<?php

declare(strict_types=1);

/**
 * MCP (Model Context Protocol) 服务器入口 — Minecraft 服务器状态查询。
 *
 * 通过 stdio 与 MCP 客户端通信（JSON-RPC 2.0 + Content-Length 帧，与官方
 * TypeScript/Python SDK 的 StdioTransport 一致），让 AI 助手可直接调用本项目的
 * 查询能力，无需经过 HTTP。
 *
 * 注册的工具：
 *   - ping_server：单台查询 Minecraft Java 版服务器状态
 *   - ping_batch ：并发批量查询多台服务器（最多 20 台）
 *
 * 客户端配置示例（claude_desktop_config.json / 等效配置）：
 * {
 *   "mcpServers": {
 *     "mc-status": {
 *       "command": "php",
 *       "args": ["E:/In_development/mc-server-api/mcp-server.php"]
 *     }
 *   }
 * }
 *
 * 注意：stdio 通信，禁止用 echo/print 输出调试信息（会破坏协议帧），
 * 调试请使用 error_log 或 fwrite(STDERR, ...)。
 *
 * ──────────────────────────────────────────────────
 * 生产部署与副作用隔离（重要，改动前必读）
 * ──────────────────────────────────────────────────
 * 本进程是只读查询端，不调用 Cache::configure / Monitor::init /
 * Metrics::configure / WebhookNotifier::configure。
 * 依赖「未配置 = 关闭、无持久化」的类设计，从而保证：
 *   - Cache   ：仅在进程内存内做 TTL 缓存（命中可省网络，不写盘）
 *   - Monitor ：保持 disabled，不写 data/*.sqlite
 *   - Metrics ：纯内存计数，不写 data/metrics*.json
 *   - Webhook ：enabled=false，绝不对外发送状态通知
 * 请勿在 mcp-server.php 中新增上述 configure/init 调用，否则会把 HTTP 侧
 * 的监控/缓存/指标/Webhook 副作用带入 MCP 进程（污染生产数据、并发锁冲突）。
 *
 * 安全边界：MCP 通过本机 stdio 与可信客户端通信，无鉴权。
 * 只应作为客户端（Claude Desktop / Cursor 等）的子进程由本机启动，
 * 不得将 mcp-server.php 暴露为可远程访问的网络服务。
 * 批量工具有内置上限（batch_max_servers，默认 20），超量返回错误。
 */

require __DIR__ . '/src/autoload.php';

use McPing\McpCore;

/** @var array<string, mixed> 全局配置 */
$config = require __DIR__ . '/config/config.php';

/**
 * 工具注册表（统一来自 McpCore，与 HTTP 远程端点保持一致）。
 *
 * @return array<int, array<string, mixed>>
 */
function mcpTools(): array
{
    return McpCore::tools();
}

/**
 * 从 stdin 读取一帧 MCP 消息（Content-Length 帧协议）。
 *
 * @return array<string, mixed>|null 无更多输入时返回 null
 */
function mcpReadMessage(): ?array
{
    $headers = [];
    while (($line = fgets(STDIN)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            break;
        }
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
    }
    $len = isset($headers['content-length']) ? (int)$headers['content-length'] : 0;
    if ($len <= 0 || $len > 10 * 1024 * 1024) {
        return null;
    }
    $body = '';
    while (strlen($body) < $len) {
        $chunk = fread(STDIN, $len - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    $msg = json_decode($body, true);
    return is_array($msg) ? $msg : null;
}

/**
 * 向 stdout 写入一帧 MCP 消息。
 *
 * @param array<string, mixed> $msg JSON-RPC 消息
 */
function mcpWriteMessage(array $msg): void
{
    $json = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    fwrite(STDOUT, 'Content-Length: ' . strlen($json) . "\r\n\r\n" . $json);
    fflush(STDOUT);
}

/**
 * 调用工具并返回 MCP 响应文本（统一来自 McpCore）。
 *
 * @param array<string, mixed> $args
 *
 * @return array{content: list<array{type: string, text: string}>, isError: bool}
 */
function mcpCallTool(string $name, array $args, array $config): array
{
    return McpCore::call($name, $args, $config);
}

/**
 * 服务端版本号（来自 config['ui']['version']，剥离前导字母前缀，如 'v1.5' -> '1.5'）。
 *
 * @param array<string, mixed> $config 全局配置
 */
function mcpServerVersion(array $config): string
{
    $v = (string)($config['ui']['version'] ?? '1.0.0');
    $cleaned = preg_replace('/^[^0-9]+/', '', $v);
    return ($cleaned !== null && $cleaned !== '') ? $cleaned : '1.0.0';
}

/** 主循环：读取请求、分发、写响应。 */
function mcpRunLoop(array $config): void
{
    while (($msg = mcpReadMessage()) !== null) {
        $method = is_string($msg['method'] ?? null) ? $msg['method'] : '';
        $id = $msg['id'] ?? null;

        // 通知类消息：无需响应
        if ($id === null) {
            continue;
        }

        if ($method === 'initialize') {
            mcpWriteMessage([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'mc-server-api', 'version' => mcpServerVersion($config)],
                ],
            ]);
            continue;
        }

        if ($method === 'ping') {
            mcpWriteMessage(['jsonrpc' => '2.0', 'id' => $id, 'result' => []]);
            continue;
        }

        if ($method === 'tools/list') {
            mcpWriteMessage([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => ['tools' => mcpTools()],
            ]);
            continue;
        }

        if ($method === 'tools/call') {
            $name = is_string($msg['params']['name'] ?? null) ? $msg['params']['name'] : '';
            $arguments = is_array($msg['params']['arguments'] ?? null) ? $msg['params']['arguments'] : [];
            $result = mcpCallTool($name, $arguments, $config);
            mcpWriteMessage(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
            continue;
        }

        // 未识别方法：返回 JSON-RPC 错误
        mcpWriteMessage([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32601, 'message' => '方法未找到：' . $method],
        ]);
    }
}

mcpRunLoop($config);
