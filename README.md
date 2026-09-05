# Minecraft 服务器状态查询 API 使用指南

一款基于 Minecraft 服务器列表 Ping 协议开发的状态查询工具。它通过 TCP 连接直接与目标服务器对话，无需安装任何服务端插件或模组，即可获取在线人数、玩家列表、MOTD、服务器图标、版本和核心等信息。整个项目基于 PHP 8.3 标准库实现，零框架依赖，开箱即用。

> 注：本文档面向开发者+使用者，若仅需使用API，则可以直接通过**目录**跳转至[API 接口说明](#api-接口说明)章节

## 项目亮点

* **双协议兼容**：支持 1.7+ 现代状态协议，并内置 legacy 0xFE 传统协议作为自动降级方案，兼容 1.4\~1.6 老版本服务器

* **精美 MOTD 渲染**：自动解析传统 § 颜色代码（含 §x RGB 渐变）和 Chat Component 对象，并将 MOTD 转换为彩色 HTML（`motd.html` 字段），支持 16 色、粗体、斜体、下划线、删除线等样式，所有文本均已做 XSS 转义

* **favicon 一键获取**：自动将 Base64 编码的服务器图标解码保存为 PNG 文件，并提供 HTTP 访问接口

* **智能识别**：自动识别服务器核心（Paper / Spigot / Purpur / Fabric / Vanilla / Forge 等）

* **稳定可靠**：默认 5 秒超时、2MB 响应上限，防止恶意超大包拖垮进程

* **自动 SRV 解析**：支持通过 `_minecraft._tcp.<host>` 查询 SRV 记录，自动解析外置登录服务器等非常规端口

* **高效缓存**：进程内 TTL 缓存，重复查询秒级响应，降低目标服务器压力

* **批量并发查询**：基于非阻塞 socket + stream\_select，一次可并发查询多台服务器

* **可用性监控**：内置 SQLite 存储历史查询记录，支持在线率、延迟趋势分析

* **头像代理**：根据 UUID 自动抓取并缓存玩家头像，支持外置登录服自定义皮肤站

* **安全防护**：固定窗口限流 + API Key 鉴权，防止恶意调用

* **状态统计**：提供 `/metrics` 接口，方便接入监控告警系统，浏览器打开还能看到高颜值数据面板

* **可视化状态页**：`/health` 和 `/metrics` 在浏览器中直接访问时会展示精美的 HTML 页面，API 调用时仍保持 JSON 返回格式

* **Docker 一键部署**：提供 Dockerfile 和 docker-compose.yml，环境变量即可完成配置

* **统一响应格式**：规范的 JSON 返回结构，附带错误码和中文错误信息

* **网络协议支持**：以 IPv4 为主，兼容 IPv6（裸 IPv6 地址自动补方括号）

* **MC 客户端式列表**：主页查询结果按 Minecraft 客户端「多人游戏」样式排版（方形图标框 + 标题 + 人数右对齐 + 延迟靠后，MOTD 独立框）

* **收藏夹**：常用服务器一键收藏，浏览器 `localStorage` 持久化，批量面板可点击查询

* **自动刷新**：可选定时刷新（15 / 30 / 60 秒）自动重查，页面不可见时自动跳过，便于盯守服务器状态

* **分享链接**：单查 / 批量可一键生成直达查询链接（`?host=` / `?servers=`），方便传播分享

* **CSV 导出**：批量查询结果一键导出为 CSV 文件（UTF-8 带 BOM）

* **在线人数趋势图**：基于 SQLite 监控历史数据绘制纯 SVG 折线图，无需任何第三方图表库

* **Webhook 状态通知**：服务器在线 / 离线状态变化时推送飞书 / 钉钉 / 企业微信，可配置防抖防刷屏

* **MCP 集成**：内置 MCP（Model Context Protocol）服务器，支持 Claude Desktop / Cursor 等 AI 客户端本机直接调用查询能力

## 环境要求

* PHP 8.1+（推荐 8.3），需启用 mbstring / iconv 扩展（缺失时自动回退到手工解码模式）

* 无需 Composer、无需额外扩展（stream 套接字为 PHP 内置能力）

## 快速开始

使用 PHP 内置服务器（开发或演示环境）：

```bash
cd C:/your/path/to/mc-server-api
php -S 0.0.0.0:8080 public/index.php
```

> **注意**：必须以 `public/index.php` 作为路由器脚本启动。PHP 内置服务器对带扩展名的路径（如 `/favicon/xxx.png`、`/api/ping/example.com`）会按静态文件处理并直接返回 404，指定路由器脚本后所有请求才会进入前端控制器。

启动后，访问 `http://127.0.0.1:8080/` 即可打开在线查询工具，或访问 `http://127.0.0.1:8080/docs` 查看本文档的渲染页面。

若已部署到生产环境，访问 `https://mcpc.goldenapplepie.xyz/mcstatus/` （本人的，你可以直接使用）即可打开在线查询工具，或访问 `https://mcpc.goldenapplepie.xyz/mcstatus/docs` 查看本文档的渲染页面。

## 网页在线工具

项目内置两个纯前端页面（原生 HTML/CSS/JS，无 npm、无 CDN、无构建依赖）：

### 主页 `/`（在线查询门户）

* **顶部导航**：工具 / 文档 / 指标 / 健康状态徽章一应俱全

* **工具面板**：支持单台查询和批量查询两种模式自由切换

* **特性展示**：精心设计的特性网格，展示项目亮点

* **快速上手**：附带复制按钮的代码示例，方便直接调用 API

**单台查询模式**：输入服务器地址与端口，点击查询即可获得：

* 彩色 MOTD 渲染 + 服务器图标

* 在线/最大人数、版本/协议号/核心

* 延迟色阶指示（<50ms 绿 / <150ms 黄 / >=150ms 红）

* 玩家列表（含 UUID 与头像）

* SRV / Secure Chat / 协议徽章

**批量查询模式**：每行一个地址（支持 `host` 或 `host:port`，裸 IPv6 与 `[IPv6]:port` 均可），一次提交即可查看所有服务器状态。

**交互细节**：加载中展示骨架屏；错误响应（HTTP 错误与业务错误码）以红色错误卡友好展示；除服务端已转义的 `motd.html` 外，所有动态文本均通过 `textContent` / DOM API 赋值，从根源杜绝 XSS。

**增强交互**：

* **MC 客户端式列表**：结果按 Minecraft「多人游戏」样式展示，每行含方形图标框、`核心+版本` 标题、右对齐的 `人数/最大` 与延迟、独立的彩色 MOTD 框

* **收藏夹**：单查结果卡「收藏」按钮 + 批量面板的收藏栏（浏览器 `localStorage` 持久化，点击即查、可清空）

* **自动刷新**：工具栏下拉可选 15 / 30 / 60 秒，自动重查最后一次结果，页面不可见时跳过

* **分享链接**：结果卡「复制分享链接」生成 `?host=` / `?servers=` 直达地址，首页打开即自动查询

* **CSV 导出**：批量汇总条下方「导出 CSV」一键下载

* **在线人数趋势图**：单查结果卡「在线人数趋势」按 `/api/monitor` 历史数据绘制纯 SVG 折线图（离线点标红、显示峰值与时间范围；数据不足时给出友好提示）

### 文档页 `/docs`

由 `src/MarkdownRenderer.php`（轻量级 Markdown 渲染器，纯标准库实现）将本 README 渲染为排版精美的文档页面。支持标题锚点、目录（TOC）、表格斑马纹、代码块（带语言标注与复制按钮）、行内代码、引用块、链接等，未支持的语法按原样文本输出。

### 指标页 `/metrics`

浏览器直接访问（`Accept: text/html`）或带 `?format=html` 时，返回状态统计美化 HTML 视图：导航栏 + gauge 大数字卡 + counter 表格/徽章 + uptime 可读时长 + 5 秒自动刷新开关。如需原始数据，可通过 `?format=raw` 获取标准文本格式。

### 健康页 `/health`

浏览器直接访问或带 `?format=html` 时，返回服务状态美化 HTML 状态卡（服务名 / PHP 版本 / Asia/Shanghai 本地时间 / 导航链接）；程序调用则保持 JSON 格式输出。

前端静态资源位于 `public/assets/app.css`（CSS 变量主题，深色石板 + 草绿 #7CB342）和 `public/assets/app.js`（原生 fetch + DOM，模块化函数）。主页/文档页的 UI 文案可在 `config/config.php` 的 `ui` 段进行定制（标题 / 描述 / 示例地址 / 版本徽章）。

### Apache 部署

将项目部署到 Web 根目录，`public/.htaccess` 已包含重写规则（所有请求交给 `public/index.php`）。注意仅将 `public/` 作为文档根目录，避免 src/config/tests 被直接访问。

### Nginx 部署

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/mc-server-api/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass 127.0.0.1:9000;
    }
}
```

## Webhook 离线通知

服务器**状态发生变化**（在线 ↔ 离线）时，自动向配置的 Webhook 地址推送一条消息，用于接入飞书 / 钉钉 / 企业微信群机器人做停机告警。

* 使用 `PingClient` 时在 `handlePing`（单查）与 `handleBatch`（批量）中自动触发，无需额外调用

* 支持平台：`feishu`（飞书）、`dingtalk`（钉钉，可配加签密钥）、`wecom`（企业微信）、`generic`（任意支持 POST JSON 的地址）

* **防抖**：同一台服务器两次状态变化通知至少间隔 `cooldown_seconds`（默认 300 秒），避免网络抖动导致刷屏

* **上线/离线通知可独立开关**：`notify_online`（默认 true）控制是否在服务器「上线」时也通知

* 状态快照持久化到 `state_file`，跨请求生效（Windows 内置服务器每请求独立进程时同样有效）

* 默认 `enabled=false`，需在 `config/config.php` 的 `webhook` 段开启并填入 `url` 后生效

示例配置：

```php
'webhook' => [
    'enabled' => true,
    'url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/xxxxx', // 机器人 Webhook 地址
    'platform' => 'feishu',        // feishu | dingtalk | wecom | generic
    'secret' => '',                // 钉钉加签密钥（仅钉钉需要，留空不签名）
    'notify_online' => true,       // 服务器上线时是否也通知
    'cooldown_seconds' => 300,     // 状态变化通知的最短间隔（秒）
    'state_file' => dirname(__DIR__) . '/data/webhook_state.json',
],
```

## MCP 集成

项目内置 MCP（Model Context Protocol）能力，让 Codex、Claude Desktop、Cursor 等支持 MCP 的客户端直接调用本项目查询能力，**两种传输共用同一套工具与查询逻辑**（`McpCore`）：

* **本地 stdio**：客户端本机跑 `mcp-server.php`（无需网络、无需 PHP 之外依赖）

* **远程 Streamable HTTP**：通过 `public/index.php` 的 `mcp` 路由（如 `https://mcpc.goldenapplepie.xyz/mcstatus/mcp`）提供，带 Bearer 鉴权，客户端**无需安装 PHP**，Codex 等可直接指向 URL

