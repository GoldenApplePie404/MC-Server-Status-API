package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// seriesPoint 是趋势图上的一个采样点。
type seriesPoint struct {
	At      string `json:"at"`       // RFC3339 时间戳
	Online  bool   `json:"online"`   // 该时刻是否在线
	Players *int   `json:"players"`  // 在线人数（离线时为 null）
	Latency *int   `json:"latency"`  // 延迟毫秒（离线时为 null）
}

// serverStats 是「数据统计监测」页对单台订阅服务器的统计摘要 + 时序数据。
type serverStats struct {
	Key         string        `json:"key"`
	Alias       string        `json:"alias"`
	Host        string        `json:"host"`
	Port        int           `json:"port"`
	Group       string        `json:"group"`
	Count       int           `json:"count"`        // 本地历史采样总数
	OnlineCount int           `json:"online_count"` // 其中在线次数
	OnlineRate  float64       `json:"online_rate"`  // 在线率 0~1
	AvgPlayers  float64       `json:"avg_players"`  // 平均在线人数（仅统计在线样本）
	PeakPlayers int           `json:"peak_players"` // 峰值在线人数
	LatestOnline bool         `json:"latest_online"`
	Series      []seriesPoint `json:"series"` // 降采样后的时序数据
}

// serverSeriesFile 枚举 history 目录下的一台服务器历史文件。
type serverSeriesFile struct {
	path string
	key  string
}

// collectSeriesFiles 列出 <snapshotDir>/history 下所有 *.jsonl 文件并归并排序。
func collectSeriesFiles(histDir string) ([]serverSeriesFile, error) {
	matches, err := filepath.Glob(filepath.Join(histDir, "*.jsonl"))
	if err != nil {
		return nil, err
	}
	files := make([]serverSeriesFile, 0, len(matches))
	for _, m := range matches {
		files = append(files, serverSeriesFile{path: m, key: strings.TrimSuffix(filepath.Base(m), ".jsonl")})
	}
	sort.Slice(files, func(i, j int) bool { return files[i].key < files[j].key })
	return files, nil
}

// readSnapshotHistory 读取某台服务器全部历史快照（按时间正序）。
func readSnapshotHistory(path string) ([]*Snapshot, error) {
	lines, err := readLines(path)
	if err != nil {
		return nil, err
	}
	out := make([]*Snapshot, 0, len(lines))
	for _, ln := range lines {
		var s Snapshot
		if json.Unmarshal([]byte(ln), &s) != nil {
			continue // 跳过损坏行
		}
		out = append(out, &s)
	}
	return out, nil
}

// downsample 把全量时序降采样到最多 maxPoints 个点（均匀抽尾段分布）。
func downsample(points []seriesPoint, maxPoints int) []seriesPoint {
	if maxPoints <= 0 {
		maxPoints = 200
	}
	if len(points) <= maxPoints {
		return points
	}
	step := float64(len(points)-1) / float64(maxPoints-1)
	out := make([]seriesPoint, 0, maxPoints)
	for i := 0; i < maxPoints; i++ {
		idx := int(float64(i)*step + 0.5)
		if idx >= len(points) {
			idx = len(points) - 1
		}
		out = append(out, points[idx])
	}
	return out
}

// statsForServer 把一台服务器的历史快照聚合成汇总 + 时序。
func statsForServer(key string, snaps []*Snapshot, maxPoints int) *serverStats {
	st := &serverStats{
		Key:   key,
		Series: make([]seriesPoint, 0, len(snaps)),
	}
	var playersSum, playersN int
	peak := 0
	for _, s := range snaps {
		if st.Alias == "" {
			st.Alias, st.Host, st.Port, st.Group = s.Alias, s.Host, s.Port, s.Group
		}
		pt := seriesPoint{At: s.At, Online: s.Online, Players: s.PlayersOnline, Latency: s.LatencyMS}
		st.Series = append(st.Series, pt)

		st.Count++
		if s.Online {
			st.OnlineCount++
			st.LatestOnline = true
			if s.PlayersOnline != nil {
				playersSum += *s.PlayersOnline
				playersN++
				if *s.PlayersOnline > peak {
					peak = *s.PlayersOnline
				}
			}
		}
	}
	if st.Count > 0 {
		st.OnlineRate = float64(st.OnlineCount) / float64(st.Count)
	}
	if playersN > 0 {
		st.AvgPlayers = float64(playersSum) / float64(playersN)
	}
	st.PeakPlayers = peak
	st.Series = downsample(st.Series, maxPoints)
	return st
}

// loadStats 汇总 <snapshotDir>/history 下所有订阅服务器的统计。
// 每台服务器返回一组汇总 + 降采样时序。maxPoints 为趋势图降采样上限。
func loadStats(dir string, maxPoints int) ([]*serverStats, error) {
	histDir := filepath.Join(dir, "history")
	if _, err := os.Stat(histDir); os.IsNotExist(err) {
		return []*serverStats{}, nil
	}
	files, err := collectSeriesFiles(histDir)
	if err != nil {
		return nil, err
	}
	out := make([]*serverStats, 0, len(files))
	for _, f := range files {
		snaps, err := readSnapshotHistory(f.path)
		if err != nil || len(snaps) == 0 {
			continue
		}
		out = append(out, statsForServer(f.key, snaps, maxPoints))
	}
	// 有数据时按在线率降序、再按服务器 key 升序稳定排序
	sort.SliceStable(out, func(i, j int) bool { return out[i].OnlineRate > out[j].OnlineRate })
	return out, nil
}