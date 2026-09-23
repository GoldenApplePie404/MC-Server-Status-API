package main

import (
	"bufio"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Snapshot 是一台服务器在某次采样时刻的状态快照, 落盘为单行 JSON。
type Snapshot struct {
	// 请求方视角的标识（订阅里的 host : port），与 API 返回的真实地址区分开。
	Key      string        `json:"key"`
	Host     string        `json:"host"`      // 请求方视角 host(订阅)
	Port     int           `json:"port"`      // 请求方视角 port(订阅)
	Alias    string        `json:"alias"`     // 订阅别名
	Group    string        `json:"group"`     // 订阅分组

	// 采样时刻
	At       string        `json:"at"`        // RFC3339 时间戳

	// 本次采样结果
	Online   bool          `json:"online"`
	LatencyMS *int         `json:"latency_ms,omitempty"` // 指针以区分"无延迟值"与 0
	Version  string        `json:"version,omitempty"`
	PlayersOnline *int     `json:"players_online,omitempty"`
	PlayersMax    *int     `json:"players_max,omitempty"`
	MOTDPlain string        `json:"motd_plain,omitempty"`
	Protocol string        `json:"protocol_used,omitempty"`
	SRVUsed  bool          `json:"srv_used"`
	SRVTarget string        `json:"srv_target,omitempty"` // SRV 解析后的真实主机
	SRVPort    int          `json:"srv_port,omitempty"`

	// 失败时的错误描述（online=false 时填充）
	ErrorCode    int     `json:"error_code,omitempty"`
	ErrorMessage string  `json:"error_message,omitempty"`
}

// saveSnapshotDir 确保快照目录存在并返回其绝对路径。
func ensureSnapshotDir(dir string) (string, error) {
	abs, err := filepath.Abs(dir)
	if err != nil {
		return "", fmt.Errorf("解析快照目录失败: %w", err)
	}
	if err := os.MkdirAll(abs, 0o755); err != nil {
		return "", fmt.Errorf("创建快照目录失败: %w", err)
	}
	return abs, nil
}

// saveSnapshot 将单台服务器的一次采样写到 <dir>/<key>.json。
// filename 用 key 做了安全处理（端口 + host 可读化），避免非法文件名字符。
// 写入采用 tmp+rename 原子替换，防止崩溃时留下半截 JSON。
func saveSnapshot(dir string, snap *Snapshot) error {
	// 快照文件名 = 可读化的 host+port。host 里可能有 ':'（IPv6）等, 统一替换掉。
	fname := sanitizeFilename(snap.Key) + ".json"
	path := filepath.Join(dir, fname)

	data, err := json.MarshalIndent(snap, "", "  ")
	if err != nil {
		return fmt.Errorf("序列化快照失败: %w", err)
	}
	return atomicWriteFile(path, data, 0o644)
}

// appendSnapshotHistory 将一次采样追加到 <dir>/history/<key>.jsonl（每行一条 JSON）。
// 超出 maxRecords 时截断最旧记录，防止文件无限增长。
func appendSnapshotHistory(dir string, snap *Snapshot, maxRecords int) error {
	histDir := filepath.Join(dir, "history")
	if err := os.MkdirAll(histDir, 0o755); err != nil {
		return fmt.Errorf("创建历史目录失败: %w", err)
	}
	path := filepath.Join(histDir, sanitizeFilename(snap.Key)+".jsonl")

	line, err := json.Marshal(snap)
	if err != nil {
		return fmt.Errorf("序列化历史快照失败: %w", err)
	}

	f, err := os.OpenFile(path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		return fmt.Errorf("打开历史文件失败: %w", err)
	}
	if _, err := f.Write(append(line, '\n')); err != nil {
		f.Close()
		return fmt.Errorf("写入历史文件失败: %w", err)
	}
	if err := f.Close(); err != nil {
		return err
	}

	// 压缩体积：超出上限时重建为最近 maxRecords 行（tmp+rename 原子化）
	if maxRecords <= 0 {
		maxRecords = 500 // 兜底默认，避免 0/负值导致全部丢弃
	}
	lines, err := readLines(path)
	if err != nil {
		return nil // 读到失败不阻断主流程
	}
	if len(lines) > maxRecords {
		keep := lines[len(lines)-maxRecords:]
		if err := atomicWriteFile(path, []byte(strings.Join(keep, "\n")+"\n"), 0o644); err != nil {
			return fmt.Errorf("裁剪历史文件失败: %w", err)
		}
	}
	return nil
}

// readLines 读取文件全部非空行。
func readLines(path string) ([]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	var lines []string
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 0, 64*1024), 1024*1024)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line != "" {
			lines = append(lines, line)
		}
	}
	return lines, sc.Err()
}

// sanitizeFilename 将任意 key 转为安全的文件基名：仅保留字母数字与 _ - 。
func sanitizeFilename(key string) string {
	runes := make([]rune, 0, len(key))
	for _, r := range key {
		switch {
		case r >= 'a' && r <= 'z':
			runes = append(runes, r)
		case r >= 'A' && r <= 'Z':
			runes = append(runes, r)
		case r >= '0' && r <= '9':
			runes = append(runes, r)
		case r == '.' || r == '_' || r == '-':
			runes = append(runes, r)
		default:
			runes = append(runes, '_')
		}
	}
	return string(runes)
}

// newEmptySnapshot 构造一次初始快照（online 默认 false）。
func newSnapshotAt(srv *Server, now time.Time) *Snapshot {
	return &Snapshot{
		Key:    srv.Key(),
		Host:   srv.Host,
		Port:   srv.Port,
		Alias:  srv.Alias,
		Group:  srv.Group,
		At:     now.Format(time.RFC3339),
		Online: false,
	}
}