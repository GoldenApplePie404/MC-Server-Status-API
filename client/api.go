package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"path/filepath"
	"strings"
	"time"
)

// 与服务端批量接口的请求/响应结构对应（只声明本客户端关心的字段，多余字段忽略）。

// batchRequest 是 POST /api/ping/batch 的请求体。
type batchRequest struct {
	Servers []batchServer `json:"servers"`
}

type batchServer struct {
	Host string `json:"host"`
	Port int    `json:"port"`
}

// batchResponse 是接口最外层包裹。
// 注意：服务端错误响应里 data 是数组 []，成功响应里 data 是对象 {total,success,failed,results}。
// 为了兼容两种形态，先用 RawMessage 接，再按 success 字段分别解析。
type batchResponse struct {
	Success bool            `json:"success"`
	Code    int             `json:"code"`
	Message string          `json:"message"`
	Data    json.RawMessage `json:"data"`
}

type batchData struct {
	Total   int           `json:"total"`
	Success int           `json:"success"`
	Failed  int           `json:"failed"`
	Results []batchResult `json:"results"`
}

// batchResult 是 data.results 中单台服务器的返回。
// 字段尽量齐全，以便把服务端返回的完整信息（图标 / MOTD 富文本 / 玩家列表 /
// 核心与协议 / secure_chat 等）原样透传给前端渲染成与官网首页一致的样式。
type batchResult struct {
	Online    bool  `json:"online"`
	Host      string `json:"host"`
	Port      int    `json:"port"`
	Cached    bool   `json:"cached,omitempty"`
	LatencyMS *int   `json:"latency_ms"`
	Version   *struct {
		Name     string `json:"name"`
		Protocol *int   `json:"protocol"`
		Brand    string `json:"brand"`
	} `json:"version"`
	Players struct {
		Online *int `json:"online"`
		Max    *int `json:"max"`
		Sample []struct {
			Name string `json:"name"`
			UUID string `json:"uuid"`
		} `json:"sample"`
	} `json:"players"`
	MOTD struct {
		PlainText string `json:"plain_text"`
		HTML      string `json:"html"`
	} `json:"motd"`
	Favicon struct {
		Base64 string `json:"base64"`
		URL    string `json:"url"`
	} `json:"favicon"`
	SecureChat *struct {
		Enforces *bool `json:"enforces"`
		Previews *bool `json:"previews"`
	} `json:"secure_chat"`
	Protocol  string `json:"protocol_used"`
	SRVUsed   bool    `json:"srv_used"`
	SRVRecord *struct {
		Target string `json:"target"`
		Port   int    `json:"port"`
	} `json:"srv_record"`
	Error *struct {
		Code    int    `json:"code"`
		Message string `json:"message"`
	} `json:"error"`
}

// httpClient 复用同一个 client 以启用连接复用；超时给足（服务端批量查询本身有 10s 预算）。
var httpClient = &http.Client{Timeout: 30 * time.Second}

// fetchBatch 调用服务端批量接口, 返回 data.results（顺序与请求一致）。
// baseURL 形如 https://host/mcstatus；批量端点固定为 /api/ping/batch。
// ctx 可用于取消请求。
func fetchBatch(ctx context.Context, baseURL string, servers []batchServer) ([]batchResult, error) {
	return fetchBatchWithClient(ctx, baseURL, servers, httpClient)
}

// fetchBatchWithClient 与 fetchBatch 相同，但允许注入 http.Client 以便测试 mock。
func fetchBatchWithClient(ctx context.Context, baseURL string, servers []batchServer, client *http.Client) ([]batchResult, error) {
	body, err := json.Marshal(batchRequest{Servers: servers})
	if err != nil {
		return nil, fmt.Errorf("构造请求体失败: %w", err)
	}

	endpoint := strings.TrimRight(baseURL, "/") + "/api/ping/batch"
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("构造请求失败: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")

	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("请求 %s 失败: %w", endpoint, err)
	}
	defer resp.Body.Close()

	raw, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("读取响应失败: %w", err)
	}

	var parsed batchResponse
	if err := json.Unmarshal(raw, &parsed); err != nil {
		return nil, fmt.Errorf("解析响应失败: %w", err)
	}

	if !parsed.Success {
		// 错误响应里 data 是 [] 数组，不再尝试解析；直接返回 message。
		return nil, fmt.Errorf("服务端返回错误 code=%d: %s", parsed.Code, parsed.Message)
	}

	// 成功响应里 data 是对象 {total,success,failed,results}，二次解析。
	var data batchData
	if len(parsed.Data) > 0 {
		if err := json.Unmarshal(parsed.Data, &data); err != nil {
			return nil, fmt.Errorf("解析响应体 data 字段失败: %w", err)
		}
	}

	if len(data.Results) != len(servers) {
		return nil, fmt.Errorf("服务端返回结果数(%d) 与请求数(%d) 不一致，可能有中继问题",
			len(data.Results), len(servers))
	}
	return data.Results, nil
}

