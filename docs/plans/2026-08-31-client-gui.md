# 客户端 GUI（侧栏导航 / MC像素风 / 双模式 Web）实现计划

> **面向 AI 代理的工作者：** 必需子技能：使用 superpowers:subagent-driven-development（推荐）或 superpowers:executing-plans 逐任务实现此计划。步骤使用复选框（`- [ ]`）语法来跟踪进度。

**目标：** 为现有 Go CLI 监控客户端（`client/`，module `mcmon`）新增 GUI 可视化控制台：浏览器访问本地 `127.0.0.1:<端口>` 即可使用可视化搜索、订阅监控、统计与配置，前端资源可 `go:embed` 打进单 exe。

**架构：** 用标准库 `net/http` 起本地 Web 服务器，`go:embed` 打包前端静态资源（发布单 exe）/ 开发时读本地 `web/` 目录。前端通过本地 HTTP API 与 Go 进程通信，业务逻辑（订阅/轮询/规则/快照）全部留在本地，远程主站仅作只读数据源。已批准的规格：`docs/specs/2026-08-31-client-gui-design.md`。

**技术栈：** Go 1.27（标准库 `net/http`、`embed`、`html/template`）+ 原生 HTML/CSS/JS 前端。零外部依赖。

---

## 文件结构

本计划的每一个任务会创建/修改以下文件（职责单一）：

- `client/ui_server.go` — 本地 Web 服务器：路由、静态资源（dev/embed 双模式）、本地 `/api/*` 处理器注册、自动开浏览器、端口重试。
- `client/ui_handlers.go` — 本地 API 处理器：`state` / `ping` / `ping/batch` / `stats` / `config`。
- `client/stats.go` — 读取 `snapshots/*.json` 聚合出趋势数据（供 `GET /api/stats`）。
- `client/ui_server_test.go` — 对 HTTP 处理器与 stats 聚合的单元测试（Go 标准库 `testing` + `httptest`）。
- `client/web/index.html` — 前端单页：侧栏导航 + 四个视图容器。
- `client/web/assets/app.css` — MC 像素风样式（草绿高亮 `#7CB342` + 深灰导航 + 泥土米色内容 + Fusion Pixel 字体）。
- `client/web/assets/app.js` — 前端逻辑：侧栏切换、单查卡片流、批查 MC 列表行、订阅监控面板、统计图、配置页。
- `client/main.go` — 修改：新增 `--ui` / `--dev` / `--port` / `--no-browser` 参数，使其可启动 Web 控制台而非仅轮询。
- `client/config.go` — 修改：`Config` 增加 `ui_port`、`ui_host`（默认值），供前端/服务配置。
- `client/config.example.json` — 增加 UI 相关示例键。

任务拆解有顺序依赖：G0 骨架 → G1 视觉框架 → G2 搜索 → G3 监控面板 → G4 统计 → G5 配置页 → G6 打磨。每个任务独立可测试。

---

## 任务 1：G0 骨架 — Web 服务器 + 双模式静态 + 参数开关

**文件：**
- 创建：`client/ui_server.go`
- 修改：`client/main.go`（新增参数解析与调度）
- 修改：`client/config.go`（新增 `ui_host`/`ui_port`）
- 测试：`client/ui_server_test.go`

- [ ] **步骤 1：在 `config.go` 增加 UI 配置字段**

在 `Config` 结构体（`e:\In_development\mc-server-api\client\config.go`）的 `WebhookCooldownSeconds` 之后追加，并在 `defaultConfig()` 中补默认值：

```go
	// ===== Web 控制台（GUI） =====

	// 本地 Web 控制台监听地址与端口（GUI 模式用）。0 表示随机端口。
	UIHost string `json:"ui_host"`
	UIPort int    `json:"ui_port"`
```

`defaultConfig()` 中补充：

```go
		WebhookCooldownSeconds: 10,

		UIHost: "127.0.0.1",
		UIPort: 0,
```

- [ ] **步骤 2：新增 `client/ui_server.go` 的骨架（含 embed 与 dev 双模式）**

```go
package main

import (
	"embed"
	"io/fs"
	"net/http"
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
```

- [ ] **步骤 3：编写失败的测试**

创建 `client/ui_server_test.go`：

```go
package main

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

// TestWebHandlerDev 验证 dev 模式能提供静态文件（web 目录暂时可能不存在，
// 若 os.Stat 失败则跳过，交由集成手工验证）。
func TestWebHandlerDev(t *testing.T) {
	h, err := webHandler(false) // 用 embed 分支，不依赖磁盘
	if err != nil {
		t.Fatalf("webHandler(false) 意外错误: %v", err)
	}
	srv := httptest.NewServer(h)
	defer srv.Close()
	resp, err := http.Get(srv.URL + "/index.html")
	if err != nil {
		t.Fatalf("GET /index.html 失败: %v", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		t.Fatalf("期望 200，得到 %d", resp.StatusCode)
	}
}
```

- [ ] **步骤 4：占位 `web/index.html` 使 embed 可编译**

`embed` 要求在编译时 `web` 目录存在。先创建 `client/web/index.html` 的最小占位（后续任务填充完整）：

```html
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>mcmon 控制台</title>
</head>
<body>
  <p>mcmon GUI 骨架占位页（G1 将替换为侧栏布局）。</p>
</body>
</html>
```

- [ ] **步骤 5：运行测试确认通过**

运行：`cd e:\In_development\mc-server-api\client; go test ./... -run TestWebHandlerDev -v`
预期：`PASS`

- [ ] **步骤 6：修改 `main.go` 支持 `--ui` / `--dev` / `--no-browser` / `--port` 参数**

修改 `parseArgs` 签名与 switch（`e:\In_development\mc-server-api\client\main.go`）：

```go
func parseArgs(args []string) (configPath string, nopause, showHelp, showVersion, ui bool, dev bool, noBrowser bool, port int, err error) {
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
		case a == "--ui":
			ui = true
		case a == "--dev":
			dev = true
		case a == "--no-browser":
			noBrowser = true
		case a == "--port":
			if i+1 >= len(args) {
				return "", false, false, false, false, false, false, 0, errors.New("--port 需要一个端口号")
			}
			i++
			port = parseIntOrZero(args[i])
		case a == "--config":
			if i+1 >= len(args) {
				return "", false, false, false, false, false, false, 0, errors.New("--config 需要一个参数值")
			}
			i++
			configPath = args[i]
		case strings.HasPrefix(a, "--config="):
			configPath = strings.TrimPrefix(a, "--config=")
		default:
			return "", false, false, false, false, false, false, 0, fmt.Errorf("未知参数: %s", a)
		}
	}
	return configPath, nopause, showHelp, showVersion, ui, dev, noBrowser, port, nil
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
```

修改 `usage()` 加入新参数说明与 `--ui` 用法：在「参数:」段落追加：

```
  --ui         启动 Web 控制台（浏览器访问 http://127.0.0.1:<端口>）
  --dev        前端读本地 web/ 目录（开发热改，需配合 --ui）
  --no-browser 启动后不自动打开浏览器
  --port       指定控制台端口 (0 表示随机, 默认取 config.ui_port)
```

修改 `main()` 调用处使其传入/接收新返回值，并在解析完成后分派：

```go
	configPath, nopause, showHelp, showVersion, ui, dev, noBrowser, port, err := parseArgs(os.Args[1:])
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

	cfg, _, lerr := LoadConfig(configPath)
	if lerr != nil {
		fmt.Fprintln(os.Stderr, "[error]", lerr)
		os.Exit(1)
	}

	if ui {
		if err := runWebUI(cfg, dev, noBrowser, port); err != nil {
			fmt.Fprintln(os.Stderr, "[error]", err)
			os.Exit(1)
		}
		return
	}

	var code int
	if err := run(configPath); err != nil {
		fmt.Fprintln(os.Stderr, "[error]", err)
		code = 1
	}

	if !nopause {
		pauseIfInteractive()
	}
	os.Exit(code)
```

