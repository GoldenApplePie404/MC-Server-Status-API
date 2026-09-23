# 项目待办清单（TODO）

> 记录于 2026-08-20。最后更新：2026-09-01。状态：✅ 完成 / 🔧 进行中 / ⬜ 待办

## Bug 修复

* [x] **SRV 自动解析**（v1.1 已交付）：未指定端口或默认端口查询失败时自动查询 `_minecraft._tcp.<host>` 并按 SRV 目标+端口重试。解决 mc.goldenapplepie.xyz / mc.eqmemory.cn（非标准端口 11874/11822）测不到的问题。
  * 根因：两服务器均使用非标准端口并通过 SRV 记录发布；goldenapplepie 主域名走 StarzV 高防前置未放行 MC 端口

  * 验证：91 单元 + 58 边界 + 22 QA 独立用例全过

## P0 功能组（v1.2 已交付）

* [x] **缓存层**：内存 TTL 缓存（默认 30s），重复查询秒回（命中加 `cached:true`，跨请求文件持久化）

* [x] **批量并发查询**：POST/GET /api/ping/batch（非阻塞 socket + stream\_select 并发，上限 20 台/次，SRV 兜底）

* [x] **SQLite 可用性监控**：记录每次查询在线/延迟/人数（失败也记，30 天自动清理），GET /api/monitor 查询接口

## P1 功能组（v1.2 已交付）

* [x] **MOTD 转彩色 HTML**：响应新增 motd.html 字段（16 色/加粗/§x 渐变/Chat Component，XSS 转义）

* [x] **玩家头像接口**：/avatar/{uuid}.png 代理 minotar（可配模板）+ 本地 sha256 缓存

* [x] **限流 + API Key**：固定窗口限流（429/1007）+ api\_keys 鉴权（401/1008，可配置开关）

* [x] **/metrics + Docker**：Prometheus 文本格式指标 + Dockerfile + docker-compose 一键部署

## P2 功能组

* [ ] **UDP Query 协议**：GameSpy4，兼容老服务器获取详细信息（仍为原生协议）

* [ ] **历史趋势接口**：基于 SQLite 监控数据输出趋势

* [x] **宕机 Webhook 告警**：`src/WebhookNotifier.php`，企业微信/飞书(签名)/钉钉/通用 JSON 四种平台，服务端/客户端双端可配

* [x] **前端状态面板**：主页在线工具 UI（单查/批量、彩色 MOTD、图标、在线数、玩家列表）+ /docs 文档页（v1.3 已交付）

## Backlog（低/信息级观察项，来自 QA）

* [ ] srv\_timeout 配置目前为预留项（dns\_get\_record 无超时参数），暂无运行时效果

* [ ] `?port[]=1` 数组参数会被静默回落默认端口（建议返回 1001）

* [ ] host 误带端口（如 example.com:25565）会返回 1004 DNS 错误（文档已要求 host 不含端口）

* [ ] RFC 2782 空 target（"."）按"回退原 host + SRV 端口"处理（有意设计，README 已说明）

## Backlog（P0+P1 QA 发现，非阻塞改进项）

* [ ] cache\_hits\_total 指标约 2x 膨胀：Cache::get() 内部再调 has() 计数，且调用方先 has() 再 get()（src/Cache.php:110-117、src/PingClient.php:101-107、src/BatchPinger.php:157-164）。建议 get() 不再内部计数

* [ ] uptime\_seconds 重启后不归零：uptime.txt 持久化使 uptime 跨请求持续累加（README 已说明）。若需真进程 uptime 语义，启动时应重建 uptime.txt（src/Metrics.php:84-95）

* [ ] Windows ZTS 多进程下 JSON 持久化「读-改-写」非原子（LOCK\_EX 仅串行化写），并发请求可能丢计数；当前规模可接受

* [ ] HTTP 状态码不一致：部分校验错误返回 HTTP 200 + code=1001（如 monitor 缺 host），部分返回 400（如 avatar 非法 uuid）；可统一规范

* [ ] tests/probe\_qa\_edge\_cases.php:132-134 注释过时（称"§x 不完整为源码缺陷"，实际已正确处理），建议清理注释

## AI 智能化扩展（规划，2026-08 提出）

> 复用已有底座（MCP / monitor 历史 / Webhook / 收藏夹 / lark）延伸 AI 能力。改动成本随档位递增：
> A 档≈零新依赖（纯统计/编排），B 档需引入 LLM 网关，C 档对外联通。