> **测试阶段（Beta）**：MCP 能力目前处于**实验性开发阶段**，工具清单、参数与返回值可能随版本调整，请勿将其作为生产核心链路的关键依赖；使用中遇到问题欢迎反馈。

**面向开发 / 集成场景的功能**：

* 让 AI 编码助手（Codex、Claude Desktop、Cursor 等）在对话中直接查询 Minecraft 服务器状态，无需手写 HTTP 请求

* 供脚本、工具按 MCP 标准接入，复用 `ping_server` / `ping_batch`，得到与 HTTP API 同构的查询结果

* 本地 stdio 与远程 Streamable HTTP 两种传输共用同一 `McpCore`，工具集与行为始终一致

注册的工具：

| 工具            | 说明                                                                 |
| ------------- | ------------------------------------------------------------------ |
| `ping_server` | 单台查询服务器状态：在线状态、在线/最大人数、MOTD、版本与协议号、核心、延迟、favicon、SRV / Secure Chat |
| `ping_batch`  | 并发批量查询多台（受 `batch_max_servers` 上限约束，默认 20），单台失败不影响其余               |

**方式一：本地 stdio**（Claude Desktop / Cursor 等本机客户端；`command` + `args` 绝对路径请按实际调整）：

```json
{
  "mcpServers": {
    "mc-status": {
      "command": "php",
      "args": ["E:/In_development/mc-server-api/mcp-server.php"]
    }
  }
}
```

**方式二：远程 Streamable HTTP**（推荐用于生产 / Codex；客户端无需安装 PHP）。先在生产配置启用端点并设置令牌：

```php
'mcp' => [
    'enabled'       => true,                              // 关闭时 /mcp 恒返回 404
    'bearer_token'  => '<随机长字符串>',                   // 客户端须带 Authorization: Bearer <token>
    'sse_max_seconds' => 600,                            // GET SSE 心跳最长存续秒数
],
```

端点 URL 即 `base_path` 拼接 `mcp`：`https://mcpc.goldenapplepie.xyz/mcstatus/mcp`。Codex CLI 远程注册（或直接写进 `~/.codex/config.toml`）：

```
codex mcp add mc-status --url https://mcpc.goldenapplepie.xyz/mcstatus/mcp --bearer-token-env-var MCAPI_MCP_TOKEN
```

```toml
# ~/.codex/config.toml
experimental_use_rmcp_client = true

[mcp_servers.mc-status]
url = "https://mcpc.goldenapplepie.xyz/mcstatus/mcp"
bearer_token_env_var = "MCAPI_MCP_TOKEN"
```

> 令牌可通过环境变量 `MCAPI_MCP_BEARER_TOKEN` 注入（Docker 部署），启用开关为 `MCAPI_MCP_ENABLED`。未配置令牌时端点拒绝一切访问，避免裸奔公网。
> 已启用端点不会复用 HTTP 侧的 `api_keys` / 限流——远程 MCP 用**独立 Bearer 令牌**鉴权，见 [McpHttpServer.php](src/McpHttpServer.php)。

