package main

import (
	"errors"
	"fmt"
	"os"
	"strings"
)

const version = "0.5.0 (GUI 桌面)"

// usage 是帮助文案, 展示 --help 时输出。
func usage() {
	fmt.Print(`mcmonitor — Minecraft 服务器监控订阅客户端

用法:
  mcmonitor [--config <路径>] [--run|--ui] [选项]

参数:
  --config   配置文件路径 (默认 config.json)
  --run      强制命令行采样/监控模式 (默认已改为 GUI, 此参数用于脚本调用)
  --nopause  结束后不等待按键直接退出 (用于命令行模式/脚本)
  --ui       显式启动 GUI (默认行为, 兼容直接省略)
  --desktop  用桌面原生窗口(WebView2)承载 UI, 不调外部浏览器 (可覆盖 ui_window 配置)
  --web      用外部浏览器打开 UI (可覆盖 ui_window 配置)
  --dev      前端读本地 web/ 目录 (开发热改, 需配合 --ui)
  --no-browser  启动后不自动打开浏览器/窗口
  --port     指定控制台端口 (0 表示随机, 默认取 config.ui_port)
  --version  打印版本号后退出
  --help     显示本帮助

说明:
  默认(双击 exe 或无参数运行)直接打开图形客户端界面:
  采用内嵌 WebView2 原生窗口承载本地控制台(Web UI),
  (可选 --web 回退到浏览器), 含可视化搜索/订阅监控/统计/配置。
  --run 则为命令行模式: 读取订阅清单调用服务端批量接口采样,
  把每台服务器在线/延迟/人数写入本地快照目录, 并以 Webhook 机械报告。

示例:
  mcmonitor                       双击即可打开桌面客户端
  mcmonitor --run                 命令行采样监控 (脚本/定时用)
  mcmonitor --ui --web            用外部浏览器打开控制台
  mcmonitor --ui --dev --port 9099  开发模式(前端热改)并固定 9099 端口
`)
}

/*
 * CLI 参数说明：
 *   --config <路径>   或 --config=<路径>
 *   --run            强制命令行采样/监控模式(仅默认模式被改为 GUI 后用于脚本)
 *   --nopause         结束后不等待按键
 *   --version / --help
 */
func parseArgs(args []string) (configPath string, nopause, showHelp, showVersion, ui, runMode, dev, noBrowser, forceDesktop, forceWeb bool, port int, err error) {
	configPath = defaultConfigPath()
	for i := 0; i < len(args); i++ {
		a := args[i]
		switch {
		case a == "--help" || a == "-h":
			showHelp = true
		case a == "--version":
			showVersion = true
		case a == "--nopause":
			nopause = true
		case a == "--run":
			runMode = true
		case a == "--ui":
			ui = true
		case a == "--desktop":
			forceDesktop = true
		case a == "--web":
			forceWeb = true
		case a == "--dev":
			dev = true
		case a == "--no-browser":
			noBrowser = true
		case a == "--port":
			if i+1 >= len(args) {
				return "", false, false, false, false, false, false, false, false, false, 0, errors.New("--port 需要一个端口号")
			}
			i++
			port = parseIntOrZero(args[i])
		case a == "--config":
			if i+1 >= len(args) {
				return "", false, false, false, false, false, false, false, false, false, 0, errors.New("--config 需要一个参数值")
			}
			i++
			configPath = args[i]
		case strings.HasPrefix(a, "--config="):
			configPath = strings.TrimPrefix(a, "--config=")
		default:
			return "", false, false, false, false, false, false, false, false, false, 0, fmt.Errorf("未知参数: %s", a)
		}
	}
	return configPath, nopause, showHelp, showVersion, ui, runMode, dev, noBrowser, forceDesktop, forceWeb, port, nil
}

// parseIntOrZero 解析整数，失败返回 0。
func parseIntOrZero(s string) int {
	n := 0
	for _, r := range s {
		if r < '0' || r > '9' {
			return 0
		}
		n = n*10 + int(r-'0')
	}
	return n
}