- [ ] **步骤 7：新增 `runWebUI` 与本地路由（暂时只挂静态 + `/api/state` 最小实现）**

在 `client/ui_server.go` 追加：

```go
import (
	// ...
	"net"
	"fmt"
	"time"
	"net/url"
	"os/exec"
	"runtime"
)

// runWebUI 启动本地 Web 控制台并阻塞直到被关闭。
func runWebUI(cfg *Config, dev bool, noBrowser bool, portOverride int) error {
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
	api := &uiAPI{cfg: cfg, dev: dev}
	mux.HandleFunc("/api/state", api.handleState)

	url := fmt.Sprintf("http://%s/", ln.Addr().String())
	fmt.Printf("mcmon GUI 已启动: %s (退出: Ctrl+C)\n", url)

	go func() {
		<-waitForSignal().Done()
		ln.Close()
	}()

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

// uiAPI 持有本地 API 处理器共享的依赖。
type uiAPI struct {
	cfg *Config
	dev bool
}
```

- [ ] **步骤 8：新增 `handleState` 最小实现（在 `client/ui_handlers.go`）**

创建 `client/ui_handlers.go`：

```go
package main

import (
	"encoding/json"
	"net/http"
)

// handleState 返回客户端当前运行状态：配置 + 订阅清单（脱敏：不含 webhook_token）。
func (a *uiAPI) handleState(w http.ResponseWriter, r *http.Request) {
	subsPath := a.cfg.SubscriptionsFile
	subs, err := LoadSubscriptions(subsPath, a.cfg.DefaultPort)
	state := map[string]any{
		"ok": true,
	}
	if err != nil {
		state["subscriptions_error"] = err.Error()
	} else {
		state["subscriptions"] = subs.Servers
	}
	// 不把 webhook_token 回传到前端
	cfgSafe := *a.cfg
	cfgSafe.WebhookToken = ""
	state["config"] = cfgSafe
	writeJSON(w, http.StatusOK, state)
}

// writeJSON 写 JSON 响应。
func writeJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}
```

- [ ] **步骤 9：编译 + 测试 + 运行一次冒烟**

运行：`cd e:\In_development\mc-server-api\client; go vet ./...; go test ./... -v`
预期：`PASS`；`go build -o mcmon.exe .` 无错。
手工冒烟：`.\mcmon.exe --ui --no-browser --port 9099`，浏览器访问 `http://127.0.0.1:9099/` 应看到占位页。

- [ ] **步骤 10：Commit**

```bash
cd e:\In_development\mc-server-api\client
git add ui_server.go ui_handlers.go ui_server_test.go web/index.html config.go main.go
git commit -m "feat(client): G0 GUI 骨架 - 本地Web服务器/双模式静态/端口重试/鉴权友好state"
```

---

## 任务 2：G1 视觉框架 — 侧栏导航 + MC 像素风

**文件：**
- 修改：`client/web/index.html`
- 创建：`client/web/assets/app.css`
- 创建：`client/web/assets/app.js`（最小：侧栏切换 + 拉取 /api/state）
- 测试：修改 `client/ui_server_test.go`（验证嵌入的 index.html 引用 css/js）

- [ ] **步骤 1：重建 `client/web/index.html` 为侧栏框架**

```html
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>mcmon 控制台</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <span class="brand-block">▦</span>
        <span class="brand-name">mcmon</span>
      </div>
      <nav class="nav">
        <button class="nav-item active" data-view="search"><span>🔍</span> 可视化搜索</button>
        <button class="nav-item" data-view="monitor"><span>📡</span> 订阅监控</button>
        <button class="nav-item" data-view="stats"><span>📊</span> 数据统计监测</button>
        <button class="nav-item" data-view="config"><span>⚙️</span> 配置</button>
      </nav>
      <div class="sidebar-foot" id="state-pill">连接中…</div>
    </aside>
    <main class="content">
      <section class="view" id="view-search">搜索视图占位</section>
      <section class="view hidden" id="view-monitor">监控视图占位</section>
      <section class="view hidden" id="view-stats">统计视图占位</section>
      <section class="view hidden" id="view-config">配置视图占位</section>
    </main>
  </div>
  <script src="assets/app.js"></script>
</body>
</html>
```

- [ ] **步骤 2：创建 `client/web/assets/app.css`（MC 像素风）**

```css
/* ===== MC 像素风 主题令牌 ===== */
:root {
  --nav-bg: #3B3A3F;
  --nav-item: #595860;
  --nav-active: #7CB342;       /* 草绿高亮 */
  --content-bg: #F4F3F0;       /* 泥土米色 */
  --surface: #FBFBF9;
  --border: #D8D2C4;
  --text: #2E2C33;
  --text-muted: #7A787F;
  --accent: #7CB342;
  --pixel: "Fusion Pixel", "PingFang SC", "Microsoft YaHei", sans-serif;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; }
body {
  font-family: var(--pixel);
  color: var(--text);
  background: var(--content-bg);
}

/* 侧栏布局 */
.app { display: flex; height: 100vh; }
.sidebar {
  width: 200px; min-width: 200px;
  background: var(--nav-bg);
  color: #E9E6DF;
  display: flex; flex-direction: column;
}
.brand { display: flex; align-items: center; gap: 8px; padding: 16px; font-size: 18px; font-weight: 700; }
.brand-block { color: var(--nav-active); }
.nav { flex: 1; display: flex; flex-direction: column; padding: 8px; gap: 4px; }
.nav-item {
  text-align: left; border: 0; background: transparent;
  color: #C8C2DB; font-family: var(--pixel); font-size: 14px;
  padding: 10px 12px; border-radius: 6px; cursor: pointer;
  display: flex; align-items: center; gap: 8px;
}
.nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
.nav-item.active { background: var(--nav-active); color: #1F2B12; font-weight: 700; }
.sidebar-foot { padding: 12px 16px; font-size: 12px; color: #A9A4B8; }

.content { flex: 1; overflow: auto; padding: 20px; }
.view { background: var(--surface); border: 2px solid var(--border); border-radius: 10px; padding: 20px; min-height: 100%; }
.hidden { display: none !important; }
```

- [ ] **步骤 3：创建最小 `client/web/assets/app.js`（侧栏切换 + state 拉取）**

```js
'use strict';

// 侧栏视图切换
const navItems = document.querySelectorAll('.nav-item');
navItems.forEach(function (btn) {
  btn.addEventListener('click', function () {
    navItems.forEach(function (b) { b.classList.toggle('active', b === btn); });
    const views = document.querySelectorAll('.view');
    views.forEach(function (v) { v.classList.toggle('hidden', v.id !== 'view-' + btn.dataset.view); });
  });
});

// 拉取运行状态，更新左下角状态胶囊
fetch('/api/state').then(function (r) { return r.json(); }).then(function (s) {
  const pill = document.getElementById('state-pill');
  if (pill && s.ok) {
    const n = (s.subscriptions || []).length;
    pill.textContent = '运行中 · 订阅 ' + n + ' 服';
  }
}).catch(function () {
  const pill = document.getElementById('state-pill');
  if (pill) pill.textContent = '数据源离线';
});
```

- [ ] **步骤 4：扩展测试验证静态页包含 css/js 引用**

在 `client/ui_server_test.go` 追加：

