package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"time"
)

// webhookReporter 是通用 Webhook 输出通道：
// 把每个 Report 序列化为 JSON，POST 到配置的 webhook URL。
// 支持可选的 Bearer Token 鉴权头。
//
// 这是一个"平台无关"的通道：既可直接发给通用机器人(企业微信/Server酱等)，
// 也为后续飞书/钉钉等特定格式通道留了扩展接口（新增一个 Reporter 实现即可）。
type webhookReporter struct {
	url   string
	token string
	client *http.Client
}

// newWebhookReporter 创建 Webhook 通道。
// url 为空时不会启用（enabled 判断由调用方处理）。
func newWebhookReporter(url, token string) Reporter {
	return &webhookReporter{
		url:    url,
		token:  token,
		client: &http.Client{Timeout: 10 * time.Second},
	}
}

func (w *webhookReporter) Name() string { return "webhook" }

func (w *webhookReporter) Report(r *Report) error {
	if w.url == "" {
		return nil // 未配置 URL，静默跳过
	}

	payload := map[string]any{
		"type":       string(r.Type),
		"kind":       r.Kind,
		"time":       r.At.Format(time.RFC3339),
		"server_key": r.ServerKey,
		"alias":      r.Alias,
		"message":    r.Message,
	}
	// 若存在额外结构化字段则合并
	for k, v := range r.Data {
		payload[k] = v
	}

	body, err := json.Marshal(payload)
	if err != nil {
		return fmt.Errorf("序列化报告失败: %w", err)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, w.url, bytes.NewReader(body))
	if err != nil {
		return fmt.Errorf("构造 Webhook 请求失败: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", "mcmonitor")
	if w.token != "" {
		req.Header.Set("Authorization", "Bearer "+w.token)
	}

	resp, err := w.client.Do(req)
	if err != nil {
		return fmt.Errorf("Webhook 请求失败: %w", err)
	}
	defer resp.Body.Close()

	// 非 2xx 视为失败
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("Webhook 返回非成功状态码: %d", resp.StatusCode)
	}
	return nil
}

// enabledWebhook 判断该通道是否应启用（URL 已配置）。
func (w *webhookReporter) enabled() bool {
	return w.url != ""
}