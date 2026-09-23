//go:build !windows

package main

// hideConsole 在非 Windows 平台为空操作。
func hideConsole() {}