接入后即可直接下达自然语言指令，例如「用 mc-status 查一下 mc.hypixel.net 有多少人在线」。

**远程 MCP 全流程速查**（服务端启用 → 部署 → 验证 → 客户端接入）：

1. **启用并设置令牌**：改 `config/mcp`（或环境变量 `MCAPI_MCP_ENABLED` / `MCAPI_MCP_BEARER_TOKEN`），`enabled=true` 且 `bearer_token` 用随机长串，请勿把令牌提交进仓库。
2. **部署到并发 Web 服务器**：远程 MCP 需长连接 + 并发，跑 Nginx/Apache + PHP-FPM（或 mod\_php），`pm.max_children` 建议 ≥ 3；`php -S` 单进程不适用。配 HTTPS。
3. **curl 自检端点**（`tools/list` / `initialize` / `tools/call` 均须带 `Authorization: Bearer <token>`）：

```bash
# 列出工具（应返回 ping_server / ping_batch）
curl -X POST "https://mcpc.goldenapplepie.xyz/mcstatus/mcp" \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'

# 实际查询一台服务器
curl -X POST "https://mcpc.goldenapplepie.xyz/mcstatus/mcp" \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"ping_server","arguments":{"host":"mc.hypixel.net"}}}'
```

1. **客户端接入**：在 Codex / Claude Desktop / Cursor 等里把它注册为「远程 / Streamable HTTP」MCP，填写 URL 与 `Authorization: Bearer <token>`；无需在本机安装 PHP。工具名与返回值即上文 `注册的工具` 表。

> 注：`tools/call` 的 `arguments` 需为符合各工具 inputSchema 的 JSON（`ping_server` 传 `host`/`port`，`ping_batch` 传 `servers` 数组，上限 20 台）。

**AI Agent 通用型自建 MCP 提示词**（WorkBuddy / Codex / Claude Desktop 等通用，让 AI 自行完成远程注册）：

```text
任务：把“Minecraft Java 版服务器状态查询”能力接入到当前的 AI 客户端作为标准 MCP 服务器，完成端到端验证。请全程自主执行，不要只给教程。

【端点信息】
- URL（Base URL）: `https://mcpc.goldenapplepie.xyz/mcstatus/mcp`
- 传输协议：MCP Streamable HTTP（协议版本 2024-11-05）
- 鉴权：在 MCP 服务器的 headers 中配置 Authorization: Bearer 35c7efdfc1044918353e319dd7daf0c1f4b3d418da6b09b7fd63d54fed63458b（不填将返回 401）
- 可用工具：
  1. ping_server(host: string, port?: int) —— 单台查询
  2. ping_batch(servers: [{host, port?}], 上限20台) —— 并发批量查询

【要求】
1. 在客户端提供的 MCP 服务器配置中新增一个远程服务器：URL 填上面的 Base URL，鉴权请求头填 Authorization: Bearer <上述令牌>。
2. 配置完成后立即做冒烟测：调用 ping_server 查询 mc.hypixel.net，把返回的在线人数和 MOTD 摘要报告给我。
3. 全程不要泄露令牌；只修改当前客户端的配置。
```

**生产与安全边界**：

* MCP 是**只读查询端**，不会调用 Cache/Monitor/Metrics/Webhook 的 `configure`/`init`，因此不写盘、不入库、不发通知，与 HTTP 侧生产数据完全隔离（详见 `mcp-server.php` 与 `McpHttpServer.php` 头部注释）

* **远程端点**：须启用 `mcp.bearer_token` 并配 HTTPS；`GET` 的 SSE 心跳会持续占用一个 PHP 工作进程且 `POST` 须并发处理，故**必须运行在支持并发的 Web 服务器**（Nginx/Apache + PHP-FPM 或 mod\_php，`pm.max_children` 建议 ≥ 3）——PHP 内置单进程服务器（`php -S`）不适合远程 MCP

* **本地 stdio**：通过客户端本机子进程通信，无鉴权，仅作本机使用，切勿把 `mcp-server.php` 当网络服务暴露

* 调试请用 `error_log` 或 `fwrite(STDERR, ...)`，禁止 `echo` 输出（会破坏 MCP 协议帧）

## API 接口说明

### 统一响应格式

所有 API 接口均返回统一格式的 JSON：

```json
{
  "success": true,
  "code": 0,
  "message": "成功",
  "data": { }
}
```

### 错误码速查表

| code | 含义                           |
| ---- | ---------------------------- |
| 0    | 成功                           |
| 1001 | 参数无效                         |
| 1002 | 连接超时                         |
| 1003 | 连接被拒绝                        |
| 1004 | DNS 解析失败                     |
| 1005 | 协议错误 / 响应异常                  |
| 1006 | 服务器离线                        |
| 1007 | 请求过于频繁（限流，HTTP 429）          |
| 1008 | API Key 无效 / 缺失（鉴权，HTTP 401） |
| 1009 | 批量参数无效（HTTP 400）             |

### 1. 查询服务器状态

```
GET /api/ping?host=xxx&port=25565
GET /api/ping/{host}?port=25565
```

| 参数   | 必填 | 说明                                   |
| ---- | -- | ------------------------------------ |
| host | 是  | 服务器地址（域名 / IPv4 / IPv6），长度不超过 255 字符 |
| port | 否  | 端口，1-65535，默认 25565                  |

**示例**：

本地环境示例（端口 8080）：

```bash
curl "http://127.0.0.1:8080/api/ping?host=mc.hypixel.net&port=25565"
curl "http://127.0.0.1:8080/api/ping/mc.hypixel.net?port=25565"
```

已部署生产环境示例：

```bash
curl "https://mcpc.goldenapplepie.xyz/mcstatus/api/ping?host=mc.hypixel.net&port=25565"
curl "https://mcpc.goldenapplepie.xyz/mcstatus/api/ping/mc.hypixel.net?port=25565"
```

**成功响应 data 结构**：

```json
{
  "online": true,
  "host": "mc.hypixel.net",
  "port": 25565,
  "latency_ms": 42,
  "version": {
    "name": "Paper 1.20.4",
    "protocol": 765,
    "brand": "Paper"
  },
  "players": {
    "online": 1234,
    "max": 2000,
    "sample": [
      { "name": "Steve", "uuid": "069a79f4-44e9-4726-a5be-fca90e38aaf5" }
    ]
  },
  "motd": {
    "raw": "{\"text\":\"A Minecraft Server\"}",
    "plain_text": "A Minecraft Server",
    "has_legacy_codes": false,
    "html": "A Minecraft Server"
  },
  "favicon": {
    "base64": "iVBORw0KGgo...",
    "saved_path": "C:/your/path/to/mc-server-api/favicons/ab12....png",
    "url": "/favicon/ab12....png"
  },
  "protocol_used": "modern",
  "secure_chat": {
    "enforces": true,
    "previews": false
  },
  "srv_used": false,
  "srv_record": null
}
```

**字段说明**：

* `online`：服务器是否在线（false 时 data 中其余字段多为 null）

* `latency_ms`：Ping/Pong 往返延迟（毫秒）；无法测量时为 null

* `version.name`：版本名称（如 "Paper 1.20.4"）

* `version.protocol`：协议版本号

* `version.brand`：核心识别结果（基于 version.name 与 MOTD 尽力识别，仅供参考）

* `players.sample`：服务器提供的玩家样例列表（含 UUID）；无则为 null

* `motd.raw`：原始 MOTD（字符串或 Chat Component 对象）

* `motd.plain_text`：去除所有控制码后的纯文本

* `motd.has_legacy_codes`：是否包含 § 传统控制码

* `motd.html`：彩色 HTML（16 色/格式码/§x 渐变/Chat Component 递归渲染，全文本已 XSS 转义）

* `cached`：仅当命中缓存时存在（true），表示本次结果直接来自进程内缓存

* `favicon.base64`：去掉 data URI 前缀的 Base64 PNG

* `favicon.url`：可通过 `GET /favicon/{token}.png` 直接访问的图片地址

* `protocol_used`：本次查询实际使用的协议（modern / legacy）

* `secure_chat`：1.19+ 可选字段（enforcesSecureChat / previewsChat）

* `srv_used`：本次查询是否经由 SRV 记录自动解析（true 表示按 SRV 目标主机+端口重试成功）

* `srv_record`：命中的 SRV 记录信息 `{ "target": "...", "port": 11822 }`；未使用 SRV 时为 null

**失败响应示例**（参数缺失）：

```json
{
  "success": false,
  "code": 1001,
  "message": "host 必填且不能为空",
  "data": {
    "online": false,
    "host": "",
    "port": 0
  }
}
```

### 2. favicon 获取接口

```
GET /favicon/{token}.png
```

* `token` 为 64 位十六进制（由 `sha256(host:port)` 生成），已做格式校验，防止目录穿越

* 返回 `Content-Type: image/png` 的原始 PNG 图片

* 同一服务器多次查询会覆盖同名文件；favicons 目录由代码自动创建

### 3. 健康检查接口

```
GET /health
GET /api/health
```

返回服务名称、PHP 版本与当前时间。该接口不参与限流与鉴权，方便监控探针使用。

**浏览器美化页**：浏览器直接打开 `/health`（请求头 `Accept: text/html`）或带 `?format=html` 参数时，返回与主页同主题的美化 HTML 状态卡（服务名、PHP 版本、Asia/Shanghai 本地时间、导航链接）；其余请求（curl、程序调用）保持 JSON 结构不变，`time` 字段仍为 `date('c')` 格式，接口契约零破坏。

### 4. 批量并发查询接口

```
POST /api/ping/batch
GET  /api/ping/batch?servers=[{"host":".."},...]
```

**请求体**（POST，JSON）：

```json
{
  "servers": [
    { "host": "mc.goldenapplepie.xyz", "port": 25565 },
    { "host": "mc.eqmemory.cn" },
    { "host": "unreachable.example.com" }
  ]
}
```

* `port` 可选，默认 25565

* 单次最多查询 `batch_max_servers` 台服务器（默认 20），超过返回 1009

* 所有服务器通过非阻塞 socket 并发查询，总耗时受 `batch_timeout_seconds`（默认 10 秒）预算约束

* 单台失败不影响其他台：失败项 `online=false` 并附带 `error: { code, message }`

* 支持 SRV 自动兜底（与单查行为一致）；命中缓存的结果带 `cached: true`

**响应 data 结构**：

```json
{
  "total": 3,
  "success": 2,
  "failed": 1,
  "results": [ { "online": true, ... }, { "online": false, "error": { "code": 1003, "message": "连接被拒绝：..." } } ]
}
```

**示例**：

本地环境示例（端口 8080）：

```bash
curl -X POST "http://127.0.0.1:8080/api/ping/batch" \
  -H "Content-Type: application/json" \
  -d '{"servers":[{"host":"mc.goldenapplepie.xyz"},{"host":"mc.eqmemory.cn"},{"host":"127.0.0.1","port":1}]}'
