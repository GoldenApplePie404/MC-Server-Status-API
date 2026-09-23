//go:build !windows

package main

import "fmt"

// runNativeGUI 在非 Windows 平台暂不支持内嵌原生窗口，返回错误由上层回退到浏览器。
func runNativeGUI(title, url string) error {
	return fmt.Errorf("当前平台暂不支持桌面原生窗口，正在改用浏览器打开")
}