// mapResultToSnapshot 将 API 的单个 result 映射到快照结构。
// 注意：result.host/port 可能是 SRV 解析后的真实地址, 所以请求方视角的 host/port
// 从订阅 srv(Server) 取, 而画框内的真实地址/SRV 信息从 result 取。
func mapResultToSnapshot(srv *Server, r batchResult, now time.Time) *Snapshot {
	snap := newSnapshotAt(srv, now)
	snap.Online = r.Online

	if r.Online {
		snap.LatencyMS = r.LatencyMS
		if r.Version != nil {
			snap.Version = r.Version.Name
		}
		snap.PlayersOnline = r.Players.Online
		snap.PlayersMax = r.Players.Max
		snap.MOTDPlain = r.MOTD.PlainText
		snap.Protocol = r.Protocol
		snap.SRVUsed = r.SRVUsed
		if r.SRVRecord != nil {
			snap.SRVTarget = r.SRVRecord.Target
			snap.SRVPort = r.SRVRecord.Port
		}
	} else {
		if r.Error != nil {
			snap.ErrorCode = r.Error.Code
			snap.ErrorMessage = r.Error.Message
		}
	}
	return snap
}

// briefRow 生成一个用于终端展示的单行摘要。
func (s *Snapshot) briefRow() string {
	if !s.Online {
		msg := s.ErrorMessage
		if msg == "" {
			msg = "离线"
		}
		return fmt.Sprintf("  [OFFLINE] %-16s %.42s", s.Alias, msg)
	}
	online, max := "-", "-"
	if s.PlayersOnline != nil {
		online = fmt.Sprintf("%d", *s.PlayersOnline)
	}
	if s.PlayersMax != nil {
		max = fmt.Sprintf("%d", *s.PlayersMax)
	}
	lat := "-"
	if s.LatencyMS != nil {
		lat = fmt.Sprintf("%dms", *s.LatencyMS)
	}
	ver := s.Version
	if ver == "" {
		ver = s.Protocol
	}
	return fmt.Sprintf("  [ONLINE]  %-16s %-9s %s/%s  延迟 %-6s  %s", s.Alias, ver, online, max, lat, truncate(s.MOTDPlain, 30))
}

func truncate(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n]) + "…"
}

// pollOnce 执行一次批量采样：
//  1. 把订阅清单组装成批量请求体
//  2. 调用服务端 /api/ping/batch 拿到每台服务器的结果
//  3. 逐台落盘为本地快照
//  4. 在终端打印一行摘要
//
// 返回本轮每台服务器的快照切片（顺序与订阅清单一致）。
// ctx 用于取消（服务端请求期间可被 Ctrl+C 中止）。
func pollOnce(ctx context.Context, cfg *Config, subs *Subscriptions) ([]*Snapshot, error) {
	// 组装批量请求体：仅取订阅里的 host + 规范化后的 port。
	servers := make([]batchServer, 0, len(subs.Servers))
	for _, srv := range subs.Servers {
		servers = append(servers, batchServer{Host: srv.Host, Port: srv.Port})
	}

	// 调服务端接口
	results, err := fetchBatch(ctx, cfg.ServerURL, servers)
	if err != nil {
		return nil, err
	}

	// 确保快照目录存在
	dir, err := ensureSnapshotDir(cfg.SnapshotDir)
	if err != nil {
		return nil, err
	}

	now := time.Now()
	out := make([]*Snapshot, 0, len(subs.Servers))
	fmt.Println("采样结果:")
	for i, srv := range subs.Servers {
		snap := mapResultToSnapshot(srv, results[i], now)
		if err := saveSnapshot(dir, snap); err != nil {
			// 单台写盘失败不中断整轮，但打印告警
			fmt.Printf("[warn] %s 快照写入失败: %v\n", srv.Key(), err)
			continue
		}
		// 追加一条历史采样，供「数据统计监测」页聚合趋势
		if err := appendSnapshotHistory(dir, snap, cfg.HistoryMaxRecords); err != nil {
			fmt.Printf("[warn] %s 历史快照追加失败: %v\n", srv.Key(), err)
		}
		fmt.Println(snap.briefRow())
		fmt.Printf("      快照已写入 %s\n", filepath.Join(dir, sanitizeFilename(snap.Key)+".json"))
		out = append(out, snap)
	}
	return out, nil
}