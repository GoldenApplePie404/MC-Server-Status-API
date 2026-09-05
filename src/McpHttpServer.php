<?php

declare(strict_types=1);

namespace McPing;

/**
 * MCP Streamable HTTP 传输处理器（远程 MCP 端点）。
 *
 * 端点为 public/index.php 中的 "mcp" 路由（剥除 base_path 后形如 /mcstatus/mcp），
 * 供 Codex / Claude Desktop 等支持 Streamable HTTP 的 MCP 客户端直接远程调用，
 * 复用 McpCore（与 stdio 版工具清单与查询行为完全一致）。
 *
 * 传输语义（MCP Streamable HTTP，2025-06-18 规范）：
 *   - POST / DELETE：客户端 → 服务端。本实现为无状态（stateless）端点，每次 POST
 *     解析一条 JSON-RPC 消息并返回单个 application/json 响应（不需要流式时的合法响应）；
 *     DELETE 返回 405（无会话可终止）。
 *   - GET：客户端订阅服务端推送事件的服务端事件流（SSE），保持 keep-alive 至
 *     sse_max_seconds 或客户端断开；服务端查询工具几乎不产生主动推送，故为心跳流。
 *   - 没有会话 ID（不依赖 Mcp-Session-Id），查询型工具天然无状态，适配
 *     每请求独立进程的 PHP-FPM / mod_php 部署。
 *
 * 鉴权：仅接受 Authorization: Bearer <token>，token 来自 config['mcp']['bearer_token']
 * （可用环境变量 MCAPI_MCP_BEARER_TOKEN 覆盖）。未配置 token 时端点拒绝一切访问，
 * 避免把查询能力裸奔到公网。
 *
 * 依赖说明：GET 提供的 SSE 会持续占用一个 PHP 工作进程，POST 需并发处理，
 * 因此远程 MCP 端点必须跑在支持并发的 Web 服务器（Nginx/Apache + PHP-FPM/mod_php，
 * 建议 pm.max_children 至少 3），PHP 内置单进程服务器（php -S）不适合。
 */