```

已部署生产环境示例：

```bash
curl -X POST "https://mcpc.goldenapplepie.xyz/mcstatus/api/ping/batch" \
  -H "Content-Type: application/json" \
  -d '{"servers":[{"host":"mc.goldenapplepie.xyz"},{"host":"mc.eqmemory.cn"},{"host":"xxxxx","port":xxxx}]}'
```

### 5. SQLite 可用性监控记录

```
GET /api/monitor?host=xxx&limit=50
```

* `host` 必填（大小写不敏感匹配），缺失返回 1001

* `limit` 可选，默认 50，上限 200，按时间倒序返回

* 每次单查/批量查询（成功与失败）都会写入 `status_log` 表

* 需要 `pdo_sqlite` 扩展；缺失时监控自动禁用（记 error\_log，不影响查询主流程）

* 数据库文件默认 `data/monitor.sqlite`，目录自动创建；过期记录按 `monitor_history_days`（默认 30 天）自动清理

**响应 data 结构**：

```json
{
  "host": "mc.eqmemory.cn",
  "limit": 50,
  "count": 1,
  "records": [
    { "id": 1, "host": "mc.eqmemory.cn", "port": 25565, "online": 1, "latency_ms": 30,
      "online_players": 5, "max_players": 20, "protocol": 769, "protocol_used": "modern", "checked_at": 1784600000 }
  ]
}
```

### 6. 玩家头像代理接口

```
GET /avatar/{uuid}.png
```

* `uuid` 支持 32 位 hex 或标准 36 位带横杠格式（正则白名单），非法返回 400/1001

* 上游模板通过 `avatar_api_template` 配置（默认 `https://minotar.net/avatar/{uuid}.png`），`{uuid}` 会被自动替换

* 抓取结果本地缓存（`avatar_cache_dir`，默认 `avatars/`，文件名 `sha256(uuid).png`），TTL 默认 24 小时

* 上游失败统一返回 404 + JSON 错误（code 1001）

* **外置登录服须知**：minotar/crafatar 等官方头像站仅覆盖 Mojang 正版 UUID；使用 authlib-injector 等第三方 Yggdrasil 时，其 UUID 无对应皮肤。可将 `avatar_api_template` 指向支持外置登录 UUID 的皮肤站 API

**示例**：

本地环境示例（端口 8080）：

```bash
curl -o notch.png "http://127.0.0.1:8080/avatar/069a79f4-44e9-4726-a5be-fca90e38aaf5.png"
```

已部署生产环境示例：

```bash
curl -o notch.png "https://mcpc.goldenapplepie.xyz/mcstatus/avatar/069a79f4-44e9-4726-a5be-fca90e38aaf5.png"
```

### 7. 状态统计接口

```
GET /metrics
```

输出 JSON 返回格式（`Content-Type: text/plain; version=0.0.4`），包含以下指标：

* `http_requests_total{route}`：各路由累计请求数（/metrics 自身不计入，避免循环污染）

* `http_errors_total{code}`：按错误码累计的业务错误数

