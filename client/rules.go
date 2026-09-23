package main

import (
	"fmt"
	"time"
)

// ruleEngine 维护每台服务器最近若干轮快照，用于判定连续/趋势类规则。
// 阈值统一取自 Config（含 json 覆盖与默认值）。
type ruleEngine struct {
	cfg        *Config
	ring       map[string][]*Snapshot // serverKey -> 最近几轮快照（新到旧）
	maxHistory int
}

// newRuleEngine 创建规则引擎。
func newRuleEngine(cfg *Config) *ruleEngine {
	return &ruleEngine{
		cfg:        cfg,
		ring:       make(map[string][]*Snapshot),
		maxHistory: cfg.OfflineConsecutive,
	}
}

// feed 接收本轮所有快照，维护滑动窗口并返回本轮触发的事件（Report 形式）。
func (e *ruleEngine) feed(snaps []*Snapshot) []*Report {
	var events []*Report
	for _, s := range snaps {
		e.push(s)
		events = append(events, e.analyze(s)...)
	}
	return events
}

// push 把本轮快照加入该服务器的历史窗口（保留若干轮，给骤降对比留余量）。
func (e *ruleEngine) push(s *Snapshot) {
	cap := e.maxHistory + 3
	his := append(e.ring[s.Key], s)
	if len(his) > cap {
		his = his[len(his)-cap:]
	}
	e.ring[s.Key] = his
}

// analyze 对单台服务器当前状态做规则检测，返回命中的告警 Report。
func (e *ruleEngine) analyze(s *Snapshot) []*Report {
	var events []*Report
	his := e.ring[s.Key]
	at := time.Now()

	// 1) 离线：连续 N 轮（含本轮）都 offline
	if !s.Online && e.cfg.OfflineConsecutive > 0 && len(his) >= e.cfg.OfflineConsecutive {
		allOffline := true
		for _, h := range his[len(his)-e.cfg.OfflineConsecutive:] {
			if h.Online {
				allOffline = false
				break
			}
		}
		if allOffline {
			events = append(events, &Report{
				At: at, Type: ReportAlert, Kind: "offline",
				ServerKey: s.Key, Alias: s.Alias,
				Message: fmt.Sprintf("服务器 %s 已连续 %d 轮离线", s.Alias, e.cfg.OfflineConsecutive),
			})
		}
	}

	// 2) 延迟过高
	if s.Online && s.LatencyMS != nil && *s.LatencyMS > e.cfg.LatencyHighMs {
		events = append(events, &Report{
			At: at, Type: ReportAlert, Kind: "latency",
			ServerKey: s.Key, Alias: s.Alias,
			Message: fmt.Sprintf("服务器 %s 延迟过高: %dms (>%dms)", s.Alias, *s.LatencyMS, e.cfg.LatencyHighMs),
			Data: map[string]any{"latency_ms": *s.LatencyMS},
		})
	}

	// 3) 人数骤降：与上一轮在在线人数对比
	if s.Online && e.cfg.DropMinPeople > 0 && len(his) >= 2 {
		prev := his[len(his)-2]
		if prev.Online && prev.PlayersOnline != nil && *prev.PlayersOnline >= e.cfg.DropMinPeople {
			before := *prev.PlayersOnline
			after := 0
			if s.PlayersOnline != nil {
				after = *s.PlayersOnline
			}
			if before > 0 {
				dropped := 1.0 - float64(after)/float64(before)
				if dropped >= e.cfg.DropRatio {
					events = append(events, &Report{
						At: at, Type: ReportAlert, Kind: "player_drop",
						ServerKey: s.Key, Alias: s.Alias,
						Message: fmt.Sprintf("服务器 %s 人数骤降: %d -> %d (%.0f%%)",
							s.Alias, before, after, dropped*100),
						Data: map[string]any{"from": before, "to": after, "drop_pct": dropped},
					})
				}
			}
		}
	}

	return events
}