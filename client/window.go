//go:build windows

package main

import (
	"fmt"
	"path/filepath"

	webview2 "github.com/jchv/go-webview2"
)

// webviewDataDir 是 WebView2 运行时的本地数据目录（浏览器缓存/用户数据）。
// 放在程序目录下的 webview2-data，既隔离沙箱限制的 AppData，也让应用自带数据、干净可移植。
func webviewDataDir() string {
	abs, err := filepath.Abs("webview2-data")
	if err != nil {
		return "webview2-data"
	}
	return abs
}

// runNativeGUI 在一个内嵌 WebView2 的原生窗口里加载 url，阻塞直到窗口被用户关闭。
// 供 Windows 桌面版使用；若 WebView2 初始化失败则返回错误，由上层回退到浏览器。
func runNativeGUI(title, url string) (err error) {
	// 兜住 WebView2 初始化过程中的异常（如数据目录不可写、运行时缺失），
	// 转成普通错误由上层决定回退策略，而不是让整个程序崩溃。
	defer func() {
		if r := recover(); r != nil {
			err = fmt.Errorf("原生窗口启动失败：%v", r)
		}
	}()

	w := webview2.NewWithOptions(webview2.WebViewOptions{
		Debug:    false,
		DataPath: webviewDataDir(),
		AutoFocus: true,
		WindowOptions: webview2.WindowOptions{
			Title:  title,
			Width:  1180,
			Height: 760,
			Center: true,
		},
	})
	if w == nil {
		return fmt.Errorf("WebView2 初始化失败，请确认系统已安装 WebView2 运行时（Windows 10/11 通常自带）")
	}
	defer func() {
		w.Terminate()
		w.Destroy()
	}()
	w.SetSize(1180, 760, webview2.HintNone)
	w.Navigate(url)
	w.Run()
	return nil
}