* `ping_success_total` / `ping_failure_total`：查询成功/失败次数

* `cache_hits_total` / `cache_misses_total`：缓存命中/未命中

* `uptime_seconds`：进程启动至今秒数

* `active_batch_requests`：进行中的批量查询数（gauge）

**输出决策优先级**（由高到低）：

1. `?format=raw` → 始终返回纯文本（状态统计兼容）
2. `?format=html` → 始终返回美化 HTML
3. `Accept: text/html`（浏览器）→ 美化 HTML
4. 其他（含 curl 默认 `Accept: */*`、状态统计）→ 保持纯文本

**示例**：

本地环境示例（端口 8080）：

```bash
curl "http://127.0.0.1:8080/metrics"                    # 纯文本（抓取）
curl -H "Accept: text/html" "http://127.0.0.1:8080/metrics"  # 美化 HTML
curl "http://127.0.0.1:8080/metrics?format=raw"         # 纯文本
curl "http://127.0.0.1:8080/metrics?format=html"        # 美化 HTML
```

已部署生产环境示例：

```bash
curl "https://mcpc.goldenapplepie.xyz/mcstatus/metrics"                    # 纯文本（抓取）
curl -H "Accept: text/html" "https://mcpc.goldenapplepie.xyz/mcstatus/metrics"  # 美化 HTML
curl "https://mcpc.goldenapplepie.xyz/mcstatus/metrics?format=raw"         # 纯文本
curl "https://mcpc.goldenapplepie.xyz/mcstatus/metrics?format=html"        # 美化 HTML
```

### 8. 限流与 API Key 鉴权

除公开页面与静态资源外（豁免范围见本节末尾），所有接口均参与以下安全机制：

* **限流**（`rate_limit_enabled`，默认 true）：按「客户端 IP + 路由」固定窗口分桶，每分钟上限 `rate_limit_per_minute`（默认 60）；超限返回 **HTTP 429 + code 1007**，响应头带 `X-RateLimit-Remaining` 与 `Retry-After`

* **API Key 鉴权**（`api_keys`，默认 `[]` 空 = 不启用）：非空时，请求必须携带 `X-API-Key` 请求头或 `?api_key=` 参数且值在列表中，否则返回 **HTTP 401 + code 1008**；携带合法 Key 的请求跳过 IP 维度限流

* **客户端 IP 判定**：默认取 `REMOTE_ADDR`（防止伪造）；仅当部署在可信反向代理之后时才应开启 `trust_proxy_headers`（此时优先取 `X-Forwarded-For` 第一个地址）

**豁免范围**：以下公开资源不参与鉴权与限流：

* `/` 主页在线工具与 `/docs` 文档页：纯展示页面、无敏感信息，保证启用 API Key 后在线工具门户仍可正常打开

* `/assets/*` 静态资源（app.css / app.js）：页面渲染所需，直接输出

* 仅 `/api/*`、`/favicon`、`/avatar`、`/metrics` 等数据接口参与鉴权与限流

## 核心协议实现说明

### 1.7+ 现代状态协议

所有数据包结构：`[varint 包长度][包内容]`，包内容首字节为包 ID。

1. **握手包**（0x00）：`varint 协议版本(-1) + 字符串(地址) + 无符号短整型(端口) + varint nextState(1)`
2. **状态请求包**（0x00）：空内容
3. **状态响应包**（0x00）：`varint 字符串长度 + JSON`
4. **Ping/Pong 包**（0x01）：8 字节随机负载，用于测量 RTT

### Legacy 0xFE 协议（1.4\~1.6）

1. 发送 `0xFE 0x01`
2. 响应：`0xFF + 无符号短整型长度 + UTF-16BE 内容`
3. 内容格式：`§1\0<协议版本>\0<版本名>\0<MOTD>\0<在线人数>\0<最大人数>`
4. 更老版本（<=1.3）：`§1<MOTD>§<在线人数>§<最大人数>`

legacy 协议无玩家列表与 favicon，相关字段返回 null。

### MOTD 解析

* **传统字符串**：去除所有 § 控制码（含 `§x§R§G§B§A§C` RGB 渐变），输出 plain\_text

* **Chat Component**：递归提取 text / extra / with / translate，数字与布尔 text 容错转字符串

* **彩色 HTML**：`motd.html` 字段按当前样式渲染 `<span style="...">`，支持 16 色映射、§x RGB 渐变（→ #RRGGBB）、加粗/斜体/下划线/删除线、§r 复位、§k 乱码（按普通文本）、Chat Component 的 color（颜色名或 #hex）与 bold/italic/underlined/strikethrough、extra 嵌套与样式继承、translate+with 展开；所有文本经 `htmlspecialchars(ENT_QUOTES, 'UTF-8')` 转义，防 XSS

### 自动降级机制

`PingClient` 先尝试现代协议，失败后自动尝试 legacy 协议；两者均失败时抛出现代协议的错误（带具体错误码），由 API 层转为统一 JSON 错误响应。

### SRV 自动解析

许多服务器（特别是外置登录服务器、BungeeCord 后端、高防前置等）通过 DNS SRV 记录把 `_minecraft._tcp.<host>` 指向实际的 MC 端口（如 11822 / 11874），而非默认的 25565。行为约定如下：

* **未指定端口**（使用默认 25565）且查询失败（超时/拒绝/协议错误等非参数错误）时，自动解析 `_minecraft._tcp.<host>` 的 SRV 记录，并按 SRV 目标主机+端口重试一次

* **显式指定端口**时以直接查询优先（尊重用户指定），仅当显式端口查询失败时也做 SRV 兜底

* **SRV 命中**时重试成功，返回数据中 `srv_used` 为 true、`srv_record` 携带 `{ target, port }`；SRV 目标为空或与原 host 相同时仍使用原 host

* **SRV 查询失败**、无记录或重试仍失败时，保持原行为（返回第一次查询的原始错误）

**示例**（SRV 命中后返回）：

```json
{
  "success": true,
  "code": 0,
  "message": "成功",
  "data": {
    "online": true,
    "host": "gapmc.goldenapplepie.xyz",
    "port": 11874,
    "srv_used": true,
    "srv_record": { "target": "gapmc.goldenapplepie.xyz", "port": 11874 }
  }
}
```

可通过 `config/config.php` 的 `srv_auto_resolve` 关闭该功能。

## 配置说明

所有配置均集中在 `config/config.php` 中，详见下表：