func main() {
	configPath, nopause, showHelp, showVersion, ui, runMode, dev, noBrowser, forceDesktop, forceWeb, port, err := parseArgs(os.Args[1:])
	if err != nil {
		fmt.Fprintln(os.Stderr, "[error]", err)
		usage()
		os.Exit(2)
	}

	if showHelp {
		usage()
		if !nopause {
			pauseIfInteractive()
		}
		os.Exit(0)
	}
	if showVersion {
		fmt.Println(version)
		os.Exit(0)
	}

	// 默认即 GUI（便于双击 exe 直接打开客户端界面）；
	// 显式 --run 才走命令行采样/监控模式；--ui 为历史兼容写法。
	guiRequested := ui || !runMode
	if guiRequested {
		cfg, _, lerr := LoadConfig(configPath)
		if lerr != nil {
			fmt.Fprintln(os.Stderr, "[error]", lerr)
			os.Exit(1)
		}
		// 初始化日志器（日志放在快照目录下的 log/）。失败降级为仅 stdout，不阻断启动。
		if ierr := InitLogger(cfg.SnapshotDir+"/log", LogLevelInfo); ierr != nil {
			fmt.Fprintln(os.Stderr, "[warn] 日志初始化失败，降级为仅 stdout:", ierr)
		} else {
			defer CloseLogger()
		}
		L().Info("mcmonitor %s 启动 (GUI 模式), 数据源: %s", version, cfg.ServerURL)
		// 默认按 config.ui_window 决定是否用桌面原生窗口；
		// --desktop / --web 命令行覆盖该配置。
		useWindow := cfg.UIWindow
		if forceDesktop {
			useWindow = true
		}
		if forceWeb {
			useWindow = false
		}
		// 桌面原生窗口模式下隐藏控制台黑窗，让双击 exe 呈现纯软件界面。
		if useWindow && !dev && !noBrowser {
			hideConsole()
		}
		if err := runWebUI(cfg, configPath, dev, noBrowser, useWindow, port); err != nil {
			L().Error("Web UI 启动失败: %v", err)
			fmt.Fprintln(os.Stderr, "[error]", err)
			os.Exit(1)
		}
		return
	}

	// CLI 监控模式也初始化日志器。
	cfg, _, lerr := LoadConfig(configPath)
	if lerr != nil {
		fmt.Fprintln(os.Stderr, "[error]", lerr)
		os.Exit(1)
	}
	if ierr := InitLogger(cfg.SnapshotDir+"/log", LogLevelInfo); ierr != nil {
		fmt.Fprintln(os.Stderr, "[warn] 日志初始化失败:", ierr)
	} else {
		defer CloseLogger()
	}
	L().Info("mcmonitor %s 启动 (CLI 监控模式), 数据源: %s", version, cfg.ServerURL)

	var code int
	if err := run(configPath); err != nil {
		L().Error("运行失败: %v", err)
		fmt.Fprintln(os.Stderr, "[error]", err)
		code = 1
	}

	if !nopause {
		pauseIfInteractive()
	}
	os.Exit(code)
}

// run 承载实际业务逻辑, 返回错误即表示失败。
// M2 起进入持续轮询 + 规则检测的监控模式，直到 Ctrl+C。
func run(configPath string) error {
	return runMonitor(configPath)
}

// pauseIfInteractive 判断是否处于交互式控制台（双击 exe 打开时成立），
// 若是则在退出前等待一个回车，避免窗口一闪而过。
func pauseIfInteractive() {
	if !stdinIsCharDevice() {
		return
	}
	fmt.Print("\n按回车退出...")
	_, _ = fmt.Scanln()
}

// stdinIsCharDevice 判断标准输入是否为字符设备（终端）。
// 双击打开控制台程序时成立; 被重定向(管道/文件)时返回 false。
func stdinIsCharDevice() bool {
	fi, err := os.Stdin.Stat()
	if err != nil {
		return false
	}
	return fi.Mode()&os.ModeCharDevice != 0
}

// defaultConfigPath 返回配置文件默认名。
// 独立成函数便于未来因平台/入口不同而调整。
func defaultConfigPath() string {
	return "config.json"
}