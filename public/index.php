<?php

declare(strict_types=1);

/**
 * 前端控制器（唯一入口）。
 *
 * 路由（基于 REQUEST_URI 手工分发，兼容 PHP 内置服务器与 Apache/Nginx 重写）：
 *   GET  /                       主页（在线查询工具门户，HTML）
 *   GET  /docs                   文档页（README.md 渲染，HTML）
 *   GET  /assets/*               静态资源（app.css / app.js，不参与鉴权限流）
 *   GET  /health 或 /api/health  健康检查（不限流不鉴权；浏览器打开返回美化 HTML 状态页）
 *   GET  /api/ping?host=xxx&port=25565        查询服务器状态（query 方式）
 *   GET  /api/ping/{host}?port=25565          查询服务器状态（路径方式）
 *   GET|POST /api/ping/batch                  批量并发查询（POST 读 JSON body，GET 读 ?servers=）
 *   GET  /api/monitor?host=xxx&limit=50       SQLite 可用性监控记录
 *   GET  /favicon/{token}.png                输出已解码的 favicon PNG 图片
 *   GET  /avatar/{uuid}.png                  玩家头像代理（上游 + 本地缓存）
 *   GET  /metrics                             状态统计（浏览器打开返回美化 HTML 视图，抓取仍为文本格式）
 *   POST /mcp 或 GET /mcp                     Remote MCP 端点（Streamable HTTP，独立 Bearer 鉴权，
 *                                             供 Codex / Claude Desktop 等远程调用，见 McpHttpServer）
 *
 * 所有 API 响应均为统一 JSON 结构：
 *   { "success": bool, "code": int, "message": string, "data": {...} }
 *
 * 鉴权与限流（/health、/ 主页、/docs 文档页、/assets 静态资源除外）：
 *   - api_keys 非空时要求 X-API-Key 头或 ?api_key= 命中，否则 401 + 1008；
 *   - 携带合法 Key 的请求跳过限流；rate_limit_enabled 时按 客户端IP+路由 分桶，
 *     超限返回 429 + 1007 并带 X-RateLimit-Remaining 响应头。
 *   - 公开页面（/、/docs）与 /health、/assets 一致，不参与鉴权与限流，
 *     保证启用 API Key 后在线工具门户仍可被浏览器正常打开。
 */

require __DIR__ . '/../src/autoload.php';

// 统一时区为 Asia/Shanghai：确保 /health 的 JSON time 字段与页面本地时间一致（不再显示 UTC +00:00）
date_default_timezone_set('Asia/Shanghai');

use McPing\AvatarProxy;
use McPing\BatchPinger;
use McPing\Cache;
use McPing\McpHttpServer;
use McPing\Metrics;
use McPing\Monitor;
use McPing\PingClient;
use McPing\PingException;
use McPing\PingResponse;
use McPing\RateLimiter;
use McPing\WebhookNotifier;

/** @var array<string, mixed> 全局配置 */
$config = require __DIR__ . '/../config/config.php';

// 全局基础路径前缀（子目录部署用）：含首尾斜杠处理，供所有渲染函数与前端 JS 使用。
// 默认 ''（域名根目录），配置如 '/mcstatus' 时所有页面链接 / 静态资源自动带前缀。
$GLOBALS['MCAPI_BASE_PATH'] = trim((string)($config['base_path'] ?? ''), '/');

// 测试模式（MCAPI_SKIP_EXECUTION）：仅加载本文件中的函数定义，不执行路由处理，
// 也不做任何副作用初始化（避免测试过程写盘 data/ 下的持久化文件）。
// tests/status_pages_test.php 通过定义该常量引入本文件后，直接调用渲染函数断言 HTML 片段。
if (defined('MCAPI_SKIP_EXECUTION')) {
    goto define_functions;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?? '/';
$path = rawurldecode($path);
$path = trim($path, '/');

// 子目录部署支持：剥除 base_path 前缀后再匹配路由（如 /mcstatus/api/ping -> api/ping）
$basePath = trim((string)($config['base_path'] ?? ''), '/');
if ($basePath !== '') {
    if ($path === $basePath) {
        $path = '';
    } elseif (str_starts_with($path, $basePath . '/')) {
        $path = substr($path, strlen($basePath) + 1);
    }
}

// 路由标签（用于限流分桶与指标）
$route = detectRoute($path);

// 配置跨请求持久化（Windows 内置服务器每请求独立进程/线程，须写盘跨请求生效）。
// MCP 远程端点是纯只读查询端，跳过全部副作用初始化（Cache/Monitor/Metrics/Webhook），
// 避免 MCP 查询污染 HTTP 侧的监控库、磁盘 metrics 与缓存文件（与 mcp-server.php stdio 的隔离承诺一致）。
if ($route !== 'mcp') {
    Cache::configure($config);
    RateLimiter::configure($config);
    Metrics::configure($config);
    Metrics::init();
    Monitor::init($config);
    WebhookNotifier::configure($config);
}

// CORS 预检（不限流不鉴权）
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    http_response_code(204);
    exit;
}

// /metrics 本身不产生请求计数（避免抓取自身造成循环污染）
if ($route !== 'metrics') {
    Metrics::increment('http_requests_total', ['route' => $route]);
}

// 静态资源（/assets/*）：直接输出 public/assets 下文件，不参与鉴权限流
if (str_starts_with($path, 'assets/')) {
    serveStaticAsset($path);
}

// /health 不限流不鉴权
if ($route === 'health') {
    if ($method !== 'GET') {
        respondJson(PingResponse::error(1001, '仅支持 GET 请求'));
    }
    // 浏览器美化页：?format=html 或 Accept: text/html 时返回 HTML；
    // 其余请求（curl、程序调用）保持原 JSON，结构与时区格式完全不变（契约零破坏）
    $format = queryParam('format');
    if (decideHealthFormat($format, $_SERVER['HTTP_ACCEPT'] ?? null) === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo renderHealthPage($config);
        exit;
    }
    respondJson([
        'success' => true,
        'code' => 0,
        'message' => 'ok',
        'data' => [
            'service' => 'mc-server-api',
            'php_version' => PHP_VERSION,
            'time' => date('c'),
        ],
    ]);
}

// MCP 远程端点（Streamable HTTP）：独立 Bearer 鉴权，不参与 api_keys 限流
//（逻辑见 McpHttpServer；endpoint 未启用或令牌缺失时内部直接返回错误并中止）。
if ($route === 'mcp') {
    McpHttpServer::dispatch($config);
    exit;
}

// 公开路由（/、/docs）：与 /health、/assets 静态资源一致，不参与鉴权与限流。
// 在线工具门户需在启用 API Key 后仍能被浏览器正常打开（页面无敏感信息），
// /api/* 与其余路由保持原有鉴权与限流行为。
$publicRoutes = ['home', 'docs'];
$isPublicRoute = in_array($route, $publicRoutes, true);

if (!$isPublicRoute && $route !== 'health') {
    // 鉴权：api_keys 非空时要求合法 key
    $apiKeys = (array)($config['api_keys'] ?? []);
    $authenticated = RateLimiter::validApiKey(apiKeyFromRequest(), $apiKeys);
    if (!$authenticated) {
        respondJson(PingResponse::error(1008, 'API Key 无效或缺失'), 401);
    }

    // 限流：合法 key 请求放宽（跳过 IP 维度限流）；未启用鉴权时对所有请求限流
    $skipRateLimit = $authenticated && $apiKeys !== [];
    $rateEnabled = (bool)($config['rate_limit_enabled'] ?? true);
    if ($rateEnabled && !$skipRateLimit) {
        $rateLimit = (int)($config['rate_limit_per_minute'] ?? 60);
        $bucketKey = clientIp($config) . '|' . $route;
        $result = RateLimiter::instance()->consume($bucketKey, $rateLimit);
        header('X-RateLimit-Remaining: ' . (string)$result['remaining']);
        if (!$result['allowed']) {
            header('Retry-After: ' . (string)$result['retry_after']);
            respondJson(PingResponse::error(1007), 429);
        }
    }
}

// 非批量路由仅支持 GET（批量支持 GET+POST）
if ($method !== 'GET' && $route !== 'batch') {
    respondJson(PingResponse::error(1001, '仅支持 GET 请求'));
}

// ============ 路由分发 ============

// 首页
if ($route === 'home') {
    renderHomePage($config);
}

// 文档页（README.md 渲染）
if ($route === 'docs') {
    renderDocsPage($config);
}

