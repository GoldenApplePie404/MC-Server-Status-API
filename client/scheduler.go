package main

import (
	"context"
	"fmt"
	"os"
	"os/signal"
	"syscall"
	"time"
)

// buildReportManager 依据配置创建报告管理器并注册输出通道：
//   - 终端通道始终注册（兜底）
//   - 若配置了 webhook_url 则额外注册 Webhook 通道
//   - 事件落盘通道：config.event_enabled=true 时注册，config.event_include_summary 决定是否把 summary 也写入 events.jsonl
func buildReportManager(cfg *Config) *reportManager {
	// 报告器级告警去重冷却（作用于所有告警事件，不区分具体通道）
	cooldown := time.Duration(cfg.AlertCooldownSeconds) * time.Second
	if cooldown <= 0 {
		cooldown = 30 * time.Second // 兜底默认
	}
	mgr := newReportManager(cooldown)

	// 终端通道：始终有
	mgr.add(newConsoleReporter())

	// Webhook 通道：仅当 URL 已配置
	if cfg.WebhookURL != "" {
		mgr.add(newWebhookReporter(cfg.WebhookURL, cfg.WebhookToken))
	}

	// 事件落盘通道：由 event_enabled 控制；include_summary 决定 summary 是否落盘
	if cfg.EventEnabled {
		mgr.add(newEventReporter(cfg.SnapshotDir, cfg.EventMaxRecords, cfg.EventIncludeSummary))
	}
	return mgr
}

// pollLoop 周期性地执行采样，直到收到 Ctrl+C 或 context 被取消。
//
// 每次采样：
//   - 调用服务端批量接口拿到一轮快照
//   - 把本轮结果追加到 ring，供规则引擎判断
//   - 规则引擎产出告警，经报告管理器（终端/Webhook）分发
//   - 本轮汇总摘要也经管理器分发
//   - 等待下一个 tick 或退出信号
func pollLoop(ctx context.Context, cfg *Config, subs *Subscriptions, mgr *reportManager) error {
	interval := time.Duration(cfg.PollIntervalSeconds) * time.Second
	if interval <= 0 {
		L().Warn("poll_interval_seconds 未配置或为 0，使用默认 60s")
		interval = 60 * time.Second
	}

	// 规则引擎维护的滑动窗口，保留最近若干轮快照
	engine := newRuleEngine(cfg)

	L().Info("开始轮询，每 %s 采样一轮。按 Ctrl+C 退出。", interval)

	// timer 而不是 ticker：前一轮采样耗时未必固定，
	// 用"完成后再等一个完整间隔"避免采样耗时累积导致漂移。
	t := time.NewTimer(interval)
	defer t.Stop()

	for {
		// 执行一轮采样（带 recover 兜底，单轮 panic 不退出循环）
		snapshots, err := pollOnceSafe(ctx, cfg, subs)
		if err != nil {
			// 单轮失败（如服务端临时不可达）不退出，记错误后继续下一轮
			L().Warn("本轮采样失败: %v", err)
		} else {
			// 规则检测 → 分发告警事件（终端 + Webhook）
			events := engine.feed(snapshots)
			for _, ev := range events {
				if derr := mgr.dispatch(ev); derr != nil {
					L().Warn("分发告警失败: %v", derr)
				}
			}

			// 本轮周期摘要（机械报告）：整份发给各通道
			mgr.dispatch(buildSummary(snapshots))
		}

		// 等待下一个周期或退出信号
		select {
		case <-ctx.Done():
			L().Info("轮询循环收到退出信号，已停止。")
			return nil
		case <-t.C:
			t.Reset(interval)
		}
	}
}

// buildSummary 把本轮快照汇总成一条周期摘要 Report。
func buildSummary(snaps []*Snapshot) *Report {
	var online, offline int
	var onlineCount, maxCount int
	for _, s := range snaps {
		if s.Online {
			online++
			if s.PlayersOnline != nil {
				onlineCount += *s.PlayersOnline
			}
			if s.PlayersMax != nil {
				maxCount += *s.PlayersMax
			}
		} else {
			offline++
		}
	}

	msg := fmt.Sprintf("本轮汇总: %d 台在线 / %d 台离线, 总人数 %d/%d",
		online, offline, onlineCount, maxCount)
	return &Report{
		At: time.Now(), Type: ReportSummary, Kind: "summary",
		Message: msg,
		Data: map[string]any{
			"online":  online,
			"offline": offline,
			"players": onlineCount,
			"max":     maxCount,
		},
	}
}

// waitForSignal 返回一个在收到 Ctrl+C(C) 时被取消的 context。
func waitForSignal() context.Context {
	ctx, cancel := context.WithCancel(context.Background())
	ch := make(chan os.Signal, 1)
	signal.Notify(ch, os.Interrupt, syscall.SIGTERM)
	go func() {
		<-ch
		cancel()
	}()
	return ctx
}

// runMonitor 主监控入口：加载配置与订阅，构建报告通道，进入轮询循环。
func runMonitor(configPath string) error {
	cfg, subsPath, err := LoadConfig(configPath)
	if err != nil {
		return err
	}
	subs, err := LoadSubscriptions(subsPath, cfg.DefaultPort)
	if err != nil {
		return err
	}

	L().Info("mcmonitor %s 启动 (CLI 监控模式)", version)
	L().Info("数据源: %s", cfg.ServerURL)
	if cfg.WebhookURL != "" {
		L().Info("Webhook 通道已启用: %s", cfg.WebhookURL)
	} else {
		L().Info("Webhook 通道未配置（config.json 的 webhook_url 留空），仅终端输出。")
	}

	mgr := buildReportManager(cfg)
	return pollLoop(waitForSignal(), cfg, subs, mgr)
}