```go
func TestWebHandlerEmbedIndexReferencesAssets(t *testing.T) {
	h, err := webHandler(false)
	if err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewServer(h)
	defer srv.Close()
	resp, err := http.Get(srv.URL + "/index.html")
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	buf := make([]byte, 4096)
	n, _ := resp.Body.Read(buf)
	body := string(buf[:n])
	if !strings.Contains(body, "assets/app.css") || !strings.Contains(body, "assets/app.js") {
		t.Fatalf("index.html 缺少 css/js 引用")
	}
	// 抽测静态资源可访问
	for _, p := range []string{"/assets/app.css", "/assets/app.js"} {
		r2, err := http.Get(srv.URL + p)
		if err != nil {
			t.Fatalf("GET %s 失败: %v", p, err)
		}
		r2.Body.Close()
		if r2.StatusCode != http.StatusOK {
			t.Fatalf("GET %s 期望200，得到 %d", p, r2.StatusCode)
		}
	}
}
```

需要 import `"strings"`。

- [ ] **步骤 5：运行测试 + 冒烟**

运行：`cd e:\In_development\mc-server-api\client; go test ./... -run TestWebHandlerEmbedIndexReferencesAssets -v; go build -o mcmon.exe .`
预期：`PASS`。`.\mcmon.exe --ui --dev --no-browser --port 9099` 后访问 `http://127.0.0.1:9099/`，应见 MC 像素风侧栏，左下角显示「运行中 · 订阅 2 服」。

- [ ] **步骤 6：Commit**

```bash
cd e:\In_development\mc-server-api\client
git add web/index.html web/assets/app.css web/assets/app.js ui_server_test.go
git commit -m "feat(client): G1 视觉框架 - 侧栏导航 + MC像素风主题 + state 拉取"
```

---

## 任务 3：G2 可视化搜索 — 单查卡片流 + 批查 MC 列表行

**文件：**
- 修改：`client/ui_handlers.go`（新增 `handlePing` / `handleBatch`）
- 修改：`client/web/index.html`（搜索视图表单）
- 修改：`client/web/assets/app.js`（搜索逻辑）
- 测试：`client/ui_server_test.go`（对本地转发处理器的单元测试，mock 掉对外请求）

- [ ] **步骤 1：`ui_handlers.go` 新增单查/批查转发处理器**

在 `client/ui_handlers.go` 追加（复用现有 `fetchBatch` / `batchServer` / `batchResult`；单查也走批量以便复用一个转发实现）：

```go
import (
	"encoding/json"
	"net/http"
	"net/url"
	"strconv"
)

// handleBatch 把前端的批量请求转发到服务端 /api/ping/batch。
// 请求体格式与服务端一致: {"servers":[{"host":"..","port":25565}]}。
func (a *uiAPI) handleBatch(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]any{"ok": false, "error": "需 POST"})
		return
	}
	var req struct {
		Servers []batchServer `json:"servers"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "请求体解析失败"})
		return
	}
	if len(req.Servers) == 0 || len(req.Servers) > 20 {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "servers 数量需在 1-20"})
		return
	}
	results, err := fetchBatch(r.Context(), a.cfg.ServerURL, req.Servers)
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "results": results})
}

// handlePing 单台查询：支持 GET /api/ping?host=&port= 或 POST body。
func (a *uiAPI) handlePing(w http.ResponseWriter, r *http.Request) {
	var host string
	var port int
	if r.Method == http.MethodGet {
		q := r.URL.Query()
		host = q.Get("host")
		if host == "" {
			writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "缺少 host"})
			return
		}
		if p := q.Get("port"); p != "" {
			if v, err := strconv.Atoi(p); err == nil {
				port = v
			}
		}
	} else {
		var body struct {
			Host string `json:"host"`
			Port int    `json:"port"`
		}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "请求体解析失败"})
			return
		}
		host, port = body.Host, body.Port
	}
	if port <= 0 {
		port = a.cfg.DefaultPort
	}
	results, err := fetchBatch(r.Context(), a.cfg.ServerURL, []batchServer{{Host: url.QueryEscape(host), Port: port}})
	_ = results
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "result": results[0]})
}
```

- [ ] **步骤 2：`ui_server.go` 注册 `/api/ping` 与 `/api/ping/batch` 路由**

在 `runWebUI` 中追加：

```go
	mux.HandleFunc("/api/ping", api.handlePing)
	mux.HandleFunc("/api/ping/batch", api.handleBatch)
```

注意：需用 `http.Method` 区分 `/api/ping` 与其子路径 `/api/ping/batch`。`http.ServeMux` 会匹配最长前缀，因此先注册 `/api/ping`，再注册 `/api/ping/batch`（Go 1.22+ 可用 `mux.HandleFunc("/api/ping/batch", ...)` 精确匹配既有前缀规则）。为稳妥，在 `handlePing` 内对 `r.URL.Path == "/api/ping/batch"` 分流调用 `handleBatch`：

```go
func (a *uiAPI) serveAPI(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path == "/api/ping/batch" {
		a.handleBatch(w, r)
		return
	}
	a.handlePing(w, r)
}
```

并在 mux 中注册：

```go
	mux.HandleFunc("/api/ping", api.serveAPI)
	mux.HandleFunc("/api/ping/batch", api.serveAPI)
```

- [ ] **步骤 3：`index.html` 填充「可视化搜索」视图**

替换 `#view-search` 占位为：

```html
<section class="view" id="view-search">
  <div class="search-tabs">
    <button class="search-tab active" data-mode="single">单查</button>
    <button class="search-tab" data-mode="batch">批查</button>
  </div>

  <form id="single-form" class="search-form">
    <input type="text" id="s-host" placeholder="服务器地址 (如 mc.hypixel.net)" required>
    <input type="number" id="s-port" placeholder="端口 (默认 25565)">
    <button type="submit">查询</button>
  </form>
  <div id="single-result" class="result-area"></div>

  <div id="batch-form" class="search-form hidden">
    <textarea id="b-hosts" rows="6" placeholder="每行一个服务器地址，可带端口:  host  或  host:port"></textarea>
    <button type="button" id="b-go">批量查询</button>
  </div>
  <div id="batch-result" class="result-area"></div>
</section>
```

- [ ] **步骤 4：`app.js` 实现搜索逻辑（卡片流 / 列表行）**

在 `client/web/assets/app.js` 末尾追加：

