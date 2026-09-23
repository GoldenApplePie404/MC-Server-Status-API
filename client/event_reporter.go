package main

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// eventsFile 事件日志文件名（放在快照目录下，与 history/ 并列）。
const eventsFile = "events.jsonl"

// eventsFilePath 返回事件日志的绝对路径（目录不存在则创建）。
func eventsFilePath(dir string) (string, error) {
	abs, err := ensureSnapshotDir(dir)
	if err != nil {
		return "", err
	}
	return filepath.Join(abs, eventsFile), nil
}

// eventReporter 是一个输出通道：把每条 Report 追加到本地 events.jsonl，
// 供「告警与事件」页可视化浏览与审计。上限由 EventMaxRecords 控制。
//
// 与终端 / Webhook 通道共存：它只负责落盘，不改变其它通道行为。
type eventReporter struct {
	mu             sync.Mutex
	path           string
	max            int
	includeSummary bool // 是否把 Type=summary 也落盘
}

// newEventReporter 创建事件落盘通道。路径放在快照目录下。
// includeSummary=false 时仅记录告警事件，summary 走其它通道（终端/Webhook）但不入 events.jsonl。
func newEventReporter(dir string, max int, includeSummary bool) *eventReporter {
	return &eventReporter{path: filepath.Join(dir, eventsFile), max: max, includeSummary: includeSummary}
}

// Name 实现 Reporter 接口。
func (e *eventReporter) Name() string { return "event" }

// Report 实现 Reporter 接口：把一条 Report 追加为 events.jsonl 的一行。
// 根据 includeSummary 决定是否跳过 summary 类型。
func (e *eventReporter) Report(r *Report) error {
	if !e.includeSummary && r.Type == ReportSummary {
		return nil // summary 由终端/Webhook 正常分发，但事件落盘跳过，避免刷屏
	}
	line, err := json.Marshal(r)
	if err != nil {
		return fmt.Errorf("序列化事件失败: %w", err)
	}

	e.mu.Lock()
	defer e.mu.Unlock()

	if err := os.MkdirAll(filepath.Dir(e.path), 0o755); err != nil {
		return fmt.Errorf("创建事件目录失败: %w", err)
	}
	f, err := os.OpenFile(e.path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		return fmt.Errorf("打开事件文件失败: %w", err)
	}
	if _, err := f.Write(append(line, '\n')); err != nil {
		f.Close()
		return fmt.Errorf("写入事件文件失败: %w", err)
	}
	if err := f.Close(); err != nil {
		return err
	}

	// 超出上限时裁剪最旧记录，防止文件无限增长（tmp+rename 原子化）。
	if e.max <= 0 {
		e.max = 200
	}
	lines, err := readLines(e.path)
	if err != nil {
		return nil // 读到失败不阻断上报
	}
	if len(lines) > e.max {
		keep := lines[len(lines)-e.max:]
		if err := atomicWriteFile(e.path, []byte(strings.Join(keep, "\n")+"\n"), 0o644); err != nil {
			return fmt.Errorf("裁剪事件文件失败: %w", err)
		}
	}
	return nil
}

// readEvents 读取事件日志，返回最多 limit 条（新->旧）。
// 解析失败的行跳过，避免单行损坏导致整体不可用。
// 同时兼容 v0 旧格式（大写字段名 At/Type/Kind/ServerKey），自动映射到新格式。
func readEvents(dir string, limit int) ([]*Report, error) {
	path := filepath.Join(dir, eventsFile)
	raw, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return []*Report{}, nil
		}
		return nil, err
	}
	rows := strings.Split(strings.TrimSpace(string(raw)), "\n")
	out := make([]*Report, 0, len(rows))
	for i := len(rows) - 1; i >= 0; i-- {
		if strings.TrimSpace(rows[i]) == "" {
			continue
		}
		line := rows[i]

		// 先尝试按新格式（小写 tag）解
		var r Report
		if json.Unmarshal([]byte(line), &r) == nil && (r.Type != "" || r.Message != "") {
			out = append(out, &r)
		} else {
			// 老格式兼容：先解到 map 再手动映射
			var m map[string]any
			if json.Unmarshal([]byte(line), &m) != nil {
				continue
			}
			r = Report{}
			r.At = anyToTime(m["at"], m["At"])
			if v, ok := m["type"].(string); ok {
				r.Type = ReportType(v)
			} else if v, ok := m["Type"].(string); ok {
				r.Type = ReportType(v)
			}
			if v, ok := m["kind"].(string); ok {
				r.Kind = v
			} else if v, ok := m["Kind"].(string); ok {
				r.Kind = v
			}
			if v, ok := m["server_key"].(string); ok {
				r.ServerKey = v
			} else if v, ok := m["ServerKey"].(string); ok {
				r.ServerKey = v
			}
			if v, ok := m["alias"].(string); ok {
				r.Alias = v
			} else if v, ok := m["Alias"].(string); ok {
				r.Alias = v
			}
			if v, ok := m["message"].(string); ok {
				r.Message = v
			} else if v, ok := m["Message"].(string); ok {
				r.Message = v
			}
			if v, ok := m["data"].(map[string]any); ok {
				r.Data = v
			} else if v, ok := m["Data"].(map[string]any); ok {
				r.Data = v
			}
			out = append(out, &r)
		}
		if limit > 0 && len(out) >= limit {
			break
		}
	}
	return out, nil
}

// anyToTime 从任意候选字段提取 time.Time。
// 接受 time.Time 或 RFC3339 字符串。nil 或解析失败返回零值。
func anyToTime(candidates ...any) time.Time {
	for _, c := range candidates {
		switch v := c.(type) {
		case time.Time:
			return v
		case string:
			if t, err := time.Parse(time.RFC3339, v); err == nil {
				return t
			}
		}
	}
	return time.Time{}
}

// clearEvents 清空事件日志（写空文件，tmp+rename 原子化）。
func clearEvents(dir string) error {
	path, err := eventsFilePath(dir)
	if err != nil {
		return err
	}
	return atomicWriteFile(path, nil, 0o644)
}