package main

import (
	"encoding/json"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
)

// handleState 返回客户端当前运行状态：配置 + 订阅清单（脱敏：不含 webhook_token）。
func (a *uiAPI) handleState(w http.ResponseWriter, r *http.Request) {
	subsPath := a.cfg.SubscriptionsFile
	subs, err := LoadSubscriptions(subsPath, a.cfg.DefaultPort)
	state := map[string]any{
		"ok": true,
	}
	if err != nil {
		state["subscriptions_error"] = err.Error()
	} else {
		state["subscriptions"] = subs.Servers
	}
	// 不把 webhook_token 回传到前端
	cfgSafe := *a.cfg
	cfgSafe.WebhookToken = ""
	state["config"] = cfgSafe
	writeJSON(w, http.StatusOK, state)
}

// serveAPI 在 /api/ping 与 /api/ping/batch 两个路由下分流。
// Go 1.22+ 的 ServeMux 支持精确 + 前缀匹配，这里显式分流以保证子路径优先。
func (a *uiAPI) serveAPI(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path == "/api/ping/batch" {
		a.handleBatch(w, r)
		return
	}
	a.handlePing(w, r)
}

// handleBatch 把前端的批量请求转发到服务端 /api/ping/batch。
// 请求体格式与服务端一致: {"servers":[{"host":"..","port":25565}]}。
func (a *uiAPI) handleBatch(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]any{"ok": false, "error": "需 POST"})
		return
	}
	var req struct {
		Servers []batchServer `json:"servers"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "请求体解析失败"})
		return
	}
	if len(req.Servers) == 0 || len(req.Servers) > 20 {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "servers 数量需在 1-20"})
		return
	}
	// 前端可能发来 port:0（没有显式冒号），统一用默认端口兜底。
	for i := range req.Servers {
		if req.Servers[i].Port <= 0 {
			req.Servers[i].Port = a.cfg.DefaultPort
		}
	}
	results, err := fetchBatch(r.Context(), a.cfg.ServerURL, req.Servers)
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "results": results})
}

// handlePing 单台查询：支持 GET /api/ping?host=&port= 或 POST body。
func (a *uiAPI) handlePing(w http.ResponseWriter, r *http.Request) {
	var host string
	var port int
	if r.Method == http.MethodGet {
		q := r.URL.Query()
		host = q.Get("host")
		if host == "" {
			writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "缺少 host"})
			return
		}
		if p := q.Get("port"); p != "" {
			if v, err := strconv.Atoi(p); err == nil {
				port = v
			}
		}
	} else {
		var body struct {
			Host string `json:"host"`
			Port int    `json:"port"`
		}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "请求体解析失败"})
			return
		}
		host, port = body.Host, body.Port
	}
	if host == "" {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "缺少 host"})
		return
	}
	if port <= 0 {
		port = a.cfg.DefaultPort
	}
	results, err := fetchBatch(r.Context(), a.cfg.ServerURL, []batchServer{{Host: host, Port: port}})
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "result": results[0]})
}

// writeJSON 写 JSON 响应。
func writeJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

// handleMonitorPanel 返回订阅的服务器最近快照，供前端渲染实时面板。
// 逐份读取 snapshots/<key>.json；坏文件跳过。
func (a *uiAPI) handleMonitorPanel(w http.ResponseWriter, r *http.Request) {
	abs, err := ensureSnapshotDir(a.cfg.SnapshotDir)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	files, err := filepath.Glob(filepath.Join(abs, "*.json"))
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	snaps := make([]*Snapshot, 0, len(files))
	for _, f := range files {
		raw, err := os.ReadFile(f)
		if err != nil {
			continue
		}
		var s Snapshot
		if json.Unmarshal(raw, &s) != nil {
			continue
		}
		snaps = append(snaps, &s)
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "snapshots": snaps})
}

// handleStats 返回「数据统计监测」数据：逐台订阅服务器的汇总指标 + 降采样时序。
// 数据来自 <snapshotDir>/history/*.jsonl。
func (a *uiAPI) handleStats(w http.ResponseWriter, r *http.Request) {
	abs, err := ensureSnapshotDir(a.cfg.SnapshotDir)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	stats, err := loadStats(abs, a.cfg.SeriesMaxPoints)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": "统计失败: " + err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "servers": stats})
}

// handleEvents 提供「告警与事件」页数据。
//   - GET:    返回最近 limit 条事件（新->旧）
//   - DELETE: 清空事件日志
func (a *uiAPI) handleEvents(w http.ResponseWriter, r *http.Request) {
	switch r.Method {
	case http.MethodGet:
		abs, err := ensureSnapshotDir(a.cfg.SnapshotDir)
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
			return
		}
		events, err := readEvents(abs, a.cfg.EventMaxRecords)
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": "读取事件失败: " + err.Error()})
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"ok": true, "events": events})
	case http.MethodDelete:
		if err := clearEvents(a.cfg.SnapshotDir); err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": "清空事件失败: " + err.Error()})
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"ok": true})
	default:
		writeJSON(w, http.StatusMethodNotAllowed, map[string]any{"ok": false, "error": "需 GET 或 DELETE"})
	}
}

// handleConfigGet 返回当前 config（脱敏 webhook_token）、订阅清单及文件路径。
func (a *uiAPI) handleConfigGet(w http.ResponseWriter, r *http.Request) {
	cfgSafe := *a.cfg
	cfgSafe.WebhookToken = ""
	subs, _ := LoadSubscriptions(a.cfg.SubscriptionsFile, a.cfg.DefaultPort)
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":                true,
		"config":            cfgSafe,
		"subscriptions":     subs,
		"subscriptions_path": a.cfg.SubscriptionsFile,
	})
}

// handleConfigPut 接收前端回传的配置 + 订阅，校验后写回本地 json。
func (a *uiAPI) handleConfigPut(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPut {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]any{"ok": false, "error": "需 PUT"})
		return
	}
	var payload struct {
		Config        *Config   `json:"config"`
		Subscriptions []*Server `json:"subscriptions"`
	}
	if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "请求体解析失败"})
		return
	}
	if payload.Config == nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "缺少 config"})
		return
	}
	// 校验：不覆盖 WebhookToken（前端不回传），保留原值
	cfg := payload.Config
	cfg.WebhookToken = a.cfg.WebhookToken

	// 写回配置文件
	if err := writeJSONFile(a.cfgPath, cfg); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": "config 写回失败: " + err.Error()})
		return
	}
	// 写回订阅清单
	if payload.Subscriptions != nil {
		if err := writeJSONFile(a.cfg.SubscriptionsFile, map[string]any{"servers": payload.Subscriptions}); err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": "订阅写回失败: " + err.Error()})
			return
		}
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// writeJSONFile 以 0644 写入 JSON 文件（格式化 + 换行）。
func writeJSONFile(path string, v any) error {
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, append(data, '\n'), 0o644)
}