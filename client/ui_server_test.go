package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// TestWebHandlerDev 验证 Web 处理器能提供 index.html（走 embed 分支）。
func TestWebHandlerDev(t *testing.T) {
	h, err := webHandler(false) // 用 embed 分支，不依赖磁盘
	if err != nil {
		t.Fatalf("webHandler(false) 意外错误: %v", err)
	}
	srv := httptest.NewServer(h)
	defer srv.Close()
	resp, err := http.Get(srv.URL + "/index.html")
	if err != nil {
		t.Fatalf("GET /index.html 失败: %v", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		t.Fatalf("期望 200，得到 %d", resp.StatusCode)
	}
}

// TestWebHandlerEmbedIndexReferencesAssets 验证嵌入的 index.html 引用了 css/js，
// 且 css/js 静态资源均可访问（G1 视觉框架）。
func TestWebHandlerEmbedIndexReferencesAssets(t *testing.T) {
	h, err := webHandler(false)
	if err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewServer(h)
	defer srv.Close()
	resp, err := http.Get(srv.URL + "/index.html")
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(body), "assets/app.css") || !strings.Contains(string(body), "assets/app.js") {
		t.Fatalf("index.html 缺少 css/js 引用")
	}
	// 抽测静态资源可访问
	for _, p := range []string{"/assets/app.css", "/assets/app.js"} {
		r2, err := http.Get(srv.URL + p)
		if err != nil {
			t.Fatalf("GET %s 失败: %v", p, err)
		}
		r2.Body.Close()
		if r2.StatusCode != http.StatusOK {
			t.Fatalf("GET %s 期望200，得到 %d", p, r2.StatusCode)
		}
	}
}

// TestHandlePingForwards 用本地 mock 服务端验证 /api/ping 转发正确（G2）。
func TestHandlePingForwards(t *testing.T) {
	// mock 服务端
	mock := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"success":true,"code":0,"message":"ok","data":{"total":1,"success":1,"failed":0,"results":[{"online":true,"host":"mc.hypixel.net","port":25565,"latency_ms":110,"version":{"name":"1.8-1.21"},"players":{"online":5,"max":10},"motd":{"plain_text":"hi"},"protocol_used":"modern","srv_used":false}]}}`))
	}))
	defer mock.Close()

	cfg := defaultConfig()
	cfg.ServerURL = mock.URL
	api := &uiAPI{cfg: cfg}

	req := httptest.NewRequest("GET", "/api/ping?host=mc.hypixel.net", nil)
	rec := httptest.NewRecorder()
	api.handlePing(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("期望 200，得到 %d: %s", rec.Code, rec.Body.String())
	}
	var resp map[string]any
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatalf("响应非 JSON: %v", err)
	}
	if resp["ok"] != true {
		t.Fatalf("ok 应为 true: %v", resp)
	}
	assertBool(resp["result"].(map[string]any)["online"] == true, t, "result.online 应为 true")
}

func assertBool(v bool, t *testing.T, msg string) {
	t.Helper()
	if !v {
		t.Fatal(msg)
	}
}

// TestHandleMonitorPanelReadsSnapshots 验证监控面板能读取合法快照并忽略坏文件（G3）。
func TestHandleMonitorPanelReadsSnapshots(t *testing.T) {
	dir := t.TempDir()
	srv := &Server{Host: "mc.hypixel.net", Port: 25565, Alias: "Hypixel"}
	snap := newSnapshotAt(srv, timeNow())
	snap.Online = true
	os.WriteFile(filepath.Join(dir, "a.json"), mustJSON(t, snap), 0644)
	os.WriteFile(filepath.Join(dir, "bad.json"), []byte("not json"), 0644)

	cfg := defaultConfig()
	cfg.SnapshotDir = dir
	api := &uiAPI{cfg: cfg}

	req := httptest.NewRequest("GET", "/api/monitor-panel", nil)
	rec := httptest.NewRecorder()
	api.handleMonitorPanel(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("期望200，得到 %d", rec.Code)
	}
	var resp struct {
		OK        bool       `json:"ok"`
		Snapshots []Snapshot `json:"snapshots"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}
	if !resp.OK || len(resp.Snapshots) != 1 {
		t.Fatalf("期望 1 个合法快照，得到 %v", resp)
	}
}