// favicon 输出：/favicon/{64位十六进制}.png
if ($route === 'favicon') {
    if (preg_match('#^favicon/([a-f0-9]{64})\.png$#', $path, $matches) === 1) {
        serveFavicon($matches[1], $config);
    }
    respondJson(PingResponse::error(1001, 'favicon 不存在'), 404);
}

// 玩家头像：/avatar/{uuid}.png
if ($route === 'avatar') {
    if (preg_match('#^avatar/([^/]+)\.png$#', $path, $matches) === 1) {
        $uuid = $matches[1];
        if (!AvatarProxy::isValidUuid($uuid)) {
            respondJson(PingResponse::error(1001, 'UUID 格式无效'), 400);
        }
        $png = AvatarProxy::fetch($uuid, $config);
        if ($png === null) {
            respondJson(PingResponse::error(1001, '头像获取失败：上游无此玩家皮肤或抓取超时'), 404);
        }
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        echo $png;
        exit;
    }
    respondJson(PingResponse::error(1001, 'UUID 格式无效'), 400);
}

// 批量并发查询：POST /api/ping/batch（GET 兼容 ?servers=）
if ($route === 'batch') {
    handleBatch($config);
}

// 单台查询：GET /api/ping 与 /api/ping/{host}
if ($route === 'ping') {
    handlePing($config, $path);
}

// SQLite 可用性监控记录：GET /api/monitor?host=xxx&limit=50
if ($route === 'monitor') {
    handleMonitor($config);
}

// 状态统计：GET /metrics
// 输出决策（优先级从高到低）：
//   1. ?format=raw  → 始终纯文本（text/plain; version=0.0.4，与改造前完全一致）
//   2. ?format=html → 始终美化 HTML
//   3. Accept: text/html（浏览器）→ 美化 HTML
//   4. 其他（含 curl 默认 Accept: */*、状态统计）→ 保持现有纯文本
// 纯文本分支零改动：直接调用 Metrics::export() 原样输出。
if ($route === 'metrics') {
    $format = queryParam('format');
    if (decideMetricsFormat($format, $_SERVER['HTTP_ACCEPT'] ?? null) === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo renderMetricsPage($config);
        exit;
    }
    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    header('Cache-Control: no-store');
    echo Metrics::export();
    exit;
}

// 未匹配任何路由
respondJson(PingResponse::error(1001, '接口不存在：/' . $path), 404);

// 测试模式跳转目标（MCAPI_SKIP_EXECUTION）：以下仅为函数定义，无任何副作用。
define_functions:
; // 空语句：goto 目标标签后必须紧跟语句（PHP 语法要求）

/**
 * 路由识别（返回路由标签）。
 *
 * @param string $path 去除首尾斜杠的路径
 */
function detectRoute(string $path): string
{
    if ($path === '') {
        return 'home';
    }
    if ($path === 'health' || $path === 'api/health') {
        return 'health';
    }
    if ($path === 'docs') {
        return 'docs';
    }
    if (preg_match('#^favicon/[0-9a-f]{64}\.png$#', $path) === 1) {
        return 'favicon';
    }
    if (preg_match('#^avatar/[^/]+\.png$#', $path) === 1) {
        return 'avatar';
    }
    if ($path === 'api/ping/batch') {
        return 'batch';
    }
    if ($path === 'api/ping' || str_starts_with($path, 'api/ping/')) {
        return 'ping';
    }
    if ($path === 'api/monitor') {
        return 'monitor';
    }
    if ($path === 'metrics') {
        return 'metrics';
    }
    if ($path === 'mcp') {
        return 'mcp';
    }
    return 'unknown';
}

/**
 * 安全读取 query 参数（拒绝数组形式，如 ?host[]=x）。
 *
 * @param string $name 参数名
 * @return string|null 参数值；不存在或为数组时返回 null
 */
function queryParam(string $name): ?string
{
    if (!isset($_GET[$name])) {
        return null;
    }
    $value = $_GET[$name];
    if (is_array($value)) {
        return null;
    }
    return (string)$value;
}

/**
 * 从请求头 X-API-Key 或 query 参数 api_key 提取 API Key。
 *
 * @return string|null 未携带时返回 null
 */
function apiKeyFromRequest(): ?string
{
    $header = $_SERVER['HTTP_X_API_KEY'] ?? null;
    if (is_string($header) && $header !== '') {
        return $header;
    }
    $query = queryParam('api_key');
    if ($query !== null && $query !== '') {
        return $query;
    }
    return null;
}

/**
 * 获取客户端 IP。
 *
 * 默认仅使用 REMOTE_ADDR（防伪造）；trust_proxy_headers 开启时
 * 优先取 X-Forwarded-For 第一个地址（仅限可信反向代理之后部署）。
 *
 * @param array<string, mixed> $config 全局配置
 */