* [ ] **智能异常告警**（A 档 · 优先级高）：复用 `monitor` 历史检测离线、人数骤降、非预期重启，走现有 Webhook 推送并给出可能成因推断（纯统计，无 LLM）

* [ ] **定时 AI 巡检日报**（A 档）：用既有 MCP + 定时能力周期性扫收藏/常用服，产出状态周报/日报（汇总到飞书或文件）

* [ ] **自然语言对话查询**（B 档）：引入 LLM 网关，让用户用自然语言查实时/历史状态（复用已打通的 MCP 端点作为数据底座）

* [ ] **飞书群 AI 值班**（C 档）：把告警/巡检汇聚成结构化飞书消息并支持回执联动 lark

> 安全提醒：B 档的 LLM 网关密钥不要写入本单文件 API 侧，建议由外部 agent 作为编排层，项目仅作数据 + 执行底座（与 MCP 定位一致）。

## 监控订阅客户端（Client · Go 实现，已交付完整 Web GUI）

> **定位**：服务端（本 API）只作「只读数据源 + MCP 工具」；把「订阅 → 轮询 → 规则检测 → 机械报告」整体下沉到用户本地。
> **技术栈已定型**：Go 1.22+ 独立二进制（mcmonitor.exe，静态链接，零运行时依赖），内置 HTTP 服务 + go:embed 前端资源，Windows 端默认 WebView2 原生窗口。CLI 参数保留供后台/纯终端使用。

### 架构（模块划分）

* 输入：订阅清单（host/port/分组/别名）+ config.json 配置

* 调度器：轮询循环，间隔可配；UI 模式下 cfgSign 驱动配置热重载

* 采集器：周期性调服务端 `/api/ping/batch` 只读查询（复用 SRV/双协议/降级）

* 规则引擎：阈值 + 连续命中计数 → 产出 Report

* 报告器：console / webhook / event(落盘) 三通道，reportManager 统一去重防抖

* 持久化：本地 snapshots 目录（history/<key>.jsonl + events.jsonl）+ 配置驱动上限裁剪

* GUI：侧栏导航 + MC 像素风 + 搜索/监控/统计/配置/告警事件 五视图

### 功能里程碑

* [x] **M0 骨架**：CLI 入口 + 配置文件读取 + 订阅清单解析

* [x] **M1 轮询调度**：`poll_interval` 可配，按间隔调 `ping_batch` 采样，落本地快照

* [x] **M2 规则检测**：离线（连续 N 次在线=0）/ 延迟告警 / 人数骤降，阈值全部可配

* [x] **M3 机械报告**：reportManager 统一派发 → console\_reporter / webhook\_reporter / event\_reporter 三通道，alert\_cooldown\_seconds 事件级去重防抖

* [ ] **M4 参数完善**：监控指标可选（online / latency / players / motd 变化）、输出格式与频率、时区

* [ ] **M5 LLM 旁路（可选）**：解释 + 建议 + 周期小结，可在配置关闭

* [x] **M6 健壮性**：结构化日志写盘+轮转（logger.go，snapshots/log/mcmonitor.log）、goroutine 单轮+协程级双层 panic recovery（scheduler pollLoop/startUIPolling）、快照/事件写入 tmp+rename 原子化（atomic.go + snapshot.go + event\_reporter.go）、fetchBatch 30s HTTP 超时兜底

### GUI 里程碑（已全部交付）

* [x] **G0 Web 服务器双模式**：--run CLI 后台模式 / 默认 WebView2 窗口

* [x] **G1 视觉框架**：侧栏导航 + FusionPixel 字体 + MC 像素风配色主题

* [x] **G2 可视化搜索**：单查卡片流 + 批查 MC 列表行，本地 API 转发到服务端

* [x] **G3 订阅监控面板**：实时状态列表，30s 自动刷新

* [x] **G4 数据统计监测**：历史趋势 SVG 图 + 汇总卡片，降采样 + 配置驱动上限

* [x] **G5 配置页**：结构化卡片化编辑 config + 订阅清单，热重载无需重启

* [x] **G6 告警与事件**：事件列表可视化 + 过滤 + 清空，数据保留上限可配

### 待决（实现前需定）

* [ ] 是否将来加「服务端托管订阅 + 私密只读端点」（当前全拉取式）

