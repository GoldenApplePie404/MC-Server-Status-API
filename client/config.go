package main

import (
	"encoding/json"
	"fmt"
	"os"
)

// Config 是客户端主配置。字段与 config.json 一一对应。
type Config struct {
	// 服务端 API 地址（只读数据源）。
	ServerURL string `json:"server_url"`

	// 轮询间隔（秒）。
	PollIntervalSeconds int `json:"poll_interval_seconds"`

	// 未指定端口时的默认 Minecraft 端口。
	DefaultPort int `json:"default_port"`

	// 订阅清单文件路径（可单独一个 json，也可内联）。
	SubscriptionsFile string `json:"subscriptions_file"`

	// 本地快照目录。
	SnapshotDir string `json:"snapshot_dir"`

	// ===== 规则引擎阈值（M2） =====

	// 离线判定：连续 N 轮采样均离线才算事件（防抖动误报）。
	OfflineConsecutive int `json:"offline_consecutive"`

	// 延迟过高阈值（毫秒）。
	LatencyHighMs int `json:"latency_high_ms"`

	// 人数骤降：本轮较上一轮在线人数下降比例达到该值(0~1)且上一轮人数
	// 不低于 DropMinPeople 才判定。
	DropRatio    float64 `json:"drop_ratio"`
	DropMinPeople int    `json:"drop_min_people"`

	// ===== Webhook 输出通道（M3） =====

	// 通用 Webhook 地址：每个告警/摘要事件会 POST 一份 JSON 到这里。
	// 留空则不启用该通道。
	WebhookURL string `json:"webhook_url"`

	// 可选的 Bearer Token，非空时请求头带 Authorization: Bearer <token>。
	WebhookToken string `json:"webhook_token"`

	// 同一事件两次推送的最短间隔（秒），防抖动刷屏。
	WebhookCooldownSeconds int `json:"webhook_cooldown_seconds"`

	// ===== Web 控制台（GUI） =====

	// 本地 Web 控制台监听地址与端口（GUI 模式用）。0 表示随机端口。
	UIHost string `json:"ui_host"`
	UIPort int    `json:"ui_port"`

	// 是否用桌面原生窗口（WebView2）承载 UI，而不调用外部浏览器。
	// 为 true 时 --ui 直接弹出原生软件窗口；--web 可强制回退到浏览器。
	UIWindow bool `json:"ui_window"`

	// ===== 外观（主题预设 + 深浅） =====

	// 主题预设：决定导航/内容区整体色调。可选值：
	//   "dirt"    — 草绿 + 泥土米色（默认，亮色）
	//   "end"     — 末地紫黑（深色）
	//   "nether"  — 下界红黑（深色）
	//   "cobalt"  — 钴蓝工业风（深色）
	UITheme string `json:"ui_theme"`

	// 是否跟随系统深浅模式：true 时 UITheme 作为"亮色基调"，CSS 的 @media (prefers-color-scheme: dark)
	// 会自动把导航/内容色调成深色；false 时固定用 UITheme 预设本身的深/浅。
	UIAutoDark bool `json:"ui_auto_dark"`

	// ===== 告警与事件（事件落盘 + 去重防抖） =====

	// 事件落盘总开关：关掉后 events.jsonl 不再追加，「告警与事件」页将为空。
	EventEnabled bool `json:"event_enabled"`

	// 是否把周期总结（Type=summary）也写入 events.jsonl。false 时仅告警事件落盘，
	// 避免周期性机械报告刷屏告警列表。
	EventIncludeSummary bool `json:"event_include_summary"`

	// 同一服务器同一告警类型两次推送的最短间隔（秒），reportManager 去重防抖用。
	// 与 webhook_cooldown_seconds 解耦：前者控制"告警事件级"防抖，
	// 后者（若保留）仅作为 Webhook 通道内的附加节流。
	AlertCooldownSeconds int `json:"alert_cooldown_seconds"`

	// ===== 本地数据保留（G4 统计 + 告警事件） =====

	// 每台服务器本地历史快照最多保留的采样条数，超出丢弃最旧记录（JSONL 截断）。
	HistoryMaxRecords int `json:"history_max_records"`

	// 「数据统计监测」页单台服务器趋势图最多展示的点数（降采样上限）。
	SeriesMaxPoints int `json:"series_max_points"`

	// 告警事件日志落盘的最大条数，超出丢弃最旧记录。
	EventMaxRecords int `json:"event_max_records"`
}

// defaultConfig 提供所有缺省值；未在 json 中出现的键都会被这里的默认值兜底。
func defaultConfig() *Config {
	return &Config{
		ServerURL:           "https://mcpc.goldenapplepie.xyz/mcstatus",
		PollIntervalSeconds: 60,
		DefaultPort:         25565,
		SubscriptionsFile:   "subscriptions.json",
		SnapshotDir:         "snapshots",

		OfflineConsecutive: 3,
		LatencyHighMs:      300,
		DropRatio:          0.7,
		DropMinPeople:      5,

		// Webhook 输出通道默认值
		WebhookURL:             "",
		WebhookToken:           "",
		WebhookCooldownSeconds: 10,

		// Web 控制台(GUI) 默认值
		UIHost: "127.0.0.1",
		UIPort: 0,
		UIWindow: true,

		// 外观默认值：草绿泥土亮色，不跟随系统（固定色调）
		UITheme:    "dirt",
		UIAutoDark: false,

		// 本地数据保留默认值
		HistoryMaxRecords: 500,
		SeriesMaxPoints:   200,
		EventMaxRecords:   200,

		// 告警与事件默认值
		EventEnabled:          true,
		EventIncludeSummary:   false, // 默认只记告警，summary 走终端/Webhook 但不落盘
		AlertCooldownSeconds:  30,
	}
}

// LoadConfig 读取配置文件并在其之上覆盖默认值：用户没写的键用默认值。
// 返回 (合并后的配置, 配置文件内指定的订阅清单路径)。
//
// TODO(M1): subsFile 目前来自配置; 后续若允许 CLI 参数 -config 覆盖则在此扩展。
func LoadConfig(path string) (*Config, string, error) {
	cfg := defaultConfig()

	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, "", fmt.Errorf("读取配置文件 %s 失败: %w", path, err)
	}

	// 已知字段覆盖默认值; 未知字段(json 里多写的键)自动忽略, 不报错。
	if err := json.Unmarshal(raw, cfg); err != nil {
		return nil, "", fmt.Errorf("解析配置文件 %s 失败: %w", path, err)
	}

	return cfg, cfg.SubscriptionsFile, nil
}