```js
// ===== 可视化搜索 =====
(function () {
  const singleForm = document.getElementById('single-form');
  const batchForm = document.getElementById('batch-form');
  const tabs = document.querySelectorAll('.search-tab');
  const singleResult = document.getElementById('single-result');
  const batchResult = document.getElementById('batch-result');
  const bHosts = document.getElementById('b-hosts');
  const hostInput = document.getElementById('s-host');

  tabs.forEach(function (t) {
    t.addEventListener('click', function () {
      tabs.forEach(function (x) { x.classList.toggle('active', x === t); });
      const isSingle = t.dataset.mode === 'single';
      singleForm.classList.toggle('hidden', !isSingle);
      batchForm.classList.toggle('hidden', isSingle);
    });
  });

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // 单查：卡片流
  singleForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const host = hostInput.value.trim();
    if (!host) return;
    const port = document.getElementById('s-port').value.trim();
    singleResult.innerHTML = '查询中…';
    fetch('/api/ping?host=' + encodeURIComponent(host) + (port ? '&port=' + encodeURIComponent(port) : ''))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { singleResult.innerHTML = '<p class="err">查询失败: ' + esc(d.error) + '</p>'; return; }
        singleResult.innerHTML = renderCard(d.result);
      });
  });

  function renderCard(res) {
    const online = !!res.online;
    const card = document.createElement('div');
    card.className = 'mc-card ' + (online ? 'up' : 'down');
    const ver = res.version ? res.version.name : (res.protocol_used || '未知');
    const on = res.players && res.players.online != null ? res.players.online : '-';
    const max = res.players && res.players.max != null ? res.players.max : '-';
    const lat = res.latency_ms != null ? res.latency_ms + 'ms' : '-';
    const motd = res.motd && res.motd.plain_text ? res.motd.plain_text : '';
    card.innerHTML =
      '<div class="mc-card-head"><span class="mc-card-host">' + esc(res.host) + '</span>' +
      '<span class="mc-card-status">' + (online ? '在线' : '离线') + '</span></div>' +
      '<div class="mc-card-meta">' + esc(ver) + ' · ' + on + '/' + max + ' · 延迟 ' + lat + '</div>' +
      '<div class="mc-card-motd">' + esc(motd) + '</div>';
    return card.outerHTML;
  }

  // 批查：MC 列表行
  document.getElementById('b-go').addEventListener('click', function () {
    const lines = bHosts.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
    const servers = lines.map(function (ln) {
      const m = ln.match(/^(.+):(\d+)$/);
      if (m) return { host: m[1], port: parseInt(m[2], 10) };
      return { host: ln, port: 0 };
    });
    if (!servers.length) { batchResult.innerHTML = '<p class="err">请输入至少一个服务器</p>'; return; }
    batchResult.innerHTML = '并发查询 ' + servers.length + ' 台…';
    fetch('/api/ping/batch', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ servers: servers })
    }).then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { batchResult.innerHTML = '<p class="err">批量查询失败: ' + esc(d.error) + '</p>'; return; }
        const rows = d.results.map(function (res) {
          const online = !!res.online;
          const ver = res.version ? res.version.name : (res.protocol_used || '未知');
          const on = res.players && res.players.online != null ? res.players.online : '-';
          const max = res.players && res.players.max != null ? res.players.max : '-';
          const lat = res.latency_ms != null ? res.latency_ms + 'ms' : '-';
          return '<div class="mc-row ' + (online ? 'up' : 'down') + '">' +
            '<span class="mc-row-host">' + esc(res.host) + '</span>' +
            '<span class="mc-row-ver">' + esc(ver) + '</span>' +
            '<span class="mc-row-count">' + on + '/' + max + '</span>' +
            '<span class="mc-row-lat">' + lat + '</span>' +
            '</div>';
        }).join('');
        batchResult.innerHTML = rows;
      });
  });
})();
```

- [ ] **步骤 5：`app.css` 补充卡片流/列表行样式**

在 `client/web/assets/app.css` 末尾追加：

```css
/* ===== 可视化搜索 ===== */
.search-tabs { display: flex; gap: 8px; margin-bottom: 16px; }
.search-tab {
  border: 2px solid var(--border); background: var(--surface); color: var(--text);
  padding: 8px 20px; font-family: var(--pixel); font-size: 14px; cursor: pointer; border-radius: 6px;
}
.search-tab.active { background: var(--nav-active); border-color: var(--nav-active); color: #1F2B12; font-weight: 700; }

.search-form { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; align-items: flex-end; }
.search-form input[type=text], .search-form input[type=number] {
  border: 2px solid var(--border); padding: 8px 10px; font-family: var(--pixel); font-size: 14px; border-radius: 6px; flex: 1; min-width: 160px;
}
.search-form textarea { border: 2px solid var(--border); padding: 8px 10px; font-family: var(--pixel); font-size: 14px; border-radius: 6px; width: 100%; }
.search-form button, #b-go {
  border: 0; background: var(--nav-active); color: #1F2B12; padding: 9px 22px;
  font-family: var(--pixel); font-size: 14px; font-weight: 700; border-radius: 6px; cursor: pointer;
}
.result-area { margin-top: 8px; }
.err { color: #C0392B; }

.mc-card { border: 2px solid var(--border); border-radius: 10px; padding: 16px; }
.mc-card.up { border-left: 6px solid var(--nav-active); }
.mc-card.down { border-left: 6px solid #C0392B; }
.mc-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.mc-card-host { font-size: 16px; font-weight: 700; }
.mc-card-status { font-size: 13px; padding: 2px 10px; border-radius: 999px; }
.mc-card.up .mc-card-status { background: var(--nav-active); color: #1F2B12; }
.mc-card.down .mc-card-status { background: #C0392B; color: #fff; }
.mc-card-meta { color: var(--text-muted); font-size: 13px; margin-bottom: 8px; }
.mc-card-motd { border-top: 1px dashed var(--border); padding-top: 8px; }

.mc-row { display: flex; gap: 12px; align-items: center; padding: 10px; border: 2px solid var(--border); border-bottom: 0; }
.mc-row:last-child { border-bottom: 2px solid var(--border); }
.mc-row.up { border-left: 6px solid var(--nav-active); }
.mc-row.down { border-left: 6px solid #C0392B; }
.mc-row-host { flex: 1; font-weight: 700; }
.mc-row-ver { flex: 2; color: var(--text-muted); }
.mc-row-count { flex: 0 0 70px; text-align: right; }
.mc-row-lat { flex: 0 0 70px; text-align: right; color: var(--text-muted); }
```

- [ ] **步骤 6：扩展测试覆盖单查转发（mock 服务端）**

在 `client/ui_server_test.go` 追加一个用 `httptest` 模拟服务端批量接口、验证本地 `/api/ping` 转发正确的测试。需要让 `fetchBatch` 的 baseURL 可注入——当前它硬编码读 `cfg.ServerURL`。为可测，重构 `fetchBatch` 签名以接受 `client *http.Client`（或保留全局，测试直接测 `handlePing` 用真实服务端会联网，不可取）。因此本计划引入一个轻量注入：

修改 `api.go` 中 `fetchBatch` 增加可选参数不破坏现有调用代价高；改为新增内部函数：

```go
// fetchBatchWithClient 与 fetchBatch 相同，但允许注入 client 以便测试 mock。
func fetchBatchWithClient(ctx context.Context, baseURL string, servers []batchServer, client *http.Client) ([]batchResult, error) {
	// ... 同 fetchBatch 实现，仅把 httpClient 换为参数 client
}

func fetchBatch(ctx context.Context, baseURL string, servers []batchServer) ([]batchResult, error) {
	return fetchBatchWithClient(ctx, baseURL, servers, httpClient)
}
```

测试：

```go
// TestHandlePingForwards 用本地 mock 服务端验证 /api/ping 转发。
func TestHandlePingForwards(t *testing.T) {
	// mock 服务端
	mock := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"success":true,"code":0,"message":"ok","data":{"total":1,"success":1,"failed":0,"results":[{"online":true,"host":"mc.hypixel.net","port":25565,"latency_ms":110,"version":{"name":"1.8-1.21"},"players":{"online":5,"max":10},"motd":{"plain_text":"hi"},"protocol_used":"modern","srv_used":false}]}}`))
	}))
	defer mock.Close()

	cfg := defaultConfig()
	cfg.ServerURL = mock.URL
	api := &uiAPI{cfg: cfg}

	req := httptest.NewRequest("GET", "/api/ping?host=mc.hypixel.net", nil)
	rec := httptest.NewRecorder()
	api.handlePing(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("期望 200，得到 %d: %s", rec.Code, rec.Body.String())
	}
	var resp map[string]any
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatalf("响应非 JSON: %v", err)
	}
	if resp["ok"] != true {
		t.Fatalf("ok 应为 true: %v", resp)
	}
}
```

需要 import `"encoding/json"`。

- [ ] **步骤 7：运行测试 + 冒烟**

运行：`cd e:\In_development\mc-server-api\client; go test ./... -v; go build -o mcmon.exe .`
预期：全部 `PASS`。`.\mcmon.exe --ui --dev --no-browser --port 9099`，单查 `mc.hypixel.net` 应显示卡片流，批查多台显示 MC 列表行。

- [ ] **步骤 8：Commit**

```bash
cd e:\In_development\mc-server-api\client
git add ui_handlers.go ui_server.go web/index.html web/assets/app.js web/assets/app.css ui_server_test.go
git commit -m "feat(client): G2 可视化搜索 - 单查卡片流/批查MC列表行 + 本地转发"
```

---

## 任务 4：G3 订阅监控面板

**文件：**
- 修改：`client/ui_handlers.go`（新增 `handleMonitorPanel`）
- 修改：`client/web/index.html`（`#view-monitor` 面板）
- 修改：`client/web/assets/app.js`（轮询渲染订阅状态）
- 修改：`client/ui_server_test.go`

