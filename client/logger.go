package main

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// LogLevel 日志级别。数值越大越严重。
type LogLevel int

const (
	LogLevelDebug LogLevel = iota
	LogLevelInfo
	LogLevelWarn
	LogLevelError
)

func (l LogLevel) String() string {
	switch l {
	case LogLevelDebug:
		return "DEBUG"
	case LogLevelInfo:
		return "INFO"
	case LogLevelWarn:
		return "WARN"
	case LogLevelError:
		return "ERROR"
	}
	return "?"
}

// Logger 是一个轻量级带文件轮转的结构化日志器。
//
// 特性：
//   - 同时输出到文件（轮转）和 stdout（仅 INFO 及以上，避免终端刷屏）
//   - 按文件大小轮转（默认 1 MB / 文件，保留 5 个历史）
//   - 协程安全（sync.Mutex）
//   - 不依赖第三方库，纯标准库
type Logger struct {
	mu       sync.Mutex
	file     *os.File
	path     string
	maxSize  int64 // 单文件最大字节
	maxFiles int   // 保留的历史文件数
	level    LogLevel
	levelOut LogLevel // stdout 最低级别，INFO 以下不刷屏
}

// 默认配置常量。不搞可配置化——mcmonitor 不是大型服务，硬编码值够用且直观。
const (
	defaultLogMaxSize  = 1 * 1024 * 1024 // 1 MB
	defaultLogMaxFiles = 5
	defaultLogLevelOut = LogLevelInfo
)

// globalLogger 包级单例，供全链路调用。
var globalLogger *Logger

// InitLogger 初始化全局日志器，日志文件放在 logDir 下（自动创建）。
// logDir 为空或 "." 时使用当前工作目录。level 控制文件里的最低级别，日志全量落盘。
func InitLogger(logDir string, level LogLevel) error {
	if logDir == "" {
		logDir = "."
	}
	if err := os.MkdirAll(logDir, 0o755); err != nil {
		return fmt.Errorf("创建日志目录 %s 失败: %w", logDir, err)
	}
	path := filepath.Join(logDir, "mcmonitor.log")
	f, err := os.OpenFile(path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		return fmt.Errorf("打开日志文件 %s 失败: %w", path, err)
	}
	globalLogger = &Logger{
		file:     f,
		path:     path,
		maxSize:  defaultLogMaxSize,
		maxFiles: defaultLogMaxFiles,
		level:    level,
		levelOut: defaultLogLevelOut,
	}
	return nil
}

// CloseLogger 关闭全局日志器。进程退出前调用（defer 就行）。
func CloseLogger() {
	if globalLogger != nil {
		globalLogger.mu.Lock()
		defer globalLogger.mu.Unlock()
		if globalLogger.file != nil {
			_ = globalLogger.file.Close()
			globalLogger.file = nil
		}
	}
}

// L 返回全局日志器（方便 l.Debug/Info/Warn/Error 调用链）。
func L() *Logger {
	if globalLogger == nil {
		// 兜底：首次调用没 Init 过？用 stdout-only 模式，不阻塞。
		globalLogger = &Logger{level: LogLevelDebug, levelOut: LogLevelDebug}
	}
	return globalLogger
}

// Debug 写一条 DEBUG 级别日志。
func (l *Logger) Debug(format string, args ...any) { l.write(LogLevelDebug, format, args...) }

// Info 写一条 INFO 级别日志。
func (l *Logger) Info(format string, args ...any) { l.write(LogLevelInfo, format, args...) }

// Warn 写一条 WARN 级别日志。
func (l *Logger) Warn(format string, args ...any) { l.write(LogLevelWarn, format, args...) }

// Error 写一条 ERROR 级别日志。
func (l *Logger) Error(format string, args ...any) { l.write(LogLevelError, format, args...) }

// write 核心写入：格式化 → 检查轮转 → 写文件 → 条件性写 stdout。
func (l *Logger) write(level LogLevel, format string, args ...any) {
	if level < l.level {
		return
	}
	msg := fmt.Sprintf(format, args...)
	line := fmt.Sprintf("%s [%s] %s\n", time.Now().Format("2006-01-02 15:04:05.000"), level, msg)

	l.mu.Lock()
	defer l.mu.Unlock()

	// 轮转检查（仅当有文件时）
	if l.file != nil {
		if info, err := l.file.Stat(); err == nil && info.Size()+int64(len(line)) > l.maxSize {
			l.rotateLocked()
		}
		_, _ = io.WriteString(l.file, line)
	}

	// stdout 输出（仅 INFO+，避免 DEBUG 刷屏）
	if level >= l.levelOut {
		_, _ = io.WriteString(os.Stdout, line)
	}
}

// rotateLocked 执行轮转：mcmonitor.log → mcmonitor.1.log → ... → mcmonitor.(N-1).log 删除。
// 假设调用方已持 l.mu。
func (l *Logger) rotateLocked() {
	if l.file != nil {
		_ = l.file.Close()
		l.file = nil
	}
	for i := l.maxFiles - 1; i >= 1; i-- {
		src := numberedPath(l.path, i)
		dst := numberedPath(l.path, i+1)
		if _, err := os.Stat(src); err == nil {
			_ = os.Rename(src, dst)
		}
	}
	// mcmonitor.log → mcmonitor.1.log
	if _, err := os.Stat(l.path); err == nil {
		_ = os.Rename(l.path, numberedPath(l.path, 1))
	}
	f, err := os.OpenFile(l.path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		// 轮转失败降级：只写 stdout，后续调用继续尝试打开
		return
	}
	l.file = f
}

// numberedPath 把 foo/mcmonitor.log 变成 foo/mcmonitor.1.log。
func numberedPath(path string, n int) string {
	ext := filepath.Ext(path)
	base := strings.TrimSuffix(path, ext)
	return fmt.Sprintf("%s.%d%s", base, n, ext)
}