final class McpHttpServer
{
    /**
     * 处理当前 HTTP 请求（在 index.php 中调用，方法内部输出响应头与响应体）。
     *
     * @param array<string, mixed> $config 全局配置
     */
    public static function dispatch(array $config): void
    {
        $mcp = is_array($config['mcp'] ?? null) ? $config['mcp'] : [];
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $token = (string)($mcp['bearer_token'] ?? '');

        // CORS 预检（不限流不鉴权）
        if ($method === 'OPTIONS') {
            self::corsHeaders();
            http_response_code(204);
            return;
        }
        self::corsHeaders();

        // 未启用端点：一律按「未找到」处理，不泄露存在性
        if (!(bool)($mcp['enabled'] ?? false)) {
            self::jsonError(404, 'MCP endpoint disabled');
            return;
        }

        // 未配置令牌 = 禁止访问（安全护栏，防裸奔）
        if ($token === '') {
            self::jsonError(500, 'MCP endpoint is not configured with a bearer_token');
            return;
        }

        // 鉴权：Authorization: Bearer <token>
        if (!self::authorized($token)) {
            self::jsonError(401, 'Unauthorized');
            return;
        }

        if ($method === 'GET') {
            self::serveSse($mcp);
            return;
        }

        if ($method === 'POST') {
            self::handlePost($config);
            return;
        }

        if ($method === 'DELETE') {
            http_response_code(405);
            header('Allow: GET, POST');
            self::emits(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => '此无状态端点不支持 DELETE']]);
            return;
        }

        self::jsonError(405, 'Method Not Allowed');
    }

    /**
     * 校验 Bearer 令牌（使用恒定时间比较）。
     */
    private static function authorized(string $expected): bool
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!is_string($auth)) {
            return false;
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m) !== 1) {
            return false;
        }
        $provided = $m[1] ?? '';
        return $provided !== '' && hash_equals($expected, $provided);
    }

    /**
     * 服务端版本号（来自 config['ui']['version']，剥离前导字母前缀，如 'v1.5' -> '1.5'）。
     *
     * @param array<string, mixed> $config 全局配置
     */
    private static function serverVersion(array $config): string
    {
        $v = (string)($config['ui']['version'] ?? '1.0.0');
        $cleaned = preg_replace('/^[^0-9]+/', '', $v);
        return ($cleaned !== null && $cleaned !== '') ? $cleaned : '1.0.0';
    }

    private static function corsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 1800');
    }

    /**
     * 处理一次客户端 POST（单条 JSON-RPC message）。
     */
    private static function handlePost(array $config): void
    {
        // 仅接受 JSON 请求体（MCP Streamable HTTP 只定义 application/json 与 text/event-stream 请求）
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($contentType !== '' && !str_starts_with($contentType, 'application/json')) {
            self::jsonError(415, 'Unsupported Media Type; expected application/json');
            return;
        }

        // 请求体大小上限：防止超大 JSON body 耗尽内存（与 stdio 端 10MB 上限对齐，
        // 但 tools/call 参数规模很小，1MB 足够且更省）。先看 Content-Length，读取后再复核实际长度。
        $maxBody = 1024 * 1024;
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBody) {
            self::jsonError(413, 'Request body too large');
            return;
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            self::jsonError(400, 'Empty request body');
            return;
        }
        if (strlen($raw) > $maxBody) {
            self::jsonError(413, 'Request body too large');
            return;
        }
        $msg = json_decode($raw, true);
        if (!is_array($msg)) {
            self::jsonError(400, 'Invalid JSON-RPC message');
            return;
        }

        $method = is_string($msg['method'] ?? null) ? $msg['method'] : '';
        $id = $msg['id'] ?? null;

        // 通知类消息：无响应（HTTP 202 无 body）
        if ($id === null) {
            http_response_code(202);
            return;
        }

        if ($method === 'initialize') {
            self::emitRpc($id, [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'mc-server-api', 'version' => self::serverVersion($config)],
            ]);
            return;
        }

        if ($method === 'ping') {
            self::emitRpc($id, []);
            return;
        }

        if ($method === 'tools/list') {
            self::emitRpc($id, ['tools' => McpCore::tools()]);
            return;
        }

        if ($method === 'tools/call') {
            $name = is_string($msg['params']['name'] ?? null) ? $msg['params']['name'] : '';
            $arguments = is_array($msg['params']['arguments'] ?? null) ? $msg['params']['arguments'] : [];
            $result = McpCore::call($name, $arguments, $config);
            self::emitRpc($id, $result);
            return;
        }

        if ($method === 'notifications/initialized' || $method === 'root/list_changed' || $method === 'resources/list_changed') {
            // 无资源/根能力：ack（有 id 的调用才需响应；通常这些是无 id 通知，不会走到这里）
            self::emitRpc($id, []);
            return;
        }

        self::jsonErrorRpc($id, -32601, '方法未找到：' . $method);
    }

    /**
     * 输出单条 JSON-RPC 成功响应。
     *
     * @param mixed  $id
     * @param mixed  $result
     */
    private static function emitRpc($id, $result): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        self::emits(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * 输出 JSON-RPC 错误响应。
     *
     * @param mixed $id
     */
    private static function jsonErrorRpc($id, int $code, string $message): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        self::emits(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    /**
     * 输出通用 JSON 错误（非完整 JSON-RPC，用于鉴权/协议层错误）。
     */
    private static function jsonError(int $httpStatus, string $message): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code($httpStatus);
        self::emits(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => $message]]);
    }

    /**
     * 客户端 GET：返回服务端事件流（SSE 心跳），直至 sse_max_seconds 或客户端断开。
     */
    private static function serveSse(array $mcp): void
    {
        // FPM / mod_php 的 max_execution_time 默认值可能远小于 sse_max_seconds，
        // 若不重置则长连接心跳会被 PHP 超时提前杀掉；CLI 下该调用为空操作。
        @set_time_limit(0);
        ignore_user_abort(false);
        $maxSeconds = max(0, (int)($mcp['sse_max_seconds'] ?? 600));

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        http_response_code(200);
        if (ob_get_level() > 0) {
            ob_end_flush();
        }

        $start = time();
        printf(": %s\n\n", 'mc-server-api MCP keep-alive');
        flush();

        while (true) {
            echo ": keep-alive\n\n";
            flush();
            // PHP 阻塞 sleep 无法中途响应连接断开；断开后下一次 flush 会触发中止
            sleep(5);
            if (connection_aborted()) {
                break;
            }
            if ($maxSeconds > 0 && (time() - $start) >= $maxSeconds) {
                break;
            }
        }
    }

    /**
     * 输出 JSON 并刷新缓冲。
     *
     * @param array<string, mixed> $payload
     */
    private static function emits(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            echo '{}';
            return;
        }
        echo $json;
    }
}