- [ ] **步骤 1：`ui_handlers.go` 新增 `handleMonitorPanel`**

返回本地订阅清单 + 各服最新快照（读 `snapshots/*.json`）。复用 `mapResultToSnapshot` 由快照文件反序列化即可——更直接做法：扫描 `cfg.SnapshotDir` 下 `*.json`，反序列化为 `Snapshot` 返回列表：

```go
import (
	"os"
	"path/filepath"
)

// handleMonitorPanel 返回订阅的服务器列表及其最近快照，供前端渲染实时面板。
func (a *uiAPI) handleMonitorPanel(w http.ResponseWriter, r *http.Request) {
	abs, err := ensureSnapshotDir(a.cfg.SnapshotDir)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	files, err := filepath.Glob(filepath.Join(abs, "*.json"))
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	snaps := make([]*Snapshot, 0, len(files))
	for _, f := range files {
		raw, err := os.ReadFile(f)
		if err != nil {
			continue
		}
		var s Snapshot
		if json.Unmarshal(raw, &s) != nil {
			continue
		}
		snaps = append(snaps, &s)
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "snapshots": snaps})
}
```

- [ ] **步骤 2：注册路由**

在 `runWebUI` 追加：`mux.HandleFunc("/api/monitor-panel", api.handleMonitorPanel)`

- [ ] **步骤 3：`index.html` 填充订阅监控视图**

替换 `#view-monitor` 占位：

```html
<section class="view" id="view-monitor">
  <div class="monitor-head">
    <h2>订阅监控</h2>
    <span class="auto-refresh-hint">每 30s 自动刷新</span>
  </div>
  <div id="monitor-list" class="monitor-list">加载中…</div>
</section>
```

- [ ] **步骤 4：`app.js` 实现监控面板轮询渲染**

```js
// ===== 订阅监控 =====
(function () {
  const list = document.getElementById('monitor-list');
  function renderMonitor() {
    fetch('/api/monitor-panel').then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok || !d.snapshots) return;
      const snaps = d.snapshots;
      if (!snaps.length) { list.innerHTML = '<p class="err">暂无本地快照，请先在「可视化搜索」或让轮询器跑一到两轮。</p>'; return; }
      list.innerHTML = snaps.map(function (s) {
        const online = !!s.online;
        const on = s.players_online != null ? s.players_online : '-';
        const max = s.players_max != null ? s.players_max : '-';
        const lat = s.latency_ms != null ? s.latency_ms + 'ms' : '-';
        return '<div class="monitor-row ' + (online ? 'up' : 'down') + '">' +
          '<span class="monitor-alias">' + esc(s.alias || s.host) + '</span>' +
          '<span class="monitor-host">' + esc(s.host + ':' + s.port) + '</span>' +
          '<span class="monitor-count">' + on + '/' + max + '</span>' +
          '<span class="monitor-lat">' + lat + '</span>' +
          '<span class="monitor-at">' + esc(s.at || '') + '</span>' +
          '</div>';
      }).join('');
    }).catch(function () { list.innerHTML = '<p class="err">无法连接本地服务</p>'; });
  }
  renderMonitor();
  setInterval(renderMonitor, 30000);
})();
```

- [ ] **步骤 5：`app.css` 补充监控面板样式**

```css
/* ===== 订阅监控 ===== */
.monitor-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.monitor-head h2 { font-size: 16px; }
.auto-refresh-hint { color: var(--text-muted); font-size: 12px; }
.monitor-row { display: flex; gap: 12px; align-items: center; padding: 10px; border: 2px solid var(--border); border-bottom: 0; }
.monitor-row:last-child { border-bottom: 2px solid var(--border); }
.monitor-row.up { border-left: 6px solid var(--nav-active); }
.monitor-row.down { border-left: 6px solid #C0392B; }
.monitor-alias { flex: 1; font-weight: 700; }
.monitor-host { flex: 2; color: var(--text-muted); }
.monitor-count { flex: 0 0 70px; text-align: right; }
.monitor-lat { flex: 0 0 70px; text-align: right; color: var(--text-muted); }
.monitor-at { flex: 0 0 120px; text-align: right; color: var(--text-muted); font-size: 12px; }
```

- [ ] **步骤 6：测试 `handleMonitorPanel`**

在 `client/ui_server_test.go` 追加：