// TestHandleConfigGet 验证配置接口不回传 webhook_token（G5）。
func TestHandleConfigGet(t *testing.T) {
	dir := t.TempDir()
	subsPath := filepath.Join(dir, "subs.json")
	os.WriteFile(subsPath, []byte(`{"servers":[{"host":"mc.hypixel.net","port":25565}]}`), 0644)
	cfg := defaultConfig()
	cfg.SubscriptionsFile = subsPath
	api := &uiAPI{cfg: cfg}

	req := httptest.NewRequest("GET", "/api/config", nil)
	rec := httptest.NewRecorder()
	api.handleConfigGet(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("期望200，得到 %d", rec.Code)
	}
	var resp struct {
		Config Config `json:"config"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}
	if resp.Config.WebhookToken != "" {
		t.Fatalf("config 不应回传 webhook_token")
	}
}

// TestWriteJSONFile 验证 writeJSONFile 能正确落盘。
func TestWriteJSONFile(t *testing.T) {
	dir := t.TempDir()
	p := filepath.Join(dir, "c.json")
	if err := writeJSONFile(p, map[string]any{"a": 1}); err != nil {
		t.Fatal(err)
	}
	raw, _ := os.ReadFile(p)
	if string(raw) == "" {
		t.Fatal("文件为空")
	}
}

// TestHandleConfigPut 验证 PUT 能写回 config（保留内存中的真实 Token）与订阅清单。
func TestHandleConfigPut(t *testing.T) {
	dir := t.TempDir()
	cfgPath := filepath.Join(dir, "config.json")
	subsPath := filepath.Join(dir, "subs.json")
	os.WriteFile(cfgPath, []byte(`{}`), 0644)
	os.WriteFile(subsPath, []byte(`{"servers":[]}`), 0644)

	cfg := defaultConfig()
	cfg.SubscriptionsFile = subsPath
	cfg.WebhookToken = "secret-token"
	a := &uiAPI{cfg: cfg, cfgPath: cfgPath}

	body := `{"config":{"server_url":"https://example.com","poll_interval_seconds":30},
		"subscriptions":[{"host":"mc.hypixel.net","port":25565,"alias":"Hypixel"}]}`
	req := httptest.NewRequest(http.MethodPut, "/api/config", strings.NewReader(body))
	rec := httptest.NewRecorder()
	a.handleConfigPut(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("期望200，得到 %d: %s", rec.Code, rec.Body.String())
	}

	// config 文件写回，且 Token 保留内存中的真实值
	rawCfg, _ := os.ReadFile(cfgPath)
	if !strings.Contains(string(rawCfg), "secret-token") {
		t.Fatalf("config 写回应保留 webhook_token")
	}
	rawSubs, _ := os.ReadFile(subsPath)
	if !strings.Contains(string(rawSubs), "mc.hypixel.net") {
		t.Fatalf("订阅清单写回失败")
	}
}

// ---- helpers（G4/G5 测试复用） ----

// mustJSON 序列化 v 为 JSON 字节；失败即测试失败。
func mustJSON(t *testing.T, v any) []byte {
	t.Helper()
	b, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

// tmpSnapshotFile 写入一个快照 JSON 到指定临时目录，返回文件路径。
func tmpSnapshotFile(t *testing.T, dir string, name string, snap *Snapshot) string {
	t.Helper()
	p := filepath.Join(dir, name)
	if err := os.WriteFile(p, mustJSON(t, snap), 0o644); err != nil {
		t.Fatal(err)
	}
	return p
}

// timeNow 返回固定的测试时间点。
func timeNow() time.Time {
	return time.Date(2026, 8, 31, 12, 0, 0, 0, time.UTC)
}