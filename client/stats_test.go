package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
	"time"
)

// 生成 n 条简单历史快照并写入 history/<key>.jsonl。
func writeFakeHistory(t *testing.T, dir, key string, n int) {
	t.Helper()
	histDir := filepath.Join(dir, "history")
	if err := os.MkdirAll(histDir, 0o755); err != nil {
		t.Fatal(err)
	}
	f, err := os.OpenFile(filepath.Join(histDir, key+".jsonl"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()

	base := time.Date(2026, 8, 31, 0, 0, 0, 0, time.UTC)
	for i := 0; i < n; i++ {
		online := i%2 == 0 // 偶数在线
		snap := &Snapshot{
			Key:     key,
			Host:    "mc.example.com",
			Port:    25565,
			Alias:   "示例服",
			Group:   "自营",
			At:      base.Add(time.Duration(i) * time.Minute).Format(time.RFC3339),
			Online:  online,
			Version: "Paper 1.20.4",
		}
		if online {
			v := i + 1
			lat := 40
			snap.PlayersOnline = &v
			snap.PlayersMax = &v
			snap.LatencyMS = &lat
		}
		b, err := json.Marshal(snap)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := f.Write(append(b, '\n')); err != nil {
			t.Fatal(err)
		}
	}
}

func TestLoadStats(t *testing.T) {
	dir := t.TempDir()
	writeFakeHistory(t, dir, "a.example.com", 6) // 偶数轮在线 -> 3 在线 / 3 离线, 在线率 0.5
	writeFakeHistory(t, dir, "b.example.com", 2) // 偶数轮在线 -> 1 在线 / 1 离线, 在线率 0.5

	stats, err := loadStats(dir, 200)
	if err != nil {
		t.Fatal(err)
	}
	if len(stats) != 2 {
		t.Fatalf("期望 2 台服务器, 得到 %d", len(stats))
	}

	var a *serverStats
	for _, st := range stats {
		if st.Key == "a.example.com" {
			a = st
		}
	}
	if a == nil {
		t.Fatal("未找到 a.example.com")
	}
	if a.Count != 6 {
		t.Errorf("Count 期望 6, 得到 %d", a.Count)
	}
	if a.OnlineCount != 3 {
		t.Errorf("OnlineCount 期望 3, 得到 %d", a.OnlineCount)
	}
	if a.OnlineRate != 0.5 {
		t.Errorf("OnlineRate 期望 0.5, 得到 %f", a.OnlineRate)
	}
	// 偶数在线时在线人数 v=i+1=1,3,5 -> 峰值 5
	if a.PeakPlayers != 5 {
		t.Errorf("PeakPlayers 期望 5, 得到 %d", a.PeakPlayers)
	}
	if len(a.Series) != 6 {
		t.Errorf("Series 长度期望 6, 得到 %d (降采样不应在小样本触发)", len(a.Series))
	}
}

func TestDownsample(t *testing.T) {
	const maxPts = 200
	// 构造超过上限的样本，验证被压缩到上限
	points := make([]seriesPoint, 0, maxPts*2)
	for i := 0; i < maxPts*2; i++ {
		points = append(points, seriesPoint{At: "t", Online: true})
	}
	out := downsample(points, maxPts)
	if len(out) != maxPts {
		t.Errorf("downsample 期望 %d 点, 得到 %d", maxPts, len(out))
	}
	// 首尾保持不变
	if out[0].At != points[0].At || out[len(out)-1].At != points[len(points)-1].At {
		t.Error("downsample 首尾点应保留")
	}

	// 少于上限时原样返回
	small := downsample([]seriesPoint{{At: "a"}, {At: "b"}}, maxPts)
	if len(small) != 2 {
		t.Errorf("小样本应原样返回, 得到 %d", len(small))
	}
}