```go
func TestHandleMonitorPanelReadsSnapshots(t *testing.T) {
	dir := t.TempDir()
	// 写入一个合法快照 + 一个非法文件
	srv := &Server{Host: "mc.hypixel.net", Port: 25565, Alias: "Hypixel"}
	snap := newSnapshotAt(srv, timeNow())
	snap.Online = true
	os.WriteFile(filepath.Join(dir, "a.json"), mustJSON(t, snap), 0644)
	os.WriteFile(filepath.Join(dir, "bad.json"), []byte("not json"), 0644)

	cfg := defaultConfig()
	cfg.SnapshotDir = dir
	api := &uiAPI{cfg: cfg}

	req := httptest.NewRequest("GET", "/api/monitor-panel", nil)
	rec := httptest.NewRecorder()
	api.handleMonitorPanel(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("期望200，得到 %d", rec.Code)
	}
	var resp struct {
		OK        bool       `json:"ok"`
		Snapshots []Snapshot `json:"snapshots"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}
	if !resp.OK || len(resp.Snapshots) != 1 {
		t.Fatalf("期望 1 个合法快照，得到 %v", resp)
	}
}
```

需要 helpers（放在测试文件顶部）：

```go
func mustJSON(t *testing.T, v any) []byte {
	t.Helper()
	b, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func timeNow() time.Time { return time.Date(2026, 8, 31, 12, 0, 0, 0, time.UTC) }
```

需要 import `"time"`、`"os"`、`"path/filepath"`、`"time"`。

- [ ] **步骤 7：运行测试 + 冒烟**

运行：`cd e:\In_development\mc-server-api\client; go test ./... -v; go build -o mcmon.exe .`
预期：`PASS`。`.\mcmon.exe --ui --dev --no-browser --port 9099` 后切到「订阅监控」，应显示你订阅的两台服务器（来自 snapshots 目录）。

- [ ] **步骤 8：Commit**

```bash
cd e:\In_development\mc-server-api\client
git add ui_handlers.go web/index.html web/assets/app.js web/assets/app.css ui_server_test.go
git commit -m "feat(client): G3 订阅监控面板 - 实时状态列表(30s自动刷新)"
```

---

## 任务 5：G4 数据统计 — 趋势图

**文件：**
- 创建：`client/stats.go`
- 修改：`client/ui_handlers.go`（新增 `handleStats`）
- 修改：`client/web/index.html`（`#view-stats` 视图）
- 修改：`client/web/assets/app.js`（SVG 折线图渲染）
- 修改：`client/ui_server_test.go`

- [ ] **步骤 1：创建 `client/stats.go` 聚合快照趋势**

```go
package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"time"
)

// StatsPoint 是趋势图上的一个数据点。
type StatsPoint struct {
	At       time.Time `json:"at"`
	Online   bool      `json:"online"`
	LatencyMS *int     `json:"latency_ms"`
	Players  *int      `json:"players_online"`
}

// loadSnapshotFile 读取单个快照文件并解析为 *Snapshot。
func loadSnapshotFile(path string) (*Snapshot, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var s Snapshot
	if err := json.Unmarshal(raw, &s); err != nil {
		return nil, err
	}
	return &s, nil
}

// loadSnapshots 读取快照目录下全部 *.json，返回按 at 升序排列的快照。
func loadSnapshots(dir string) ([]*Snapshot, error) {
	abs, err := ensureSnapshotDir(dir)
	if err != nil {
		return nil, err
	}
	files, err := filepath.Glob(filepath.Join(abs, "*.json"))
	if err != nil {
		return nil, err
	}
	out := make([]*Snapshot, 0, len(files))
	for _, f := range files {
		s, err := loadSnapshotFile(f)
		if err != nil {
			continue // 跳过坏文件
		}
		out = append(out, s)
	}
	// 按 at 升序
	// 注意：快照目录经 pollOnce 写入，每台服务器一份文件；趋势应"按 key 分组 + 按时间排序"。
	return out, nil
}
```

为正确画趋势，应**按服务器分组再按时序排**。statistics 处理器将按 `key` 分组：

```go
// statsForServer 返回某台服务器按时间升序的趋势点。
func statsForServer(dir, key string) ([]StatsPoint, error) {
	snaps, err := loadSnapshots(dir)
	if err != nil {
		return nil, err
	}
	points := make([]StatsPoint, 0, len(snaps))
	for _, s := range snaps {
		if s.Key != key {
			continue
		}
		at, err := time.Parse(time.RFC3339, s.At)
		if err != nil {
			continue
		}
		points = append(points, StatsPoint{
			At: at, Online: s.Online, LatencyMS: s.LatencyMS, Players: s.PlayersOnline,
		})
	}
	// 升序排序
	for i := 1; i < len(points); i++ {
		for j := i; j > 0 && points[j].At.Before(points[j-1].At); j-- {
			points[j], points[j-1] = points[j-1], points[j]
		}
	}
	return points, nil
}
```

- [ ] **步骤 2：`ui_handlers.go` 新增 `handleStats`**

```go
// handleStats 返回可用的服务器 key 列表及某台服务器的趋势点。
func (a *uiAPI) handleStats(w http.ResponseWriter, r *http.Request) {
	snaps, err := loadSnapshots(a.cfg.SnapshotDir)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	keys := make(map[string]string) // key -> alias
	for _, s := range snaps {
		keys[s.Key] = s.Alias
	}
	host := r.URL.Query().Get("server")
	resp := map[string]any{"ok": true, "servers": keys}
	if host != "" {
		pts, err := statsForServer(a.cfg.SnapshotDir, host)
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
			return
		}
		resp["points"] = pts
	}
	writeJSON(w, http.StatusOK, resp)
}
```

- [ ] **步骤 3：注册路由**

`mux.HandleFunc("/api/stats", api.handleStats)`

- [ ] **步骤 4：`index.html` 填充统计视图**

```html
<section class="view" id="view-stats">
  <h2>数据统计监测</h2>
  <label>选择服务器:
    <select id="stats-server"><option value="">--</option></select>
  </label>
  <div class="stats-chart"><svg id="stats-svg" viewBox="0 0 600 200">加载中…</svg></div>
</section>
```

- [ ] **步骤 5：`app.js` 渲染趋势图（纯 SVG 折线，人数为主指标）**

```js
// ===== 数据统计 =====
(function () {
  const sel = document.getElementById('stats-server');
  const svg = document.getElementById('stats-svg');
  function loadServers() {
    fetch('/api/stats').then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok || !d.servers) return;
      Object.keys(d.servers).forEach(function (k) {
        const o = document.createElement('option');
        o.value = k;
        o.textContent = (d.servers[k] || k);
        sel.appendChild(o);
      });
      if (sel.options.length > 1) loadChart(sel.options[1].value);
    });
  }
  function loadChart(key) {
    svg.innerHTML = '加载中…';
    fetch('/api/stats?server=' + encodeURIComponent(key)).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok || !d.points || !d.points.length) { svg.innerHTML = '<text x="20" y="20">暂无历史数据</text>'; return; }
      drawChart(d.points);
    });
  }
  function drawChart(pts) {
    const w = 600, h = 200, pad = 30;
    const vals = pts.map(function (p) { return p.players_online != null ? p.players_online : 0; });
    let maxV = 10; vals.forEach(function (v) { if (v > maxV) maxV = v; });
    // 生成折线 path
    let path = '';
    pts.forEach(function (p, i) {
      const x = pad + (i / (pts.length - 1 || 1)) * (w - 2 * pad);
      const y = h - pad - ((p.players_online != null ? p.players_online : 0) / maxV) * (h - 2 * pad);
      path += (i === 0 ? 'M' : 'L') + x.toFixed(1) + ',' + y.toFixed(1);
    });
    svg.innerHTML = '<polyline points="' + path.slice(1) + '" fill="none" stroke="#7CB342" stroke-width="2"/>';
  }
  sel.addEventListener('change', function () { if (sel.value) loadChart(sel.value); });
  loadServers();
})();
```

> 说明：上例的 y 缩放假设 h-2*pad 空间；若无数据自动退化到「暂无历史数据」，图不崩。

- [ ] **步骤 6：`app.css` 补充统计样式**

```css
/* ===== 数据统计 ===== */
.stats-chart { margin-top: 16px; border: 2px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface); }
.stats-chart svg { width: 100%; height: auto; display: block; }
#stats-server { border: 2px solid var(--border); padding: 6px 10px; font-family: var(--pixel); font-size: 14px; border-radius: 6px; }
```

- [ ] **步骤 7：测试 `statsForServer`**

在 `client/ui_server_test.go` 追加：

```go
func TestStatsForServer(t *testing.T) {
	dir := t.TempDir()
	// 同一台服务器两个时间点
	srv := &Server{Host: "mc.hypixel.net", Port: 25565}
	s1 := newSnapshotAt(srv, time.Date(2026, 8, 31, 10, 0, 0, 0, time.UTC))
	s1.Online = true
	n1, m1 := 5, 10
	s1.PlayersOnline, s1.PlayersMax = &n1, &m1
	os.WriteFile(filepath.Join(dir, "a.json"), mustJSON(t, s1), 0644)

	s2 := newSnapshotAt(srv, time.Date(2026, 8, 31, 10, 1, 0, 0, time.UTC))
	s2.Online = true
	s2.PlayersOnline, s2.PlayersMax = &n1, &m1
	os.WriteFile(filepath.Join(dir, "b.json"), mustJSON(t, s2), 0644)

	pts, err := statsForServer(dir, srv.Key())
	if err != nil {
		t.Fatal(err)
	}
	if len(pts) != 2 {
		t.Fatalf("期望 2 个点，得到 %d", len(pts))
	}
	if !pts[0].At.Before(pts[1].At) {
		t.Fatalf("期望按时间升序")
	}
}
```

- [ ] **步骤 8：运行测试 + 冒烟**

运行：`cd e:\In_development\mc-server-api\client; go test ./... -v; go build -o mcmon.exe .`
预期：`PASS`。「数据统计」视图会列出订阅的服务器 key，选中后显示人数折线（若快照才一两份则会退化到「暂无历史数据」，属预期）。

- [ ] **步骤 9：Commit**

```bash
cd e:\In_development\mc-server-api\client
git add stats.go ui_handlers.go web/index.html web/assets/app.js web/assets/app.css ui_server_test.go
git commit -m "feat(client): G4 数据统计 - 按服务器聚合快照趋势(SVG折线)"
```

---

## 任务 6：G5 配置页 — 可视化编辑 config + subscriptions

**文件：**
- 修改：`client/ui_handlers.go`（新增 `handleConfigGet` / `handleConfigPut`）
- 修改：`client/web/index.html`（`#view-config`）
- 修改：`client/web/assets/app.js`（配置表单保存）
- 修改：`client/ui_server_test.go`

- [ ] **步骤 1：`ui_handlers.go` 新增配置读/写**

```go
import (
	"bytes"
)

// handleConfigGet 返回当前 config（脱敏 webhook_token）、订阅清单，及文件路径。
func (a *uiAPI) handleConfigGet(w http.ResponseWriter, r *http.Request) {
	cfgSafe := *a.cfg
	cfgSafe.WebhookToken = ""
	subs, _ := LoadSubscriptions(a.cfg.SubscriptionsFile, a.cfg.DefaultPort)
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":              true,
		"config":          cfgSafe,
		"subscriptions":   subs,
		"subscriptions_path": a.cfg.SubscriptionsFile,
	})
}

// handleConfigPut 接收前端回传的配置 + 订阅，校验后写回本地 json。
func (a *uiAPI) handleConfigPut(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPut {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]any{"ok": false, "error": "需 PUT"})
		return
	}
	var payload struct {
		Config        *Config  `json:"config"`
		Subscriptions []*Server `json:"subscriptions"`
	}
	if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "请求体解析失败"})
		return
	}
	if payload.Config == nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "缺少 config"})
		return
	}
	// 校验：不覆盖 WebhookToken（前端不回传），保留原值
	cfg := payload.Config
	cfg.WebhookToken = a.cfg.WebhookToken

	// 写回配置文件
	if err := writeJSONFile(a.cfgPath(), cfg); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": "config 写回失败: " + err.Error()})
		return
	}
	// 写回订阅清单
	if payload.Subscriptions != nil {
		if err := writeJSONFile(a.cfg.SubscriptionsFile, map[string]any{"servers": payload.Subscriptions}); err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": "订阅写回失败: " + err.Error()})
			return
		}
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// writeJSONFile 以 0644 写入 JSON 文件（格式化+换行）。
func writeJSONFile(path string, v any) error {
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, append(data, '\n'), 0o644)
}
```

`uiAPI` 需要记录初始化加载的 config 文件路径，供写回。在 `runWebUI` 构造时传入：

```go
	api := &uiAPI{cfg: cfg, dev: dev, cfgPath: configPath}
```

需要给 `uiAPI` 增加字段 `cfgPath string`；`handleState`/`handleConfigGet` 不涉及写回则不需要。`runWebUI` 的 `configPath` 由调用处传入——需把配置文件路径传入 `runWebUI`。修改签名：`runWebUI(cfg *Config, cfgPath string, dev bool, noBrowser bool, portOverride int)`，并在 `main()` 调用处传入 `configPath` 变量。

- [ ] **步骤 2：注册路由**

```go
	mux.HandleFunc("/api/config", func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodPut {
			api.handleConfigPut(w, r)
			return
		}
		api.handleConfigGet(w, r)
	})
```

- [ ] **步骤 3：`index.html` 填充配置视图**

```html
<section class="view" id="view-config">
  <h2>配置</h2>
  <form id="config-form">
    <label>数据源地址<input type="text" id="cfg-server-url"></label>
    <label>轮询间隔(秒)<input type="number" id="cfg-poll" min="10"></label>
    <label>默认端口<input type="number" id="cfg-port"></label>
    <label>离线连续判定(轮)<input type="number" id="cfg-offline" min="1"></label>
    <label>延迟告警阈值(ms)<input type="number" id="cfg-latency" min="0"></label>
    <label>人数骤降比例(0~1)<input type="number" id="cfg-drop" min="0" max="1" step="0.05"></label>
    <label>骤降最小人数的引用<input type="number" id="cfg-dropmin" min="0"></label>
    <hr>
    <h3>订阅清单</h3>
    <div id="subs-editor"></div>
    <button type="submit" id="cfg-save">保存</button>
    <span id="cfg-msg"></span>
  </form>
</section>
```

- [ ] **步骤 4：`app.js` 渲染配置表单 + 保存**

```js
// ===== 配置 =====
(function () {
  const form = document.getElementById('config-form');
  const subsEditor = document.getElementById('subs-editor');
  const msg = document.getElementById('cfg-msg');
  function load() {
    fetch('/api/config').then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) return;
      const c = d.config;
      document.getElementById('cfg-server-url').value = c.server_url || '';
      document.getElementById('cfg-poll').value = c.poll_interval_seconds != null ? c.poll_interval_seconds : '';
      document.getElementById('cfg-port').value = c.default_port || '';
      document.getElementById('cfg-offline').value = c.offline_consecutive || '';
      document.getElementById('cfg-latency').value = c.latency_high_ms || '';
      document.getElementById('cfg-drop').value = c.drop_ratio != null ? c.drop_ratio : '';
      document.getElementById('cfg-dropmin').value = c.drop_min_people || '';
      // 订阅行
      const subs = (d.subscriptions && d.subscriptions.servers) || [];
      subsEditor.innerHTML = '';
      subs.forEach(function (s, i) {
        const row = document.createElement('div');
        row.className = 'sub-row';
        row.innerHTML =
          '<input data-i="' + i + '" data-f="host" value="' + esc(s.host || '') + '" placeholder="host">' +
          '<input data-i="' + i + '" data-f="port" type="number" value="' + (s.port || '') + '" placeholder="port">' +
          '<input data-i="' + i + '" data-f="alias" value="' + esc(s.alias || '') + '" placeholder="别名">' +
          '<input data-i="' + i + '" data-f="group" value="' + esc(s.group || '') + '" placeholder="分组">';
        subsEditor.appendChild(row);
      });
    });
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const subs = Array.from(subsEditor.querySelectorAll('.sub-row')).map(function (row) {
      const o = {};
      row.querySelectorAll('input').forEach(function (inp) {
        const f = inp.dataset.f;
        if (f === 'port') o[f] = inp.value ? parseInt(inp.value, 10) : 0;
        else o[f] = inp.value.trim();
      });
      return o;
    });
    const payload = {
      config: {
        server_url: document.getElementById('cfg-server-url').value.trim(),
        poll_interval_seconds: parseInt(document.getElementById('cfg-poll').value, 10),
        default_port: parseInt(document.getElementById('cfg-port').value, 10),
        offline_consecutive: parseInt(document.getElementById('cfg-offline').value, 10),
        latency_high_ms: parseInt(document.getElementById('cfg-latency').value, 10),
        drop_ratio: parseFloat(document.getElementById('cfg-drop').value),
        drop_min_people: parseInt(document.getElementById('cfg-dropmin').value, 10),
        subscriptions_file: document.getElementById('cfg-server-url').value.trim() ? '' : '' // 保留原值
      },
      subscriptions: subs
    };
    // 从加载的 config 保留未编辑字段（webhook 等）。简便做法：GET 一次拿到全量再覆盖。
    fetch('/api/config').then(function (r) { return r.json(); }).then(function (d) {
      const full = d.config || {};
      Object.keys(payload.config).forEach(function (k) {
        if (payload.config[k] === undefined || payload.config[k] === '' && !isNaN(payload.config[k])) delete payload.config[k];
      });
      // 用全量 base 覆盖用户可编辑字段
      payload.config = Object.assign({}, full, payload.config);
      delete payload.config.webhook_token; // 永不上送
      return fetch('/api/config', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    }).then(function (r) { return r.json(); }).then(function (d) {
      msg.textContent = d.ok ? '已保存 ✓' : '保存失败: ' + d.error;
    });
  });
  load();
})();
```

> 说明：为避免过度复杂，`payload.config.subscriptions_file` 等继承字段在提交前由 `Object.assign(full, ...)` 补齐；只要最终 JSON 只包含 `Config` 的已知 json tag 字段即可。

- [ ] **步骤 5：`app.css` 补充配置页样式**

```css
/* ===== 配置 ===== */
#config-form label { display: block; margin-bottom: 10px; font-size: 13px; color: var(--text-muted); }
#config-form input { display: block; width: 100%; max-width: 340px; margin-top: 4px; border: 2px solid var(--border); padding: 8px 10px; font-family: var(--pixel); font-size: 14px; border-radius: 6px; }
#config-form h3 { margin: 16px 0 8px; }
#cfg-save { margin-top: 12px; border: 0; background: var(--nav-active); color: #1F2B12; padding: 9px 26px; font-family: var(--pixel); font-size: 14px; font-weight: 700; border-radius: 6px; cursor: pointer; }
#cfg-msg { margin-left: 12px; font-size: 13px; }
.sub-row { display: flex; gap: 6px; margin-bottom: 6px; }
.sub-row input { max-width: none; }
```

- [ ] **步骤 6：测试 `handleConfigGet` 与 `writeJSONFile`**

在 `client/ui_server_test.go` 追加：

```go
func TestHandleConfigGet(t *testing.T) {
	dir := t.TempDir()
	subsPath := filepath.Join(dir, "subs.json")
	os.WriteFile(subsPath, []byte(`{"servers":[{"host":"mc.hypixel.net","port":25565}]}`), 0644)
	cfg := defaultConfig()
	cfg.SubscriptionsFile = subsPath
	api := &uiAPI{cfg: cfg}

	req := httptest.NewRequest("GET", "/api/config", nil)
	rec := httptest.NewRecorder()
	api.handleConfigGet(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("期望200，得到 %d", rec.Code)
	}
	var resp struct {
		Config Config `json:"config"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}
	if resp.Config.WebhookToken != "" {
		t.Fatalf("config 不应回传 webhook_token")
	}
}

