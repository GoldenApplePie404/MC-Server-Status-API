package main

import "fmt"

// consoleReporter 是终端输出通道：把 Report 打印到 stdout。
// 这是最基础的通道，也是默认始终存在的"兜底通道"。
type consoleReporter struct{}

// newConsoleReporter 创建终端通道。
func newConsoleReporter() Reporter {
	return &consoleReporter{}
}

func (c *consoleReporter) Name() string { return "console" }

func (c *consoleReporter) Report(r *Report) error {
	switch r.Type {
	case ReportAlert:
		fmt.Printf("[ALERT %-11s] %s\n", r.Kind, r.Message)
	case ReportSummary:
		// 终端不重复打印摘要：采样结果的逐台明细已由 pollOnce 展示，
		// 周期摘要主要供 Webhook 等外部通道消费。
		return nil
	default:
		fmt.Printf("  [%s] %s\n", r.Type, r.Message)
	}
	return nil
}