| 配置项                       | 默认值                                     | 说明                                                      |
| ------------------------- | --------------------------------------- | ------------------------------------------------------- |
| timeout\_seconds          | 5.0                                     | 连接与读写超时（秒）；含降级最坏约 2 倍耗时                                 |
| max\_packet\_bytes        | 2097152                                 | 单次响应最大字节数（2MB）                                          |
| default\_port             | 25565                                   | 默认端口                                                    |
| host\_max\_length         | 255                                     | host 最大长度                                               |
| favicon\_dir              | ./favicons                              | favicon 保存目录（自动创建）                                      |
| favicon\_url\_prefix      | /favicon                                | favicon URL 前缀                                          |
| modern\_enabled           | true                                    | 是否启用现代协议                                                |
| legacy\_enabled           | true                                    | 是否启用 legacy 降级                                          |
| srv\_auto\_resolve        | true                                    | 是否自动解析 `_minecraft._tcp` SRV 记录（失败时兜底重试）                |
| srv\_timeout              | 1.0                                     | SRV DNS 查询超时（秒）；dns\_get\_record 无超时参数，暂为预留项            |
| cache\_enabled            | true                                    | 是否启用进程内 TTL 缓存                                          |
| cache\_ttl\_seconds       | 30                                      | 缓存有效期（秒）                                                |
| cache\_file\_path         | ./data/cache.json                       | 缓存跨请求持久化文件（自动创建）                                        |
| batch\_max\_servers       | 20                                      | 单次批量查询最大服务器数                                            |
| batch\_timeout\_seconds   | 10.0                                    | 批量查询总时间预算（秒）                                            |
| monitor\_enabled          | true                                    | 是否启用 SQLite 可用性监控（pdo\_sqlite 缺失自动禁用）                   |
| monitor\_db\_path         | ./data/monitor.sqlite                   | 监控数据库路径（自动创建目录）                                         |
| monitor\_history\_days    | 30                                      | 历史记录保留天数                                                |
| avatar\_api\_template     | <https://minotar.net/avatar/{uuid}.png> | 头像上游模板（{uuid} 占位符）                                      |
| avatar\_cache\_dir        | ./avatars                               | 头像本地缓存目录（自动创建）                                          |
| avatar\_cache\_ttl\_hours | 24                                      | 头像缓存有效期（小时）                                             |
| avatar\_timeout\_seconds  | 5.0                                     | 头像上游抓取超时（秒）                                             |
| rate\_limit\_enabled      | true                                    | 是否启用限流                                                  |
| rate\_limit\_per\_minute  | 60                                      | 每 IP+路由 每分钟请求上限                                         |
| ratelimit\_file\_path     | ./data/ratelimit.json                   | 限流桶跨请求持久化文件（自动创建）                                       |
| api\_keys                 | \[]                                     | API Key 列表（空 = 不启用鉴权）                                   |
| trust\_proxy\_headers     | false                                   | 是否信任 X-Forwarded-For（仅可信代理后开启）                          |
| metrics\_file\_path       | ./data/metrics.json                     | 状态统计跨请求持久化文件（自动创建）                                      |
| metrics\_uptime\_file     | ./data/uptime.txt                       | 进程启动时间持久化文件（uptime\_seconds 用）                          |
| webhook.enabled           | false                                   | 是否启用 Webhook 状态变化通知                                     |
| webhook.url               | *(空)*                                   | 目标 Webhook 地址（飞书 / 钉钉 / 企业微信群机器人等）                      |
| webhook.platform          | generic                                 | 消息平台：feishu / dingtalk / wecom / generic                |
| webhook.secret            | *(空)*                                   | 钉钉加签密钥（仅钉钉需要，留空不签名）                                     |
| webhook.notify\_online    | true                                    | 服务器「上线」时是否也通知（false 时仅离线通知）                             |
| webhook.cooldown\_seconds | 300                                     | 同一台服务器两次状态变化通知的最短间隔（秒）                                  |
| webhook.state\_file       | ./data/webhook\_state.json              | 状态快照持久化文件（自动创建）                                         |
| mcp.enabled               | false                                   | 是否启用远程 MCP 端点（/mcp）                                     |
| mcp.bearer\_token         | *(空)*                                   | 远程 MCP 鉴权令牌（留空则端点拒绝一切访问；可用 `MCAPI_MCP_BEARER_TOKEN` 注入） |
| mcp.sse\_max\_seconds     | 600                                     | GET SSE 心跳最长存续秒数（0 不限时，不推荐）                             |

### 环境变量覆盖（Docker 部署）

`config/config.php` 支持以 `MCAPI_*` 环境变量覆盖配置项（值非空时生效，布尔值解析：`1/true/yes/on` 为 true；`0/false/no/off` 为 false）：

| 环境变量                             | 对应配置项                     | 类型          |
| -------------------------------- | ------------------------- | ----------- |
| MCAPI\_TIMEOUT\_SECONDS          | timeout\_seconds          | float       |
| MCAPI\_CACHE\_ENABLED            | cache\_enabled            | bool        |
| MCAPI\_CACHE\_TTL\_SECONDS       | cache\_ttl\_seconds       | int         |
| MCAPI\_BATCH\_MAX\_SERVERS       | batch\_max\_servers       | int         |
| MCAPI\_BATCH\_TIMEOUT\_SECONDS   | batch\_timeout\_seconds   | float       |
| MCAPI\_MONITOR\_ENABLED          | monitor\_enabled          | bool        |
| MCAPI\_MONITOR\_DB\_PATH         | monitor\_db\_path         | string      |
| MCAPI\_MONITOR\_HISTORY\_DAYS    | monitor\_history\_days    | int         |
| MCAPI\_AVATAR\_API\_TEMPLATE     | avatar\_api\_template     | string      |
| MCAPI\_AVATAR\_CACHE\_DIR        | avatar\_cache\_dir        | string      |
| MCAPI\_AVATAR\_CACHE\_TTL\_HOURS | avatar\_cache\_ttl\_hours | int         |
| MCAPI\_RATE\_LIMIT\_ENABLED      | rate\_limit\_enabled      | bool        |
| MCAPI\_RATE\_LIMIT\_PER\_MINUTE  | rate\_limit\_per\_minute  | int         |
| MCAPI\_API\_KEYS                 | api\_keys                 | array（逗号分隔） |
| MCAPI\_TRUST\_PROXY\_HEADERS     | trust\_proxy\_headers     | bool        |
| MCAPI\_SRV\_AUTO\_RESOLVE        | srv\_auto\_resolve        | bool        |
| MCAPI\_MCP\_ENABLED              | mcp.enabled               | bool        |
| MCAPI\_MCP\_BEARER\_TOKEN        | mcp.bearer\_token         | string      |

### Docker 部署

**示例**：
本地环境示例（端口 8080）：

```bash
cd C:/Users/Cory/WorkBuddy/mc-server-api
docker compose up -d --build
curl "http://127.0.0.1:8080/api/ping?host=mc.goldenapplepie.xyz"
```

* **Dockerfile**：`php:8.3-cli-alpine` + 安装 pdo\_sqlite + `php -S 0.0.0.0:8080 -t public`

* **docker-compose.yml**：服务名 `mc-status-api`，端口映射 `8080:8080`，`data/favicons/avatars` 目录挂载持久化卷，环境变量可覆盖部分配置

* **.dockerignore**：排除 tests/data/avatars/favicons 等运行期与开发文件

## mcmon 客户端 GUI（可视化控制台）

`client/` 目录内是用 Go 编写的独立监控订阅客户端 `mcmon`，编译为单个可执行文件（Windows `.exe` / macOS / Linux），零运行时依赖、无需安装额外环境。双击启动即可弹出桌面原生客户端（内嵌 WebView2 渲染本地 Web 控制台），无需浏览器；也可用命令行做持续采样监控。