func TestWriteJSONFile(t *testing.T) {
	dir := t.TempDir()
	p := filepath.Join(dir, "c.json")
	if err := writeJSONFile(p, map[string]any{"a": 1}); err != nil {
		t.Fatal(err)
	}
	raw, _ := os.ReadFile(p)
	if string(raw) == "" {
		t.Fatal("文件为空")
	}
}
```

需要注意：`handleConfigGet` 调用 `LoadSubscriptions` 时若订阅文件不存在会返回 err（此处用 subsPath 指向真实文件，OK）。

- [ ] **步骤 7：运行测试 + 冒烟**

运行：`cd e:\In_development\mc-server-api\client; go test ./... -v; go build -o mcmon.exe .`
预期：`PASS`。「配置」视图加载出 config 字段与订阅行，修改 host 或阈值后点保存，重新加载应回显；`webhook_token` 永不显示。

- [ ] **步骤 8：Commit**

```bash
cd e:\In_development\mc-server-api\client
git add ui_handlers.go ui_server.go web/index.html web/assets/app.js web/assets/app.css ui_server_test.go
git commit -m "feat(client): G5 配置页 - 可视化编辑config与订阅清单(脱敏Token)"
```

---

## 任务 7：G6 打磨 — 空态/错误态/主题细节/文档

**文件：**
- 修改：`client/web/index.html`、`client/web/assets/app.js`、`client/web/assets/app.css`
- 修改：`client/config.example.json`
- 修改：`README.md`（追加客户端 GUI 使用说明，风格与既有中文 README 一致）

- [ ] **步骤 1：补空态/错误态 UI**

在 `app.css` 追加通用空态/错误态组件，并在各视图调用已存在的 `.err`。统一在 `index.html` 的 `#state-pill` 基础上，补充一个全局「数据源离线」横幅：

