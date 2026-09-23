package main

import (
	"context"
	"embed"
	"fmt"
	"io/fs"
	"net"
	"net/http"
	"os"
	"os/exec"
	"runtime"
)

//go:embed web
var webFS embed.FS

// webHandler 返回前端静态资源处理器。
// dev=true 时直接读本地磁盘 web/ 目录（改前端无需重编译）；否则读内嵌文件系统。
func webHandler(dev bool) (http.Handler, error) {
	if dev {
		return http.FileServer(http.Dir("web")), nil
	}
	sub, err := fs.Sub(webFS, "web")
	if err != nil {
		return nil, err
	}
	return http.FileServer(http.FS(sub)), nil
}

// uiAPI 持有本地 API 处理器共享的依赖。
type uiAPI struct {
	cfg     *Config
	cfgPath string // config 配置文件路径（G5 配置写回用）
	dev     bool
}

// runWebUI 启动本地 Web 控制台并阻塞直到被关闭。
// useWindow=true 时在原生桌面窗口（WebView2）中加载页面，不再调用外部浏览器。
func runWebUI(cfg *Config, cfgPath string, dev, noBrowser, useWindow bool, portOverride int) error {
	// GUI 生命周期 context：窗口/服务退出时取消，连带停止后台采样协程。
	uiCtx, cancelUI := context.WithCancel(context.Background())
	defer cancelUI()

	port := cfg.UIPort
	if portOverride > 0 {
		port = portOverride
	}

	// 尝试监听：端口占用则自动 +1 重试，最多 20 次
	ln, port, err := listenWithRetry(cfg.UIHost, port, 20)
	if err != nil {
		return err
	}

	mux := http.NewServeMux()
	// 静态资源
	wh, err := webHandler(dev)
	if err != nil {
		return err
	}
	mux.Handle("/", wh)

	// 本地 API
	api := &uiAPI{cfg: cfg, cfgPath: cfgPath, dev: dev}
	mux.HandleFunc("/api/state", api.handleState)
	mux.HandleFunc("/api/ping", api.serveAPI)
	mux.HandleFunc("/api/ping/batch", api.serveAPI)
	mux.HandleFunc("/api/monitor-panel", api.handleMonitorPanel)
	mux.HandleFunc("/api/stats", api.handleStats)
	mux.HandleFunc("/api/events", api.handleEvents)
	mux.HandleFunc("/api/config", func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodPut {
			api.handleConfigPut(w, r)
			return
		}
		api.handleConfigGet(w, r)
	})

	url := fmt.Sprintf("http://%s/", ln.Addr().String())
	fmt.Printf("mcmonitor GUI 已启动: %s (退出: Ctrl+C)\n", url)

	// 后台周期采样：为「订阅监控」与「数据统计监测」提供实时数据。
	// 传入 cfgPath 而非 cfg——协程每次轮询前从磁盘重载配置与订阅，
	// 使「配置」页保存后即时生效。
	startUIPolling(uiCtx, cfgPath)

	go func() {
		<-waitForSignal().Done()
		ln.Close()
	}()

	// 桌面原生窗口模式：窗口在主线程阻塞，服务在后台运行；窗口关闭即退出。
	if useWindow && !noBrowser {
		fmt.Println("已打开桌面原生窗口（WebView2），关闭窗口即退出。")
		serveCh := make(chan error, 1)
		go func() { serveCh <- http.Serve(ln, mux) }()
		if err := runNativeGUI("mcmonitor 监控客户端", url); err != nil {
			// 原生窗口不可用（如缺 WebView2）时回退到默认浏览器。
			fmt.Fprintln(os.Stderr, "[warn]", err)
			openBrowser(url)
			return <-serveCh
		}
		ln.Close()
		_ = <-serveCh
		return nil
	}

	if !noBrowser {
		openBrowser(url)
	}

	return http.Serve(ln, mux)
}

// listenWithRetry 监听 host:port，0 端口交给系统分配；非 0 被占用则自动顺延。
func listenWithRetry(host string, port, maxTries int) (net.Listener, int, error) {
	for i := 0; i < maxTries; i++ {
		ln, err := net.Listen("tcp", fmt.Sprintf("%s:%d", host, port))
		if err == nil {
			addr := ln.Addr().(*net.TCPAddr)
			return ln, addr.Port, nil
		}
		if port == 0 {
			return nil, 0, err // 随机端口失败没必要重试
		}
		port++ // 端口占用则顺延
	}
	return nil, 0, fmt.Errorf("无法在 %s 端口绑定监听(已尝试 %d 次): 端口可能被占用", host, maxTries)
}

// openBrowser 用系统默认浏览器打开 URL（尽力而为，失败忽略）。
func openBrowser(target string) {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		cmd = exec.Command("rundll32", "url.dll,FileProtocolHandler", target)
	case "darwin":
		cmd = exec.Command("open", target)
	default:
		cmd = exec.Command("xdg-open", target)
	}
	_ = cmd.Start()
}