function clientIp(array $config): string
{
    if ((bool)($config['trust_proxy_headers'] ?? false)) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if (is_string($forwarded) && $forwarded !== '') {
            $parts = explode(',', $forwarded);
            $first = trim((string)($parts[0] ?? ''));
            if ($first !== '') {
                return $first;
            }
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * 输出统一 JSON 响应并结束请求。
 *
 * 顺带上报 http_errors_total 指标（code 非 0 时）。
 *
 * @param array<string, mixed> $payload    统一响应结构
 * @param int                  $httpStatus HTTP 状态码
 */
function respondJson(array $payload, int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    if (isset($payload['code']) && (int)$payload['code'] !== 0) {
        Metrics::increment('http_errors_total', ['code' => (string)(int)$payload['code']]);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 单台服务器状态查询处理（GET /api/ping 与 /api/ping/{host}）。
 *
 * @param array<string, mixed> $config 全局配置
 * @param string               $path   请求路径
 */
function handlePing(array $config, string $path): void
{
    if ($path === 'api/ping') {
        $host = queryParam('host') ?? '';
    } else {
        $host = substr($path, strlen('api/ping/'));
    }
    $portParam = queryParam('port');
    $portRaw = $portParam === null ? (string)(int)$config['default_port'] : $portParam;

    $host = trim($host);
    $port = filter_var($portRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

    // 输入校验：host 必填且非空
    $maxHostLength = (int)($config['host_max_length'] ?? 255);
    if ($host === '') {
        respondJson(PingResponse::error(1001, 'host 必填且不能为空', offlineData($host, $port === false ? 0 : (int)$port)));
    }
    if (strlen($host) > $maxHostLength) {
        respondJson(PingResponse::error(1001, "host 长度不能超过 {$maxHostLength} 个字符", offlineData($host, $port === false ? 0 : (int)$port)));
    }
    if (preg_match('/[\s\/\\\\\x00-\x1f\x7f]/', $host) === 1) {
        respondJson(PingResponse::error(1001, 'host 包含非法字符', offlineData($host, $port === false ? 0 : (int)$port)));
    }
    if ($port === false) {
        respondJson(PingResponse::error(1001, 'port 必须是 1-65535 的整数', offlineData($host, 0)));
    }
    $port = (int)$port;

    try {
        $client = new PingClient($config);
        $data = $client->ping($host, $port);
        Metrics::increment('ping_success_total');
        WebhookNotifier::notifyIfChanged($host, $port, $data);
        respondJson(PingResponse::ok($data));
    } catch (PingException $e) {
        Metrics::increment('ping_failure_total');
        WebhookNotifier::notifyIfChanged($host, $port, offlineData($host, $port));
        respondJson(PingResponse::error($e->getErrorCode(), $e->getMessage(), offlineData($host, $port)));
    } catch (\Throwable $e) {
        Metrics::increment('ping_failure_total');
        WebhookNotifier::notifyIfChanged($host, $port, offlineData($host, $port));
        respondJson(PingResponse::error(1006, '服务器离线：未知错误 ' . $e->getMessage(), offlineData($host, $port)));
    }
}

/**
 * 批量并发查询处理（POST /api/ping/batch；GET 兼容 ?servers=）。
 *
 * @param array<string, mixed> $config 全局配置
 */
function handleBatch(array $config): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // 请求体来源：POST 读 JSON body；GET 读 ?servers=（JSON 字符串）
    $raw = null;
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
    } else {
        $serversParam = queryParam('servers');
        if ($serversParam !== null) {
            $raw = $serversParam;
        }
    }

    if ($raw === null || trim($raw) === '') {
        respondJson(PingResponse::error(1009, '请求体必须为 JSON：{"servers":[{"host":"..","port":25565}]}'), 400);
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        respondJson(PingResponse::error(1009, '请求体必须包含 servers 数组'), 400);
    }

    // 兼容两种输入形态：
    //   POST：{"servers":[{...}, ...]}
    //   GET： ?servers=[{...}, ...]（值本身就是服务器数组）
    if (array_is_list($body)) {
        $servers = $body;
    } elseif (isset($body['servers']) && is_array($body['servers'])) {
        $servers = $body['servers'];
    } else {
        respondJson(PingResponse::error(1009, '请求体必须包含 servers 数组'), 400);
    }

    try {
        $pinger = new BatchPinger($config);
        $data = $pinger->pingBatch($servers);
        // Webhook 状态变化通知（每个结果逐一检测）
        if (is_array($data['results'] ?? null)) {
            foreach ($data['results'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $rHost = (string)($item['host'] ?? '');
                $rPort = (int)($item['port'] ?? (int)$config['default_port']);
                if ($rHost === '') {
                    continue;
                }
                WebhookNotifier::notifyIfChanged($rHost, $rPort, $item);
            }
        }
        respondJson(PingResponse::ok($data));
    } catch (PingException $e) {
        respondJson(PingResponse::error($e->getErrorCode(), $e->getMessage()), 400);
    } catch (\Throwable $e) {
        respondJson(PingResponse::error(1006, '批量查询失败：' . $e->getMessage()), 500);
    }
}

/**
 * SQLite 可用性监控记录查询（GET /api/monitor?host=xxx&limit=50）。
 *
 * @param array<string, mixed> $config 全局配置
 */
function handleMonitor(array $config): void
{
    $host = queryParam('host') ?? '';
    $host = trim($host);
    if ($host === '') {
        respondJson(PingResponse::error(1001, 'host 必填且不能为空'));
    }

    $limitParam = queryParam('limit');
    $limit = $limitParam === null ? 50 : filter_var($limitParam, FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1) {
        $limit = 50;
    }
    $limit = min(200, $limit);

    $records = Monitor::query($host, $limit);
    respondJson(PingResponse::ok([
        'host' => $host,
        'limit' => $limit,
        'count' => count($records),
        'records' => $records,
    ]));
}

/**
 * 构建"服务器离线"状态的 data 结构（用于失败响应）。
 *
 * @return array<string, mixed>
 */
function offlineData(string $host, int $port): array
{
    return [
        'online' => false,
        'host' => $host,
        'port' => $port,
        'latency_ms' => null,
        'version' => ['name' => null, 'protocol' => null, 'brand' => null],
        'players' => ['online' => null, 'max' => null, 'sample' => null],
        'motd' => ['raw' => null, 'plain_text' => null, 'has_legacy_codes' => false, 'html' => null],
        'favicon' => ['base64' => null, 'saved_path' => null, 'url' => null],
        'protocol_used' => null,
        'secure_chat' => ['enforces' => null, 'previews' => null],
        'srv_used' => false,
        'srv_record' => null,
    ];
}

/**
 * 输出 favicon PNG 图片。
 *
 * @param string               $token  64 位十六进制 token（路由已校验格式）
 * @param array<string, mixed> $config 全局配置
 */
function serveFavicon(string $token, array $config): void
{
    $dir = (string)($config['favicon_dir'] ?? '');
    $file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $token . '.png';
    if ($dir === '' || !is_file($file)) {
        respondJson(PingResponse::error(1001, 'favicon 不存在'), 404);
    }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    readfile($file);
    exit;
}

/**
 * 输出静态资源（/assets/*）。
 *
 * 兼容两种部署：
 *   - Apache/Nginx：public/ 为文档根目录时由 Web 服务器直接命中真实文件；
 *   - PHP 内置服务器（php -S ... public/index.php）：请求进入本控制器后在此输出。
 * 仅允许 assets/ 下白名单扩展名，并拦截目录穿越。
 *
 * @param string $path 请求路径（已 rawurldecode、去除首尾斜杠）
 */
function serveStaticAsset(string $path): void
{
    if (preg_match('#^assets/([A-Za-z0-9_./-]+)$#', $path, $matches) !== 1 || str_contains($matches[1], '..')) {
        respondJson(PingResponse::error(1001, '静态资源不存在'), 404);
    }
    $file = __DIR__ . '/assets/' . $matches[1];
    if (!is_file($file)) {
        respondJson(PingResponse::error(1001, '静态资源不存在'), 404);
    }
    $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
        'woff2' => 'font/woff2',
        'txt' => 'text/plain; charset=utf-8',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=3600');
    readfile($file);
    exit;
}

/**
 * 提取 UI 相关配置（带默认值兜底）。
 *
 * @return array<string, string>
 */
function uiConfig(array $config): array
{
    $ui = is_array($config['ui'] ?? null) ? $config['ui'] : [];
    return [
        'site_title' => (string)($ui['site_title'] ?? 'Minecraft 服务器状态查询'),
        'site_description' => (string)($ui['site_description'] ?? '基于 Server List Ping 协议的 Minecraft Java 版服务器状态在线查询工具'),
        'example_host' => (string)($ui['example_host'] ?? 'mc.goldenapplepie.xyz'),
        'version' => (string)($ui['version'] ?? 'v1.2'),
    ];
}

/**
 * 返回全局基础路径前缀（'/mcstatus' 或 ''）。
 */
function baseUrl(): string
{
    $base = $GLOBALS['MCAPI_BASE_PATH'] ?? '';
    return $base === '' ? '' : '/' . $base;
}

/**
 * 输出 <head> 片段（含样式表引用）。
 *
 * @param string $title       页面标题
 * @param string $description 页面描述（meta description）
 * @param array<string, string> $ui UI 配置
 */
function renderPageHead(string $title, string $description, array $ui): string
{
    $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $descriptionEsc = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    $base = baseUrl();
    $baseJs = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="{$descriptionEsc}">
<title>{$titleEsc}</title>
<link rel="stylesheet" href="{$base}/assets/app.css">
<script>window.MCAPI_BASE = "{$baseJs}";</script>
</head>
HTML;
}

/**
 * 输出内联 SVG 图标库（<symbol> 定义，页面内以 <use href="#icon-*"> 引用）。
 *
 * 全部为描边风格图标（stroke: currentColor），随文字颜色变化；
 * 不依赖任何外部图标库或 CDN。
 */
function renderIconDefs(): string
{
    return <<<'HTML'
<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
  <defs>
    <symbol id="icon-grass" viewBox="0 0 24 24">
      <path d="M12 2.5 21 7 12 11.5 3 7z" fill="#8bc34a"/>
      <path d="M3 7 12 11.5 12 21.5 3 17z" fill="#8d6e63"/>
      <path d="M12 11.5 21 7 21 17 12 21.5z" fill="#6d4c41"/>
    </symbol>
    <symbol id="icon-search" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/>
      <line x1="16.5" y1="16.5" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </symbol>
    <symbol id="icon-doc" viewBox="0 0 24 24">
      <path d="M6 3h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
      <path d="M14 3v4h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
      <line x1="8.5" y1="12" x2="15.5" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <line x1="8.5" y1="16" x2="15.5" y2="16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </symbol>
    <symbol id="icon-gauge" viewBox="0 0 24 24">
      <path d="M4 14a8 8 0 1 1 16 0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <path d="M12 14l4-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      <circle cx="12" cy="14" r="1.6" fill="currentColor"/>
      <line x1="4" y1="20" x2="20" y2="20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </symbol>
    <symbol id="icon-health" viewBox="0 0 24 24">
      <path d="M12 20.5S4 15.5 4 9.7C4 6.6 6.4 4.5 9 4.5c1.2 0 2.3.5 3 1.4.7-.9 1.8-1.4 3-1.4 2.6 0 5 2.1 5 5.2 0 5.8-8 10.8-8 10.8z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
    </symbol>
    <symbol id="icon-copy" viewBox="0 0 24 24">
      <rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
      <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </symbol>
    <symbol id="icon-loader" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3" stroke-dasharray="42 16" stroke-linecap="round"/>
    </symbol>
    <symbol id="icon-server" viewBox="0 0 24 24">
      <rect x="3" y="4" width="18" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
      <rect x="3" y="13" width="18" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
      <circle cx="7" cy="7.5" r="1" fill="currentColor"/>
      <circle cx="7" cy="16.5" r="1" fill="currentColor"/>
    </symbol>
    <symbol id="icon-zap" viewBox="0 0 24 24">
      <path d="M13 2 4 14h6l-1 8 9-12h-6z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
    </symbol>
    <symbol id="icon-list" viewBox="0 0 24 24">
      <line x1="9" y1="6" x2="20" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <line x1="9" y1="12" x2="20" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <line x1="9" y1="18" x2="20" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <circle cx="4.5" cy="6" r="1.2" fill="currentColor"/>
      <circle cx="4.5" cy="12" r="1.2" fill="currentColor"/>
      <circle cx="4.5" cy="18" r="1.2" fill="currentColor"/>
    </symbol>
    <symbol id="icon-eye" viewBox="0 0 24 24">
      <path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
      <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/>
    </symbol>
    <symbol id="icon-compass" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/>
      <path d="M15.5 8.5 13.8 13.8 8.5 15.5l1.7-5.3z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
    </symbol>
    <symbol id="icon-color" viewBox="0 0 24 24">
      <path d="M12 3a9 9 0 1 0 0 18c1.7 0 2.5-1.3 2.5-2.6 0-1-.6-1.7-1.4-2.2-.8-.5-1.3-1.2-1.3-2.2 0-1.4 1.1-2.5 2.5-2.5H16a5 5 0 0 0 5-5c0-2-2.3-3.5-9-3.5z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
      <circle cx="7.5" cy="10" r="1.2" fill="currentColor"/>
      <circle cx="9.5" cy="6.5" r="1.2" fill="currentColor"/>
      <circle cx="13.5" cy="6.5" r="1.2" fill="currentColor"/>
    </symbol>
    <symbol id="icon-check" viewBox="0 0 24 24">
      <path d="M4 12.5l5 5L20 6.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="icon-alert" viewBox="0 0 24 24">
      <path d="M12 3 2.5 20h19z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
      <line x1="12" y1="10" x2="12" y2="14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <circle cx="12" cy="17" r="1.1" fill="currentColor"/>
    </symbol>
    <symbol id="icon-menu" viewBox="0 0 24 24">
      <line x1="4" y1="7" x2="20" y2="7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <line x1="4" y1="12" x2="20" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <line x1="4" y1="17" x2="20" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </symbol>
    <symbol id="icon-info" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/>
      <line x1="12" y1="11" x2="12" y2="16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <circle cx="12" cy="8" r="1.1" fill="currentColor"/>
    </symbol>
    <symbol id="icon-image" viewBox="0 0 24 24">
      <rect x="3" y="4" width="18" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
      <circle cx="9" cy="10" r="1.8" fill="none" stroke="currentColor" stroke-width="2"/>
      <path d="M4 18l5-5 4 4 3-3 4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="icon-clock" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/>
      <path d="M12 7v5l3.5 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="icon-users" viewBox="0 0 24 24">
      <circle cx="9" cy="8" r="3" fill="none" stroke="currentColor" stroke-width="2"/>
      <path d="M3.5 19c0-3 2.5-5 5.5-5s5.5 2 5.5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <path d="M16 6.5a3 3 0 0 1 0 5.6M17.5 14c2.4.4 4 2.3 4 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </symbol>
    <symbol id="icon-group" viewBox="0 0 24 24">
      <circle cx="8" cy="9" r="2.6" fill="none" stroke="currentColor" stroke-width="2"/>
      <circle cx="16" cy="9" r="2.6" fill="none" stroke="currentColor" stroke-width="2"/>
      <path d="M3 19c0-2.8 2.2-4.5 5-4.5s5 1.7 5 4.5M13.5 14.5C16 14 18 15.7 18 18.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </symbol>
    <symbol id="icon-tag" viewBox="0 0 24 24">
      <path d="M4 4h7l9 9-7 7-9-9z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
      <circle cx="8" cy="8" r="1.4" fill="currentColor"/>
    </symbol>
    <symbol id="icon-layers" viewBox="0 0 24 24">
      <path d="M12 3 21 8 12 13 3 8z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
      <path d="M3 12l9 5 9-5M3 16l9 5 9-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
    </symbol>
    <symbol id="icon-badge" viewBox="0 0 24 24">
      <path d="M12 3l2.2 1.6 2.7-.2 1 2.5 2.2 1.6-1 2.5 1 2.5-2.2 1.6-1 2.5-2.7-.2L12 21l-2.2-1.6-2.7.2-1-2.5L3.9 15.5l1-2.5-1-2.5 2.2-1.6 1-2.5 2.7.2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
      <circle cx="12" cy="12" r="2.4" fill="none" stroke="currentColor" stroke-width="2"/>
    </symbol>
    <symbol id="icon-signal" viewBox="0 0 24 24">
      <path d="M5 16a8 8 0 0 1 8-8M5 16a4 4 0 0 1 4-4M5 16a12 12 0 0 1 12-12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <circle cx="5" cy="16" r="2" fill="currentColor"/>
    </symbol>
    <symbol id="icon-activity" viewBox="0 0 24 24">
      <path d="M2 12h4l2.5-7 5 14 2.5-7H22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="icon-database" viewBox="0 0 24 24">
      <ellipse cx="12" cy="6" rx="8" ry="3" fill="none" stroke="currentColor" stroke-width="2"/>
      <path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6" fill="none" stroke="currentColor" stroke-width="2"/>
      <path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3" fill="none" stroke="currentColor" stroke-width="2"/>
    </symbol>
    <symbol id="icon-shield" viewBox="0 0 24 24">
      <path d="M12 3 5 6v6c0 4 3 7 7 9 4-2 7-5 7-9V6z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
      <path d="M9 12l2 2 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="icon-globe" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/>
      <path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18" fill="none" stroke="currentColor" stroke-width="2"/>
    </symbol>
    <symbol id="icon-player" viewBox="0 0 24 24">
      <circle cx="12" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="2"/>
      <path d="M4 20c0-4 3.6-6 8-6s8 2 8 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </symbol>
    <symbol id="icon-star" viewBox="0 0 24 24">
      <path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
    </symbol>
  </defs>
</svg>
HTML;
}

/**
 * 输出顶部导航栏。
 *
 * @param string $active 当前激活项：home / docs
 * @param array<string, string> $ui UI 配置
 */
function renderSiteHeader(string $active, array $ui): string
{
    $homeClass = $active === 'home' ? ' class="active"' : '';
    $docsClass = $active === 'docs' ? ' class="active"' : '';
    $metricsClass = $active === 'metrics' ? ' class="active"' : '';
    $healthClass = $active === 'health' ? ' active' : '';
    // 配置值经 htmlspecialchars 转义后再插入 HTML（纵深防御，防引号逃逸）
    $versionEsc = htmlspecialchars((string)($ui['version'] ?? ''), ENT_QUOTES, 'UTF-8');
    $base = baseUrl();
    return <<<HTML
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="{$base}/">
      <svg class="brand-icon" aria-hidden="true"><use href="#icon-grass"></use></svg>
      <span class="brand-name">MC服务器状态查询</span>
      <span class="brand-version">{$versionEsc}</span>
    </a>
    <nav class="site-nav" aria-label="主导航">
      <a href="{$base}/"{$homeClass}><svg class="nav-icon" aria-hidden="true"><use href="#icon-search"></use></svg>工具</a>
      <a href="{$base}/docs"{$docsClass}><svg class="nav-icon" aria-hidden="true"><use href="#icon-doc"></use></svg>文档</a>
      <a href="{$base}/metrics"{$metricsClass}><svg class="nav-icon" aria-hidden="true"><use href="#icon-gauge"></use></svg>状态统计</a>
      <a href="{$base}/health" class="health-link{$healthClass}"><span class="nav-dot"></span>健康检查</a>
    </nav>
    <button type="button" class="nav-toggle" aria-label="展开菜单" aria-expanded="false">
      <svg aria-hidden="true"><use href="#icon-menu"></use></svg>
    </button>
  </div>
</header>
HTML;
}

/**
 * 输出页脚。
 *
 * @param array<string, string> $ui UI 配置
 */
function renderSiteFooter(array $ui): string
{
    // 配置值经 htmlspecialchars 转义后再插入 HTML（纵深防御）
    $siteTitleEsc = htmlspecialchars((string)($ui['site_title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $base = baseUrl();
    return <<<HTML
<footer class="site-footer">
  <div class="container footer-inner">
    <div class="footer-links">
      <a href="https://github.com/GoldenApplePie404/MC-Server-Status-API" target="_blank">GitHub</a>
      <a href="https://space.bilibili.com/399173069" target="_blank">Bilibili</a>
      <a href="https://blog.goldenapplepie.xyz/" target="_blank">金苹果派の博客</a>
      <a href="https://mcpc.goldenapplepie.xyz/" target="_blank">万驹同源官网</a>
    </div>
    <div class="footer-note">{$siteTitleEsc} · 纯 PHP 8.3 标准库实现，无框架、无构建依赖 · 基于 Minecraft Server List Ping 协议</div>
  </div>
</footer>
HTML;
}

/**
 * 渲染主页（在线查询工具门户）。
 *
 * @param array<string, mixed> $config 全局配置
 */
function renderHomePage(array $config): void
{
    $ui = uiConfig($config);
    $defaultPort = (int)($config['default_port'] ?? 25565);
    // 直接插入 heredoc 的配置值先转义（纵深防御，防引号逃逸）；传给
    // renderPageHead 的值由其内部统一转义，此处保留原始值
    $exampleHost = htmlspecialchars((string)($ui['example_host'] ?? ''), ENT_QUOTES, 'UTF-8');
    $siteTitle = $ui['site_title'];
    $siteDescription = $ui['site_description'];
    $version = htmlspecialchars((string)($ui['version'] ?? ''), ENT_QUOTES, 'UTF-8');

    header('Content-Type: text/html; charset=utf-8');

    $head = renderPageHead($siteTitle . ' · 在线工具', $siteDescription, $ui);
    $iconDefs = renderIconDefs();
    $header = renderSiteHeader('home', $ui);
    $footer = renderSiteFooter($ui);
    $base = baseUrl();

    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
{$head}
<body>
{$iconDefs}
{$header}
<main>
  <!-- Hero 区 -->
  <section class="hero">
    <div class="container">
      <h1>Minecraft 服务器状态 <span class="hl">在线查询</span></h1>
      <p class="hero-sub">输入服务器地址，立即获取在线人数、彩色 MOTD、服务器图标、版本与延迟；支持单台与批量查询，SRV 记录自动解析。</p>
      <div class="hero-badges">
        <span class="badge" id="api-health-badge"><span class="badge-dot"></span><span class="badge-text">检测中</span></span>
        <span class="badge brand-badge">{$version}</span>
      </div>
    </div>
  </section>

  <!-- 在线工具面板 -->
  <section class="tool-section">
    <div class="container">
      <div class="tool-panel" id="mc-tool">
        <div class="tool-tabs">
          <button type="button" class="tab-btn active" data-mode="single"><svg class="tab-icon" aria-hidden="true"><use href="#icon-search"></use></svg>单台查询</button>
          <button type="button" class="tab-btn" data-mode="batch"><svg class="tab-icon" aria-hidden="true"><use href="#icon-list"></use></svg>批量查询</button>
          <button type="button" class="tab-btn" data-mode="fav"><svg class="tab-icon" aria-hidden="true"><use href="#icon-star"></use></svg>收藏夹</button>
          <div class="auto-refresh">
            <label for="auto-refresh">自动刷新</label>
            <select id="auto-refresh" title="按选定间隔自动重新查询最后一次结果">
              <option value="0">关闭</option>
              <option value="15">15 秒</option>
              <option value="30">30 秒</option>
              <option value="60">60 秒</option>
            </select>
          </div>
        </div>
        <div class="tool-body">
          <form id="single-form" class="tool-form" autocomplete="off">
            <div class="field-row">
              <div class="field-host">
                <input type="text" id="single-host" placeholder="服务器地址，如 {$exampleHost}" required>
              </div>
              <div class="field-port">
                <input type="number" id="single-port" placeholder="端口 {$defaultPort}" min="1" max="65535">
              </div>
              <button type="submit" class="btn btn-primary" id="single-submit">
                <svg aria-hidden="true"><use href="#icon-search"></use></svg>查询
              </button>
            </div>
          </form>
          <div id="batch-form" class="tool-form hidden">
            <textarea id="batch-input" placeholder="每行一个服务器地址，支持 host 或 host:port，例如：&#10;{$exampleHost}&#10;mc.eqmemory.cn&#10;mc.hypixel.net:25565"></textarea>
            <div class="batch-actions">
              <button type="button" class="btn btn-ghost" id="batch-clear"><svg aria-hidden="true"><use href="#icon-menu"></use></svg>清空</button>
              <button type="button" class="btn btn-primary" id="batch-submit"><svg aria-hidden="true"><use href="#icon-list"></use></svg>批量查询</button>
            </div>
            </div>
          <div id="fav-view" class="fav-panel hidden">
            <div class="fav-head">
              <span><svg aria-hidden="true"><use href="#icon-star"></use></svg>收藏夹（点击立即查询，保存在本机浏览器）</span>
              <button type="button" class="btn btn-ghost btn-xs" id="fav-clear">清空</button>
            </div>
            <div id="fav-list" class="fav-list"></div>
          </div>
          <div class="tool-hint">
            <svg aria-hidden="true"><use href="#icon-info"></use></svg>
            <span>提示：未指定端口时使用默认端口 {$defaultPort}；失败时自动解析 _minecraft._tcp SRV 记录兜底。批量模式每行一台，最多 20 台。</span>
          </div>
        </div>
      </div>
      <div id="result-area" class="result-area" aria-live="polite"></div>
    </div>
  </section>

  <!-- 特性网格 -->
  <section class="features-section">
    <div class="container">
      <h2 class="section-title">核心特性</h2>
      <p class="section-sub">纯 PHP 8.3 标准库实现，覆盖现代协议与生产所需能力</p>
      <div class="features-grid">
        <div class="feature-card">
          <div class="feature-icon"><svg aria-hidden="true"><use href="#icon-color"></use></svg></div>
          <h3>彩色 MOTD</h3>
          <p>支持 § 颜色码与新版 Chat Component，渲染为带样式的彩色 HTML，全文本 XSS 转义。</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon"><svg aria-hidden="true"><use href="#icon-image"></use></svg></div>
          <h3>服务器图标</h3>
          <p>favicon Base64 自动解码落盘，提供 HTTP 输出接口，随时取用。</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon"><svg aria-hidden="true"><use href="#icon-compass"></use></svg></div>
          <h3>SRV 自动解析</h3>
          <p>自动查询 _minecraft._tcp 记录并按目标主机与端口兜底重试，支持外置登录服务器。</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon"><svg aria-hidden="true"><use href="#icon-zap"></use></svg></div>
          <h3>批量并发查询</h3>
          <p>非阻塞 socket + stream_select 并发查询多台服务器，一次返回全部结果。</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon"><svg aria-hidden="true"><use href="#icon-clock"></use></svg></div>
          <h3>可用性监控</h3>
          <p>SQLite 记录每次查询的在线 / 延迟 / 人数历史，30 天自动清理，接口可查。</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon"><svg aria-hidden="true"><use href="#icon-eye"></use></svg></div>
          <h3>玩家头像代理</h3>
          <p>按 UUID 获取正版皮肤头像，本地缓存加速，支持自定义上游模板。</p>
        </div>
      </div>
    </div>
  </section>

  <!-- 快速开始 -->
  <section class="quickstart-section">
    <div class="container">
      <h2 class="section-title">快速开始</h2>
      <p class="section-sub">无需安装任何依赖，一条命令启动完整 API</p>
      <div class="quickstart-grid">
        <!--<div class="qs-card">
          <div class="qs-card-head"><h3>启动服务器</h3><button type="button" class="copy-btn"><svg aria-hidden="true"><use href="#icon-copy"></use></svg>复制</button></div>
          <pre><code>php -S 0.0.0.0:8080 public/index.php</code></pre>
        </div>-->
        <div class="qs-card">
          <div class="qs-card-head"><h3>单台查询</h3><button type="button" class="copy-btn"><svg aria-hidden="true"><use href="#icon-copy"></use></svg>复制</button></div>
          <pre><code>curl "https://mcpc.goldenapplepie.xyz/mcstatus/api/ping?host={$exampleHost}"

          </code></pre>
        </div>
        <div class="qs-card">
          <div class="qs-card-head"><h3>批量查询</h3><button type="button" class="copy-btn"><svg aria-hidden="true"><use href="#icon-copy"></use></svg>复制</button></div>
          <pre><code>curl -X POST "https://mcpc.goldenapplepie.xyz/mcstatus/api/ping/batch" \
  -H "Content-Type: application/json" \
  -d '{"servers":[{"host":"mc.goldenapplepie.xyz"},{"host":"mc.eqmemory.cn"}]}'</code></pre>
        </div>
      </div>
    </div>
  </section>
</main>
{$footer}
<script src="{$base}/assets/app.js" defer></script>
</body>
</html>
HTML;
    exit;
}

/**
 * 渲染文档页（README.md 渲染为排版精美的 HTML）。
 *
 * @param array<string, mixed> $config 全局配置
 */
function renderDocsPage(array $config): void
{
    $ui = uiConfig($config);
    header('Content-Type: text/html; charset=utf-8');

    $readmePath = dirname(__DIR__) . '/README.md';
    $markdown = is_file($readmePath) ? (string)file_get_contents($readmePath) : '（README.md 不存在）';

    $renderer = new \McPing\MarkdownRenderer();
    $contentHtml = $renderer->render($markdown);
    $tocHtml = $renderer->renderToc($markdown);
    if ($tocHtml === '') {
        $tocHtml = '<div class="docs-toc"><div class="toc-title">目录</div><p style="color:var(--text-faint);font-size:13px;">（无二级标题）</p></div>';
    }

    $head = renderPageHead($ui['site_title'] . ' · 文档', '项目文档：README 渲染页（API 说明、配置与协议实现）', $ui);
    $iconDefs = renderIconDefs();
    $header = renderSiteHeader('docs', $ui);
    $footer = renderSiteFooter($ui);
    $base = baseUrl();

    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
{$head}
<body>
{$iconDefs}
<div class="docs-progress"><div class="docs-progress-bar"></div></div>
{$header}
<main>
  <section class="docs-section">
    <div class="container docs-layout">
      <aside class="docs-toc-wrap">{$tocHtml}</aside>
      <article class="docs-content">{$contentHtml}</article>
    </div>
  </section>
</main>
{$footer}
<script src="{$base}/assets/app.js" defer></script>
</body>
</html>
HTML;
    exit;
}

/**
 * 判断请求头 Accept 是否包含 text/html（浏览器语义）。
 *
 * 支持逗号分隔的多个媒体类型与 q 权重（q=0 表示明确不接受）；
 * 大小写不敏感；同时识别 application/xhtml+xml（浏览器同族 HTML）。
 *
 * @param string|null $acceptHeader 原始 Accept 请求头；null 表示未携带
 */
function acceptsHtmlHeader(?string $acceptHeader): bool
{
    if ($acceptHeader === null || $acceptHeader === '') {
        return false;
    }
    foreach (explode(',', $acceptHeader) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $segments = explode(';', $part);
        $media = strtolower(trim((string)($segments[0] ?? '')));
        if ($media !== 'text/html' && $media !== 'application/xhtml+xml') {
            continue;
        }
        // 解析 q 权重（缺省 1.0）
        $q = 1.0;
        foreach (array_slice($segments, 1) as $param) {
            $param = trim($param);
            if (stripos($param, 'q=') === 0) {
                $q = (float)substr($param, 2);
            }
        }
        if ($q > 0.0) {
            return true;
        }
    }
    return false;
}

/**
 * 当前请求是否倾向浏览器 HTML（读取 $_SERVER['HTTP_ACCEPT']）。
 */
function acceptsHtml(): bool
{
    return acceptsHtmlHeader($_SERVER['HTTP_ACCEPT'] ?? null);
}

/**
 * /metrics 输出格式决策。
 *
 * 优先级（从高到低）：
 *   1. ?format=raw  → 始终纯文本（状态统计兼容）；
 *   2. ?format=html → 始终美化 HTML；
 *   3. 未指定 format 且 Accept: text/html（浏览器）→ 美化 HTML；
 *   4. 其余（含 curl 默认 Accept 通配、状态统计）→ 纯文本。
 *
 * @param string|null $format       query 参数 format
 * @param string|null $acceptHeader 原始 Accept 请求头
 * @return string 'html' 或 'plain'
 */
function decideMetricsFormat(?string $format, ?string $acceptHeader): string
{
    if ($format === 'raw') {
        return 'plain';
    }
    if ($format === 'html') {
        return 'html';
    }
    if ($format === null && acceptsHtmlHeader($acceptHeader)) {
        return 'html';
    }
    return 'plain';
}

/**
 * /health 输出格式决策。
 *
 * ?format=html 或 Accept: text/html 时返回美化 HTML；
 * 其余请求（curl、程序调用）保持原 JSON，契约零破坏。
 *
 * @param string|null $format       query 参数 format
 * @param string|null $acceptHeader 原始 Accept 请求头
 * @return string 'html' 或 'json'
 */
function decideHealthFormat(?string $format, ?string $acceptHeader): string
{
    if ($format === 'html') {
        return 'html';
    }
    if (($format === null || $format === '') && acceptsHtmlHeader($acceptHeader)) {
        return 'html';
    }
    return 'json';
}

/**
 * 解析状态统计 文本为结构化分组。
 *
 * 输入 Metrics::export() 输出（HELP/TYPE 头 + 样本行），返回：
 *   [ 指标名 => ['type' => ..., 'help' => ..., 'samples' => [...] ] ]
 * 其中每个样本为 ['name' => ..., 'labels' => [...], 'value' => float]。
 * 标签值按 状态统计 转义规则还原（\"、\\、\n）。
 *
 * @return array<string, array{type: string, help: string, samples: array<int, array{name: string, labels: array<string, string>, value: float}>}>
 */
function parsePrometheusText(string $text): array
{
    $families = [];
    foreach (explode("\n", $text) as $line) {
        $line = rtrim($line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '# HELP ')) {
            // "# HELP 指标名 描述文本"
            $parts = preg_split('/\s+/', substr($line, 7), 2);
            $name = (string)($parts[0] ?? '');
            if ($name === '') {
                continue;
            }
            $families[$name]['type'] = $families[$name]['type'] ?? 'counter';
            $families[$name]['help'] = (string)($parts[1] ?? '');
            $families[$name]['samples'] = $families[$name]['samples'] ?? [];
        } elseif (str_starts_with($line, '# TYPE ')) {
            // "# TYPE 指标名 counter|gauge"
            $parts = preg_split('/\s+/', substr($line, 7));
            $name = (string)($parts[0] ?? '');
            if ($name === '') {
                continue;
            }
            $families[$name]['type'] = (string)($parts[1] ?? 'counter');
            $families[$name]['help'] = $families[$name]['help'] ?? '';
            $families[$name]['samples'] = $families[$name]['samples'] ?? [];
        } elseif ($line[0] !== '#') {
            // 样本行：metric_name{labels} value
            $pos = strrpos($line, ' ');
            if ($pos === false) {
                continue;
            }
            $value = (float)substr($line, $pos + 1);
            $metricPart = substr($line, 0, $pos);
            $name = $metricPart;
            $labels = [];
            $open = strpos($metricPart, '{');
            if ($open !== false) {
                $name = substr($metricPart, 0, $open);
                $labelText = rtrim(substr($metricPart, $open + 1), '}');
                foreach (explode(',', $labelText) as $kv) {
                    $eq = strpos($kv, '=');
                    if ($eq === false) {
                        continue;
                    }
                    $labelName = trim(substr($kv, 0, $eq));
                    $labelValue = trim(substr($kv, $eq + 1), '"');
                    // 还原 状态统计 转义（单遍顺序解码，避免多段 str_replace 顺序替换
                    // 把字面量 "\n"（转义反斜杠 + 字母 n）误判为换行）：
                    //   \n → 换行，\" → 引号，\\ → 反斜杠，其余字符原样保留
                    $decoded = '';
                    $labelLen = strlen($labelValue);
                    for ($i = 0; $i < $labelLen; $i++) {
                        $ch = $labelValue[$i];
                        if ($ch === '\\' && $i + 1 < $labelLen) {
                            $next = $labelValue[$i + 1];
                            if ($next === 'n') {
                                $decoded .= "\n";
                                $i++;
                                continue;
                            }
                            if ($next === '"') {
                                $decoded .= '"';
                                $i++;
                                continue;
                            }
                            if ($next === '\\') {
                                $decoded .= '\\';
                                $i++;
                                continue;
                            }
                        }
                        $decoded .= $ch;
                    }
                    $labelValue = $decoded;
                    if ($labelName !== '') {
                        $labels[$labelName] = $labelValue;
                    }
                }
            }
            if ($name === '') {
                continue;
            }
            $families[$name]['type'] = $families[$name]['type'] ?? 'counter';
            $families[$name]['help'] = $families[$name]['help'] ?? '';
            $families[$name]['samples'][] = ['name' => $name, 'labels' => $labels, 'value' => $value];
        }
    }
    return $families;
}

/**
 * 将秒数格式化为可读时长（中文）。
 *
 * 示例：5708 → "1小时35分8秒"；0 → "0秒"；86405 → "1天5秒"。
 *
 * @param float $seconds 秒数（可为小数，向下取整）
 */
function formatUptime(float $seconds): string
{
    $total = max(0, (int)floor($seconds));
    $days = intdiv($total, 86400);
    $hours = intdiv($total % 86400, 3600);
    $minutes = intdiv($total % 3600, 60);
    $secs = $total % 60;
    $parts = [];
    if ($days > 0) {
        $parts[] = $days . '天';
    }
    if ($hours > 0) {
        $parts[] = $hours . '小时';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . '分';
    }
    $parts[] = $secs . '秒';
    return implode('', $parts);
}

/**
 * 指标数值展示：整数不带小数点，浮点保留最多 3 位（去尾零）。
 */
function formatMetricValue(float $value): string
{
    if (floor($value) === $value) {
        return (string)(int)$value;
    }
    return rtrim(rtrim(sprintf('%.3f', $value), '0'), '.');
}

/**
 * 渲染 gauge 大数字卡片。
 *
 * @param string $label     中文标题（如 运行时长）
 * @param string $name      指标名（如 uptime_seconds）
 * @param float  $value     原始数值
 * @param string $sub       补充说明
 * @param string $display   展示文本（如 1小时35分8秒）
 * @param string $tone      强调色（accent / 空）
 * @param string $iconName  可选图标名（对应 icon- 前缀）
 */
function renderMetricGaugeCard(string $label, string $name, float $value, string $sub, string $display, string $tone = '', string $iconName = ''): string
{
    $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $subEsc = htmlspecialchars($sub, ENT_QUOTES, 'UTF-8');
    $displayEsc = htmlspecialchars($display, ENT_QUOTES, 'UTF-8');
    $toneClass = $tone !== '' ? ' tone-' . $tone : '';
    $iconHtml = $iconName !== '' ? '<svg aria-hidden="true" class="gauge-icon"><use href="#icon-' . htmlspecialchars($iconName, ENT_QUOTES, 'UTF-8') . '"></use></svg>' : '';
    return '<div class="gauge-card' . $toneClass . '">'
        . $iconHtml
        . '<div class="gauge-label">' . $labelEsc . '</div>'
        . '<div class="gauge-value">' . $displayEsc . '</div>'
        . '<div class="gauge-sub">' . $subEsc . ' · <code>' . $nameEsc . '</code></div>'
        . '</div>';
}

/**
 * 渲染带标签的表格卡片（如 http_requests_total 按 route、http_errors_total 按 code）。
 *
 * @param string $name     指标名
 * @param string $title    卡片标题（中文）
 * @param string $help     HELP 描述
 * @param array  $samples  样本数组（parsePrometheusText 输出结构）
 * @param string $labelKey 展示用的标签键
 */
function renderMetricLabelTable(string $name, string $title, string $help, array $samples, string $labelKey): string
{
    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $helpEsc = htmlspecialchars($help, ENT_QUOTES, 'UTF-8');
    $rows = '';
    $total = 0.0;
    foreach ($samples as $sample) {
        $labelValue = (string)($sample['labels'][$labelKey] ?? '（无标签）');
        $value = (float)$sample['value'];
        $total += $value;
        $labelEsc = htmlspecialchars($labelValue, ENT_QUOTES, 'UTF-8');
        $valueEsc = htmlspecialchars(formatMetricValue($value), ENT_QUOTES, 'UTF-8');
        $rows .= '<tr>'
            . '<td><span class="metric-dot"></span><code class="metric-label">' . $labelEsc . '</code></td>'
            . '<td class="metric-num">' . $valueEsc . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="2" class="metric-empty">暂无数据（等待首次样本）</td></tr>';
    }
    $totalEsc = htmlspecialchars(formatMetricValue($total), ENT_QUOTES, 'UTF-8');
    return '<div class="metric-card">'
        . '<div class="metric-card-head">'
        . '<div>'
        . '<div class="metric-title">' . $titleEsc . '</div>'
        . '<div class="metric-help">' . $helpEsc . ' · <code>' . $nameEsc . '</code></div>'
        . '</div>'
        . '<span class="metric-total">' . $totalEsc . '</span>'
        . '</div>'
        . '<div class="metric-table-wrap"><table class="metric-table"><tbody>' . $rows . '</tbody></table></div>'
        . '</div>';
}

/**
 * 渲染无标签计数徽章卡（ping / cache 等）。
 *
 * @param string $name      指标名
 * @param string $label     中文标签
 * @param string $help      HELP 描述
 * @param float  $total     当前值
 * @param string $tone      色点（good / bad / warn / info）
 * @param string $iconName  可选图标名（对应 icon- 前缀）
 */
function renderMetricStat(string $name, string $label, string $help, float $total, string $tone, string $iconName = ''): string
{
    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $helpEsc = htmlspecialchars($help, ENT_QUOTES, 'UTF-8');
    $valueEsc = htmlspecialchars(formatMetricValue($total), ENT_QUOTES, 'UTF-8');
    $toneClass = $tone !== '' ? ' ' . $tone : '';
    $helpLine = $helpEsc !== '' ? ' · ' . $helpEsc : '';
    $iconHtml = $iconName !== '' ? '<svg aria-hidden="true" class="metric-icon"><use href="#icon-' . htmlspecialchars($iconName, ENT_QUOTES, 'UTF-8') . '"></use></svg>' : '';
    return '<div class="metric-stat' . $toneClass . '">'
        . $iconHtml
        . '<div class="metric-stat-main">'
        . '<div class="metric-stat-label">' . $labelEsc . '</div>'
        . '<div class="metric-stat-value">' . $valueEsc . '</div>'
        . '<div class="metric-stat-name"><code>' . $nameEsc . '</code>' . $helpLine . '</div>'
        . '</div>'
        . '</div>';
}

/**
 * 渲染未知指标通用卡片（保证未来新增指标在页面中仍可见）。
 *
 * @param string $name    指标名
 * @param string $type    指标类型（counter / gauge）
 * @param string $help    HELP 描述
 * @param array  $samples 样本数组
 */
function renderMetricGeneric(string $name, string $type, string $help, array $samples): string
{
    $hasLabels = false;
    foreach ($samples as $sample) {
        if (($sample['labels'] ?? []) !== []) {
            $hasLabels = true;
            break;
        }
    }
    if (!$hasLabels) {
        $total = 0.0;
        foreach ($samples as $sample) {
            $total += (float)$sample['value'];
        }
        return renderMetricStat($name, $name . '（' . $type . '）', $help, $total, 'info');
    }
    // 有标签：聚合全部标签键为列，输出通用表格
    $labelKeys = [];
    foreach ($samples as $sample) {
        foreach (array_keys($sample['labels'] ?? []) as $labelKey) {
            $labelKeys[$labelKey] = true;
        }
    }
    $labelKeys = array_keys($labelKeys);
    sort($labelKeys);
    $header = '<tr>';
    foreach ($labelKeys as $labelKey) {
        $header .= '<th>' . htmlspecialchars((string)$labelKey, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $header .= '<th class="metric-num">值</th></tr>';
    $rows = '';
    foreach ($samples as $sample) {
        $cells = '';
        foreach ($labelKeys as $labelKey) {
            $v = (string)($sample['labels'][$labelKey] ?? '');
            $cells .= '<td><code class="metric-label">' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '</code></td>';
        }
        $rows .= '<tr>' . $cells . '<td class="metric-num">' . htmlspecialchars(formatMetricValue((float)$sample['value']), ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $helpEsc = htmlspecialchars($help, ENT_QUOTES, 'UTF-8');
    return '<div class="metric-card">'
        . '<div class="metric-card-head"><div>'
        . '<div class="metric-title"><code>' . $nameEsc . '</code>（' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '）</div>'
        . '<div class="metric-help">' . $helpEsc . '</div>'
        . '</div></div>'
        . '<div class="metric-table-wrap"><table class="metric-table"><thead>' . $header . '</thead><tbody>' . $rows . '</tbody></table></div>'
        . '</div>';
}

/**
 * 渲染 /metrics 浏览器美化页（返回完整 HTML 文档字符串）。
 *
 * 数据来源：Metrics::export() 实时输出（与纯文本抓取完全同源），
 * 保证 HTML 视图与 状态统计 文本展示的数值一致。
 * 页面含：导航栏（工具/文档/指标/健康）、gauge 大数字卡、counter 表格/徽章、
 * 查看原始文本链接（?format=raw）、5 秒自动刷新开关（保留滚动位置）。
 * /metrics 自身请求不产生 route="metrics" 计数污染（沿用现有豁免逻辑），
 * 轮询请求同样命中 /metrics 路由，因此不会刷爆请求计数。
 *
 * @param array<string, mixed> $config 全局配置
 * @return string 完整 HTML 文档
 */
function renderMetricsPage(array $config): string
{
    $ui = uiConfig($config);
    $families = parsePrometheusText(Metrics::export());

    $gaugeCards = '';
    $statCards = '';
    $tableCards = '';
    foreach ($families as $name => $family) {
        $type = (string)($family['type'] ?? 'counter');
        $help = (string)($family['help'] ?? '');
        $samples = $family['samples'] ?? [];
        $total = 0.0;
        foreach ($samples as $sample) {
            $total += (float)$sample['value'];
        }

        if ($name === 'uptime_seconds') {
            $value = (float)($samples[0]['value'] ?? 0.0);
            $gaugeCards .= renderMetricGaugeCard('运行时长', $name, $value, '进程启动至今已运行', formatUptime($value), 'accent', 'clock');
        } elseif ($name === 'active_batch_requests') {
            $value = (float)($samples[0]['value'] ?? 0.0);
            $gaugeCards .= renderMetricGaugeCard('进行中批量查询', $name, $value, '当前正在执行的批量查询数', formatMetricValue($value), '', 'zap');
        } elseif ($name === 'http_requests_total') {
            $tableCards .= renderMetricLabelTable($name, '累计请求数（按路由）', $help, $samples, 'route');
        } elseif ($name === 'http_errors_total') {
            $tableCards .= renderMetricLabelTable($name, '累计错误数（按错误码）', $help, $samples, 'code');
        } elseif ($name === 'ping_success_total') {
            $statCards .= renderMetricStat($name, '查询成功', $help, $total, 'good', 'check');
        } elseif ($name === 'ping_failure_total') {
            $statCards .= renderMetricStat($name, '查询失败', $help, $total, 'bad', 'alert');
        } elseif ($name === 'cache_hits_total') {
            $statCards .= renderMetricStat($name, '缓存命中', $help, $total, 'good', 'database');
        } elseif ($name === 'cache_misses_total') {
            $statCards .= renderMetricStat($name, '缓存未命中', $help, $total, 'warn', 'database');
        } else {
            $tableCards .= renderMetricGeneric($name, $type, $help, $samples);
        }
    }

    $head = renderPageHead($ui['site_title'] . ' · 状态统计', '状态统计浏览器视图：状态统计 文本抓取保持完全兼容', $ui);
    $iconDefs = renderIconDefs();
    $header = renderSiteHeader('metrics', $ui);
    $footer = renderSiteFooter($ui);
    $base = baseUrl();

    $gaugeSection = $gaugeCards !== '' ? '<div class="metrics-gauges">' . $gaugeCards . '</div>' : '';
    $statSection = $statCards !== '' ? '<div class="metrics-stats">' . $statCards . '</div>' : '';
    $tableSection = $tableCards !== '' ? '<div class="metrics-grid">' . $tableCards . '</div>' : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
{$head}
<body>
{$iconDefs}
{$header}
<main>
  <section class="metrics-section">
    <div class="container">
      <div class="page-head">
        <h1>状态统计</h1>
        <div class="page-toolbar">
          <a class="btn btn-ghost" href="{$base}/metrics?format=raw"><svg aria-hidden="true"><use href="#icon-eye"></use></svg>查看原始文本</a>
          <label class="auto-refresh"><input type="checkbox" id="metrics-auto-refresh"><span>自动刷新（5 秒）</span></label>
        </div>
      </div>
      <div id="metrics-content">
        {$gaugeSection}
        {$statSection}
        {$tableSection}
      </div>
    </div>
  </section>
</main>
{$footer}
<script src="{$base}/assets/app.js" defer></script>
<script>
(function () {
  var toggle = document.getElementById('metrics-auto-refresh');
  var content = document.getElementById('metrics-content');
  if (!toggle || !content) { return; }
  var timer = null;
  function refresh() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '{$base}/metrics?format=html&_t=' + Date.now(), true);
    xhr.setRequestHeader('Accept', 'text/html');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4 || xhr.status !== 200) { return; }
      var parser = new DOMParser();
      var doc = parser.parseFromString(xhr.responseText, 'text/html');
      var fresh = doc.getElementById('metrics-content');
      if (!fresh) { return; }
      var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
      content.innerHTML = fresh.innerHTML;
      window.scrollTo(0, scrollTop);
    };
    xhr.send();
  }
  toggle.addEventListener('change', function () {
    if (toggle.checked) {
      if (timer) { clearInterval(timer); }
      timer = setInterval(refresh, 5000);
    } else {
      if (timer) { clearInterval(timer); }
      timer = null;
    }
  });
})();
</script>
</body>
</html>
HTML;
}

/**
 * 渲染 /health 浏览器美化页（返回完整 HTML 文档字符串）。
 *
 * JSON 接口契约不变（time 字段保持 date('c') 原格式，由 JSON 分支输出）；
 * 页面内额外以 Asia/Shanghai 时区展示可读本地时间。
 *
 * @param array<string, mixed> $config 全局配置
 * @return string 完整 HTML 文档
 */
function renderHealthPage(array $config): string
{
    $ui = uiConfig($config);
    $timezone = new DateTimeZone('Asia/Shanghai');
    $now = new DateTimeImmutable('now', $timezone);
    $timeDisplay = $now->format('Y-m-d H:i:s');
    $service = 'mc-server-api';
    $phpVersion = PHP_VERSION;
    $serviceEsc = htmlspecialchars($service, ENT_QUOTES, 'UTF-8');
    $phpVersionEsc = htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8');
    $timeDisplayEsc = htmlspecialchars($timeDisplay, ENT_QUOTES, 'UTF-8');

    $head = renderPageHead($ui['site_title'] . ' · 健康检查', '服务健康检查状态页（JSON 接口保持兼容）', $ui);
    $iconDefs = renderIconDefs();
    $header = renderSiteHeader('health', $ui);
    $footer = renderSiteFooter($ui);
    $base = baseUrl();

    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
{$head}
<body>
{$iconDefs}
{$header}
<main>
  <section class="health-section">
    <div class="container health-wrap">
      <div class="health-card">
        <div class="health-icon"><svg aria-hidden="true"><use href="#icon-health"></use></svg></div>
        <h1>服务健康状态</h1>
        <div class="health-badge ok"><span class="badge-dot"></span>运行正常</div>
        <div class="health-meta">
          <div class="health-row">
            <svg aria-hidden="true" class="health-icon-sm"><use href="#icon-server"></use></svg>
            <span class="health-label">服务名称</span>
            <span class="health-value mono">{$serviceEsc}</span>
          </div>
          <div class="health-row">
            <svg aria-hidden="true" class="health-icon-sm"><use href="#icon-layers"></use></svg>
            <span class="health-label">PHP 版本</span>
            <span class="health-value mono">{$phpVersionEsc}</span>
          </div>
          <div class="health-row">
            <svg aria-hidden="true" class="health-icon-sm"><use href="#icon-clock"></use></svg>
            <span class="health-label">当前时间</span>
            <span class="health-value mono">{$timeDisplayEsc}</span>
          </div>
          <div class="health-row">
            <svg aria-hidden="true" class="health-icon-sm"><use href="#icon-globe"></use></svg>
            <span class="health-label">时区</span>
            <span class="health-value">Asia/Shanghai</span>
          </div>
        </div>
        <div class="health-links">
          <a class="btn btn-ghost" href="{$base}/"><svg aria-hidden="true"><use href="#icon-search"></use></svg>在线工具</a>
          <a class="btn btn-ghost" href="{$base}/docs"><svg aria-hidden="true"><use href="#icon-doc"></use></svg>API 文档</a>
          <a class="btn btn-ghost" href="{$base}/metrics"><svg aria-hidden="true"><use href="#icon-gauge"></use></svg>状态统计</a>
          <a class="btn btn-ghost" href="https://github.com/GoldenApplePie404/MC-Server-Status-API" target="_blank"><svg aria-hidden="true"><use href="#icon-github"></use></svg>在 GitHub 上查看</a>
        </div>
      </div>
    </div>
  </section>
</main>
{$footer}
<script src="{$base}/assets/app.js" defer></script>
</body>
</html>
HTML;
}