在 `index.html` 的 `<main>` 顶部插入：

```html
<main class="content">
  <div id="offline-banner" class="offline-banner hidden">数据源离线：无法连接本地服务</div>
  ...
```

`app.js` 末尾加：

```js
// 全局错误横幅
fetch('/api/state').catch(function () {
  const b = document.getElementById('offline-banner');
  if (b) b.classList.remove('hidden');
});
```

CSS：

```css
.offline-banner { background: #C0392B; color: #fff; padding: 8px 16px; border-radius: 6px; margin-bottom: 12px; }
```

现有 `.err` 已用于多数错误信息，无需重复造。

- [ ] **步骤 2：主题细节 — Fusion Pixel 字体接入**

客户端 `web/` 内自带一份像素字体（与主站一致）。复制 `e:\In_development\mc-server-api\public\assets\fusion-pixel.woff2` 到 `client/web/assets/fusion-pixel.woff2`，并在 `app.css` 顶部引入：

```css
@font-face {
  font-family: "Fusion Pixel";
  src: url("fusion-pixel.woff2") format("woff2");
  font-display: swap;
}
:root { --pixel: "Fusion Pixel", "PingFang SC", "Microsoft YaHei", sans-serif; }
```

> 若字体文件未复制，则回退到系统字体，不阻塞功能；本步骤以复制为准。

- [ ] **步骤 3：更新 `config.example.json` 增加 UI 键**

在 `webhook_cooldown_seconds` 后追加：

```json
  "ui_host": "127.0.0.1",
  "ui_port": 0
```

- [ ] **步骤 4：更新 README 客户端 GUI 章节**

在 `README.md` 的客户端说明处追加一段（中文，与既有风格一致，标题为 `mcmon 客户端 GUI（可视化控制台）`），说明：作用、启动方式（`mcmon --ui` / `--dev` / `--no-browser` / `--port`）、四个页面、数据边界（本地进程+远程只读 API）、测试命令。请在原有「监控订阅客户端」相关段落之后追加，不改动既有 MCP 章节结构。

- [ ] **步骤 5：运行完整测试 + 构建 + 冒烟**

运行：`cd e:\In_development\mc-server-api\client; go vet ./...; go test ./... -v; go build -o mcmon.exe .`
预期：全部 `PASS`，构建无错。`.\mcmon.exe --ui --dev --no-browser --port 9099` 全流程操作四个页面正常。

- [ ] **步骤 6：Commit**

```bash
cd e:\In_development\mc-server-api\client
git add web/ config.example.json README.md
git commit -m "chore(client): G6 打磨 - 空态/错误态/FusionPixel字体/README"
```

---

## UI 完整部署说明

- 开发期：`mcmon --ui --dev --no-browser --port 9099`
- 发布版：`mcmon --ui`（自动开浏览器，嵌入静态资源）

---

## 验证命令汇总

```bash
cd e:\In_development\mc-server-api\client
go vet ./...
go test ./... -v
go build -o mcmon.exe .
```

以上三步在每个任务后都应通过；提交前的完整验证在 G6 任务末尾统一执行。

## 自检记录

1. **规格覆盖度**：规格各章——视觉(§2)、架构(§3 双模式/零依赖)、页面结构(§4, 四个页面)、数据流(§5, 各转向)、错误处理(§6)、测试(§7)——分别由任务1(架构/双模式)、任务2(视觉/骨架)、任务3(搜索/数据流)、任务4(监控面板)、任务5(统计)、任务6(配置)、任务7(错误态/打磨/文档) 覆盖。§6 的「端口占用自动顺延」在任务1 的 `listenWithRetry` 中；「配置校验不覆盖原文件」在任务6 中；「数据源离线占位」在任务7。✅
2. **占位符扫描**：无「待定/TODO/后续实现」；每个代码步骤均有完整代码与命令。✅
3. **类型一致性**：`fetchBatch`/`batchServer`/`batchResult`/`Snapshot`/`uiAPI`/`runWebUI`/`handlePing`/`handleBatch`/`handleMonitorPanel`/`handleStats`/`handleConfigGet`/`handleConfigPut` 均贯穿前后任务且签名一致；`Config` 新增 `UIHost`/`UIPort` 与 json tag 对应 `config.example.json` 的 `ui_host`/`ui_port`。`runWebUI` 签名在任务1 与任务6 中被正确更新为带 `cfgPath`。✅

> 注：本计划为满足「面向 AI 代理」的可执行性，已将每个里程碑写成独立任务；但前端 `app.js`/`app.css` 会在多个任务中持续追加代码块，因此这些文件在 G2–G6 属于**增量修改**而非重建，任务中标注为「追加」以确保工程师不覆盖既有代码。