### 作用

* 本地订阅管理（每台服务器 host/port/别名/分组）

* 定时抽样（复用主站只读 API `fetchBatch`，顺带在本地 `snapshots/` 落盘快照，并把历史采样追加到 `snapshots/history/`）

* 规则检测（离线/延迟/人数骤降）与 Webhook 机械报告

* 单查卡片流、批查 MC 列表行（自动解析服务器图标与彩色 MOTD）、实时监控面板、数据统计趋势图、告警事件日志、配置可视化编辑

* **8 个 MC 风格主题预设**（草绿泥土 / 海洋蓝 / 沙漠金亮色；末地紫黑 / 下界红黑 / 钴蓝工业 / 黑曜石紫 / 深板岩洞穴深色），支持跟随系统深浅自动切换

* **数据持久化 + 上限裁剪**：历史快照（默认 500 条/服）、趋势图点数（默认 200）、事件日志（默认 200 条），全可在配置页调

* **结构化日志**：`snapshots/log/mcmon.log`（1MB 文件 × 5 个轮转），INFO+ 同时输出到终端

* **双重 panic recovery**：单轮采样炸了不退出，整协程炸了进程仍在，日志里有堆栈

* **原子写入**：所有落盘操作（快照 / 历史裁剪 / 事件裁剪 / 清空）走 tmp+rename，崩溃不留半截文件

* **配置热重载**：改配置页保存后即时生效，无需重启

> 数据边界：所有订阅/轮询/规则/快照业务逻辑都在本地进程中，远程主站仅作只读数据源；配置与 Token 只驻留本机，`webhook_token` 在浏览器与 API 回传中一律脱敏。

### 启动方式

```bash
mcmon                   # 双击 / 无参数：直接打开桌面原生窗口（WebView2），关闭窗口即退出
mcmon --web             # 改用系统浏览器打开控制台
mcmon --run             # 命令行持续采样监控（按 Ctrl+C 退出）
```

开发场景（前端热改无需重编译）：

```bash
mcmon --ui --dev --no-browser --port 9099
```

参数：

* `--ui`：显式启动 Web 控制台（默认行为，双击 exe 即为其简写）

* `--desktop`：用桌面原生窗口（WebView2）承载 UI（可覆盖 config `ui_window`）

* `--web`：用外部浏览器打开 UI（可覆盖 config `ui_window`）

* `--run`：强制命令行采样/监控模式（脚本 / 定时任务用）

* `--dev`：前端读本地 `web/` 目录（改前端即时生效，需配合 `--ui`）

* `--no-browser`：启动后不自动打开浏览器/窗口

* `--port`：指定端口（0 表示随机；默认取配置 `ui_port`，占用会自动顺延）

* `--nopause`：命令行退出前不等待按键（便于脚本化）

### 五个页面

| 页面     | 说明                                                   |
| ------ | ---------------------------------------------------- |
| 可视化搜索  | 单查（卡片流）/ 批查（MC 列表行）两种模式，本地转发到主站查询，自动解析服务器图标与彩色 MOTD  |
| 订阅监控   | 每 30s 自动刷新订阅服务器的最近快照状态                               |
| 数据统计监测 | 基于本地历史快照聚合的汇总指标 + 纯 SVG 在线人数趋势图（离线点标红，在线率/平均/峰值一目了然） |
| 告警与事件  | 规则命中的告警事件 + 周期总结（可过滤「只看告警」、清空、15s 自动刷新）              |
| 配置     | 外观/基础设置/规则阈值/数据保留/告警事件/订阅清单，全可视化编辑，保存即时生效            |

### 主题预设

在配置页「外观」区块选择，保存后**即时切换**（无需刷新页面）：

| 预设                | 风格   | accent         |
| ----------------- | ---- | -------------- |
| `dirt` 草绿泥土       | 亮色默认 | `#7CB342` 草绿   |
| `ocean` 海洋蓝       | 亮色   | `#4FC3F7` 天蓝   |
| `sand` 沙漠金        | 亮色   | `#FDD835` 阳光黄  |
| `end` 末地紫黑        | 深色   | `#C39BD3` 末地紫  |
| `nether` 下界红黑     | 深色   | `#E74C3C` 下界红  |
| `cobalt` 钴蓝工业     | 深色   | `#4FC3F7` 亮钴蓝  |
| `obsidian` 黑曜石紫   | 深色   | `#7E57C2` 幽紫   |
| `deepslate` 深板岩洞穴 | 深色   | `#81C784` 苔绿微光 |

亮色预设可配合「跟随系统深浅」让 Windows 深色模式下自动翻成深色泥土风。

### 构建与测试

```bash
cd client
go vet ./...
go test ./... -v
go build -o mcmon.exe .
```

> 说明：默认 `config.json` 仅含少数键，`--ui` 启动时会用默认值兜底补齐。每次轮询采样会把快照落到本地 `snapshots/`，并把一条历史采样追加到 `snapshots/history/<key>.jsonl`（每台服务器默认最多保留 500 条，超出丢弃最旧），「数据统计监测」页即基于这些历史数据聚合趋势。

命令行采样模式（可配合 `--nopause` 用于脚本定时跑）：

```bash
mcmon --run          # 命令行持续采样（按 Ctrl+C 退出）
```

配置文件关键字段（`config.example.json` 里有完整示例）：

| 字段                              | 默认值   | 说明           |
| ------------------------------- | ----- | ------------ |
| `server_url`                    | —     | 主站只读 API 地址  |
| `poll_interval_seconds`         | 60    | 轮询间隔（秒）      |
| `offline_consecutive`           | 3     | 离线连续命中轮数     |
| `latency_high_ms`               | 300   | 延迟告警阈值（ms）   |
| `drop_ratio`                    | 0.7   | 人数骤降比例阈值     |
| `history_max_records`           | 500   | 历史快照上限（条/服）  |
| `series_max_points`             | 200   | 趋势图降采样上限     |
| `event_enabled`                 | true  | 事件落盘总开关      |
| `event_include_summary`         | false | 把周期总结也写入事件列表 |
| `event_max_records`             | 200   | 事件日志上限       |
| `alert_cooldown_seconds`        | 30    | 告警级去重冷却      |
| `webhook_url` / `webhook_token` | —     | Webhook 推送通道 |
| `ui_theme`                      | dirt  | 主题预设         |
| `ui_auto_dark`                  | false | 跟随系统深浅       |

### 安全与健壮性

* **零进程内状态泄漏**：所有敏感配置（Webhook Token、API Key）只在后端内存里处理，前端永远拿不到；config API 对 Token 字段一律脱敏

* **崩溃安全**：每次落盘（快照 / 历史裁剪 / 事件裁剪 / 清空）走 CreateTemp + Write + Sync + Rename，进程崩溃最多留下孤立 .tmp，旧文件始终完整可见

