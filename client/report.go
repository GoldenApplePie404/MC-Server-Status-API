package main

import (
	"fmt"
	"time"
)

// ReportType 标识报告的类别：是告警事件还是周期摘要。
type ReportType string

const (
	// ReportAlert 一条规则命中的告警事件。
	ReportAlert ReportType = "alert"
	// ReportSummary 一轮采样后的状态摘要（周期性机械报告）。
	ReportSummary ReportType = "summary"
)

// Report 是报告接口层的统一数据模型。
// 终端 / Webhook / 飞书等所有输出通道都消费同一种 Report，互不感知对方。
// json tag 与前端 /api/events 的消费者对齐（小驼峰 + server_key）。
type Report struct {
	At        time.Time  `json:"at"`                  // 发生/采样时间
	Type      ReportType `json:"type"`                // alert 或 summary
	Kind      string     `json:"kind"`                // offline / latency / player_drop / summary
	ServerKey string     `json:"server_key"`          // 请求方视角 host:port（全局摘要该字段为空）
	Alias     string     `json:"alias"`               // 服务器别名
	Message   string     `json:"message"`             // 人类可读文本

	// Data 携带结构化字段（在线数、延迟等），供方富输出通道（如 Webhook JSON）使用。
	Data map[string]any `json:"data,omitempty"`
}

// Reporter 是输出通道接口：任何能消费一条 Report 的东西都是一个 Reporter。
type Reporter interface {
	Name() string // 通道名（调试/日志用）
	Report(r *Report) error
}

// reportManager 管理一个或多个 Reporter，并负责事件级去重防抖。
type reportManager struct {
	reporters []Reporter

	// 事件去重防抖：以 eventKey = kind|serverKey 记住最近一次发送时间，
	// 在 cooldown 内重复触发同一事件则跳过，防止抖动刷屏。
	dedup    map[string]time.Time
	cooldown time.Duration
}

// newReportManager 创建报告管理器。
func newReportManager(cooldown time.Duration) *reportManager {
	return &reportManager{
		reporters: make([]Reporter, 0, 2),
		dedup:     make(map[string]time.Time),
		cooldown:  cooldown,
	}
}

// add 注册一个输出通道。
func (m *reportManager) add(r Reporter) {
	m.reporters = append(m.reporters, r)
}

// enabled 返回是否有已注册的输出通道。
func (m *reportManager) enabled() bool {
	return len(m.reporters) > 0
}

// dispatch 把一条 Report 分发给所有通道。
//
// 对告警事件应用去重防抖：同 key 在冷却期内再次触发直接忽略；
// 周期摘要不参与去重，每次照常输出。
func (m *reportManager) dispatch(r *Report) error {
	if r.Type == ReportAlert {
		if !m.dedupAllow(r) {
			return nil // 冷却期内重复事件，静默跳过
		}
	}

	var firstErr error
	for _, rep := range m.reporters {
		if err := rep.Report(r); err != nil {
			if firstErr == nil {
				firstErr = fmt.Errorf("通道 %s 报告失败: %w", rep.Name(), err)
			}
		}
	}
	return firstErr
}

// dedupAllow 判断告警事件是否允许发送（通过去重防抖检查），
// 通过则记录本次发送时间以便后续判定。
func (m *reportManager) dedupAllow(r *Report) bool {
	if m.cooldown <= 0 {
		return true // 未启用冷却，恒放行
	}
	key := r.Kind + "|" + r.ServerKey
	now := time.Now()
	if last, ok := m.dedup[key]; ok {
		if now.Sub(last) < m.cooldown {
			return false // 冷却中
		}
	}
	m.dedup[key] = now
	return true
}