// pollOnceSafe 是 pollOnce 的 panic-safe 包装。
// 内部 recover 任何 panic（包括从 pollOnce 传播上来的），打 Error 日志后作为 error 返回，
// 让外层轮询循环可以继续下一轮。
func pollOnceSafe(ctx context.Context, cfg *Config, subs *Subscriptions) (snapshots []*Snapshot, err error) {
	defer func() {
		if r := recover(); r != nil {
			L().Error("pollOnce panic 被捕获: %v", r)
			err = fmt.Errorf("pollOnce panic: %v", r)
		}
	}()
	return pollOnce(ctx, cfg, subs)
}

// startUIPolling 在 GUI 后台启动一个周期采样协程，为「订阅监控」面板与
// 「数据统计监测」页提供实时数据。
//
// 采样即复用 pollOnce（落盘快照 + 追加历史到 history/*.jsonl），
// 但不做规则检测 / Webhook 分发——避免一打开 GUI 就频繁推送告警，
// 机械报告请用 --run 命令行模式。ctx 取消即停止。
//
// 与旧版不同：不把 cfg/subs 在启动时固定，而是「每次轮询前重新从磁盘
// 加载 config + 订阅」，因此「配置」页保存轮询间隔、新增/删除服务器后
// 即时生效，无需重启 GUI。每轮循环用 recover 兜底，防止单轮异常
// （如配置文件瞬时损坏）令整个协程退出。
func startUIPolling(ctx context.Context, cfgPath string) {
	// 配置/订阅读取失败时的退避等待，避免热循环空转。
	const reloadFailWait = 15 * time.Second

	// 配置指纹：规则阈值/通知通道/事件落盘相关字段变化时重建引擎与报告管理器，
	// 使「配置」页保存后即时生效；未变化则复用，保留引擎滑动窗口与报告器冷却状态。
	type cfgSign struct {
		OfflineConsecutive   int
		LatencyHighMs        int
		DropMinPeople        int
		DropRatio            float64
		WebhookURL           string
		WebhookToken         string
		WebhookCooldownSec   int
		AlertCooldownSec     int
		EventEnabled         bool
		EventIncludeSummary  bool
		EventMaxRecords      int
	}

	go func() {
		// 整个 goroutine 还有一层兜底 recover —— 极端情况下（如 runtime 错误未被内层捕获）
		// 至少打一条日志让运维知道 GUI 采样协程挂了，进程本身还活着。
		defer func() {
			if r := recover(); r != nil {
				L().Error("后台采样协程致命 panic（整协程退出）: %v — 进程仍在，但数据统计/监控面板将停止更新", r)
			}
		}()

		var engine *ruleEngine
		var mgr *reportManager
		var last cfgSign

		for {
			// 每轮独立 recover：单轮 panic（如 pollOnce / LoadConfig 内）只跳过这一轮，
			// 不影响后续轮询。退避 15s 等下一轮而不是立刻 continue，避免配置坏掉时空转。
			func() {
				defer func() {
					if r := recover(); r != nil {
						L().Error("后台采样-单轮 panic: %v", r)
					}
				}()

				cfg, subsPath, err := LoadConfig(cfgPath)
				if err != nil {
					L().Warn("后台采样-读取配置失败: %v", err)
					return
				}
				subs, err := LoadSubscriptions(subsPath, cfg.DefaultPort)
				if err != nil {
					L().Warn("后台采样-读取订阅失败: %v", err)
					return
				}

				// 首次或配置变化时重建规则引擎 + 报告管理器（含终端/Webhook/事件落盘通道）。
				sign := cfgSign{
					cfg.OfflineConsecutive, cfg.LatencyHighMs,
					cfg.DropMinPeople, cfg.DropRatio,
					cfg.WebhookURL, cfg.WebhookToken, cfg.WebhookCooldownSeconds,
					cfg.AlertCooldownSeconds, cfg.EventEnabled, cfg.EventIncludeSummary, cfg.EventMaxRecords,
				}
				if engine == nil || sign != last {
					engine = newRuleEngine(cfg)
					mgr = buildReportManager(cfg)
					last = sign
				}

				if len(subs.Servers) == 0 {
					return // 暂无订阅，跳采样
				}

				// 采一轮（落盘快照 + 追加历史）。失败不退出，记告警后等下一轮。
				snapshots, perr := pollOnceSafe(ctx, cfg, subs)
				if perr != nil {
					L().Warn("后台采样失败: %v", perr)
				} else {
					// 规则检测 → 分发告警（终端 / Webhook，随配置）。与 --run 同一套引擎与方法。
					for _, ev := range engine.feed(snapshots) {
						if derr := mgr.dispatch(ev); derr != nil {
							L().Warn("分发告警失败: %v", derr)
						}
					}
					// 本轮周期总结。
					if derr := mgr.dispatch(buildSummary(snapshots)); derr != nil {
						L().Warn("分发总结失败: %v", derr)
					}
				}
			}()

			// 每次按最新配置取轮询间隔。
			// 空订阅或配置读取失败时退避用 reloadFailWait 避免空转。
			interval := reloadFailWait
			if cfg, _, err := LoadConfig(cfgPath); err == nil {
				if i := time.Duration(cfg.PollIntervalSeconds) * time.Second; i > 0 {
					interval = i
				}
			}
			if !sleepCtx(ctx, interval) {
				L().Info("后台采样协程收到退出信号，已停止。")
				return
			}
		}
	}()
}

// sleepCtx 等待 d 时长，或 ctx 被取消时提前返回 false。
// 若提前返回，调用方应立即结束循环退出协程。
func sleepCtx(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}