* **日志轮转**：`snapshots/log/mcmon.log`，单文件 1MB、保留 5 个历史文件，INFO+ 同时输出到终端便于观察

* **双层 panic recovery**：GUI 后台采样协程外层兜底打 Error 日志后进程继续，内层每轮独立 recover 防止单轮坏数据导致后续轮询全部跳过

* **配置热重载**：`cfgSign` 感知配置变化，轮询间隔 / 规则阈值 / Webhook / 事件开关 / 主题 改完即生效，无需重启

## 测试

项目内置无 PHPUnit 依赖的轻量测试运行器，覆盖核心功能：

```bash
php tests/test_runner.php
```

测试覆盖范围：VarInt 编解码、MOTD 解析（含彩色 HTML）、核心识别、统一响应、legacy 解析、输入校验与错误码映射、SRV 记录自动解析、缓存、批量并发（校验/缓存/失败条目/SRV 兜底）、SQLite 监控、头像代理、限流器、指标采集（含真实 DNS 失败 / 连接拒绝场景）。

```bash
php tests/test_runner.php        # 单元测试（含 /metrics 与 /health 美化页新增用例）
php tests/probe_qa_edge_cases.php  # QA 独立边界用例（58）
```

## 目录结构

```
mc-server-api/
├── public/
│   ├── index.php            # 前端控制器（路由分发 + 限流/鉴权/指标上报 + 主页/文档/指标/健康页渲染）
│   ├── assets/
│   │   ├── app.css          # 全局主题样式（主页在线工具 + 文档页，CSS 变量深色主题）
│   │   └── app.js           # 在线工具交互脚本（原生 fetch + DOM，无依赖）
│   └── .htaccess            # Apache 重写规则
├── src/
│   ├── autoload.php         # 极简自动加载器（McPing\ 命名空间）
│   ├── MarkdownRenderer.php # 轻量 Markdown -> HTML 渲染器（文档页 /docs 使用，纯标准库）
│   ├── VarInt.php           # VarInt 编解码
│   ├── PingException.php    # 统一业务异常（携带错误码 1001-1009）
│   ├── MinecraftPing.php    # 1.7+ 现代协议核心（含批量复用静态方法）
│   ├── LegacyPing.php       # legacy 0xFE 协议
│   ├── MotdParser.php       # MOTD 解析（含彩色 HTML 渲染）
│   ├── BrandDetector.php    # 核心识别
│   ├── PingResponse.php     # 统一 JSON 响应构建
│   ├── PingClient.php       # 门面类（自动降级 + SRV 解析 + 缓存 + 监控记录）
│   ├── Cache.php            # 进程内内存 TTL 缓存 + 跨请求文件持久化
│   ├── BatchPinger.php      # 批量并发查询（非阻塞 socket + stream_select）
│   ├── Monitor.php          # SQLite 可用性监控
│   ├── AvatarProxy.php      # 玩家头像代理
│   ├── RateLimiter.php      # 固定窗口限流 + API Key 校验
│   ├── WebhookNotifier.php  # Webhook 状态变化通知（飞书/钉钉/企业微信，防抖）
│   ├── McpCore.php          # MCP 共享核心（工具注册 + 调用，stdio 与 HTTP 复用）
│   ├── McpHttpServer.php    # MCP Streamable HTTP 传输（远程端点 /mcp，Bearer 鉴权）
│   └── Metrics.php          # 状态统计采集
├── mcp-server.php           # MCP 服务器（stdio，本机 AI 客户端调用）
├── config/
│   └── config.php           # 全局配置（含 MCAPI_* 环境变量覆盖）
├── data/                    # SQLite 监控数据库（自动创建）
├── favicons/                # 解码后的 favicon 图片（自动创建）
├── avatars/                 # 玩家头像缓存（自动创建）
├── tests/                   # 轻量单元测试
├── client/                  # mcmon 订阅监控客户端（Go，独立二进制）
│   ├── main.go              # CLI 入口（默认 GUI / --run 后台采样）
│   ├── config.go            # 配置结构体 + 默认值（含 UI 主题/数据保留/规则阈值）
│   ├── subscription.go      # 订阅清单加载
│   ├── api.go               # 转发到主站 /api/ping/batch（JSON 透传）
│   ├── scheduler.go         # 轮询循环 + 后台采样协程 + 双层 panic recovery
│   ├── rules.go             # 规则引擎（离线/延迟/人数骤降）
│   ├── report.go            # Report 统一模型 + Reporter 接口 + reportManager 去重
│   ├── console_reporter.go  # 终端通道
│   ├── webhook_reporter.go  # Webhook 通道（飞书/钉钉/企业微信/通用）
│   ├── event_reporter.go    # 事件落盘通道（events.jsonl，可裁剪）
│   ├── event.go             # 事件读写辅助（兼容旧 v0 格式）
│   ├── snapshot.go          # 单次快照落盘 + 历史 JSONL 追加
│   ├── stats.go             # 历史聚合 + 趋势图降采样
│   ├── atomic.go            # tmp + rename 原子写入工具（所有落盘统一走这里）
│   ├── logger.go            # 结构化日志（文件 1MB × 5 轮转 + INFO+ 终端输出）
│   ├── ui_server.go         # GUI HTTP 服务 + 路由
│   ├── ui_handlers.go       # /api/config /api/state /api/events /api/stats 等接口
│   ├── ui_window.go         # WebView2 桌面窗口封装
│   ├── web/                 # 前端资源（go:embed 打包进 exe）
│   │   ├── index.html       # 五视图 + 配置页结构化卡片
│   │   └── assets/          # app.css（主题变量）/ app.js / fusion-pixel.woff2
│   ├── config.example.json  # 配置模板（复制为 config.json 即可）
│   └── mcmon.exe            # Windows 构建产物（.gitignore 忽略）
├── Dockerfile               # Docker 镜像定义
├── docker-compose.yml       # 容器编排（服务名 mc-status-api）
└── README.md
```

## 注意事项

* 超时总时长 = 现代协议超时 + legacy 超时（最坏约 10 秒），可按需调小 timeout\_seconds

* 大响应包上限 2MB，防止恶意服务器拖垮进程

* 核心识别为"尽力识别"，MOTD 中出现核心词可能造成误报，仅供展示参考

* favicon token 基于 host:port 生成，公网部署建议为 API 加鉴权或限流

* 内存缓存/限流/指标为进程内状态；Windows 下 PHP 内置服务器（php -S）每请求独立进程/线程，因此前端控制器会自动把缓存、限流桶、指标计数持久化到 `data/` 下的 JSON 文件（请求结束写盘、下次请求加载），实现跨请求生效；多 worker（PHP-FPM）部署时各 worker 文件写入以 LOCK\_EX 互斥、最后一次写盘生效

* 外置登录服（authlib-injector 等）玩家 UUID 属于第三方 Yggdrasil 体系，官方 minotar/crafatar 头像站无其皮肤；可将 `avatar_api_template` 指向支持外置登录 UUID 的皮肤站 API

* `pdo_sqlite` 扩展缺失时监控自动禁用（记 error\_log，不影响查询主流程）

