//go:build windows

package main

import "syscall"

// hideConsole 在 GUI 桌面模式下隐藏控制台窗口，让双击 exe 只呈现软件界面。
// 非命令行(被重定向)或无需控制台场景下调用是无害的。
func hideConsole() {
	freeConsole := syscall.NewLazyDLL("kernel32.dll").NewProc("FreeConsole")
	_, _, _ = freeConsole.Call()
}