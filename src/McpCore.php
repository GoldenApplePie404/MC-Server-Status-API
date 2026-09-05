<?php

declare(strict_types=1);

namespace McPing;

/**
 * MCP 共享核心：工具注册表 + 工具调用。
 *
 * 供两个 MCP 传输复用，保证工具清单与查询行为完全一致：
 *   - mcp-server.php    （stdio，本机 AI 客户端）
 *   - McpHttpServer     （Streamable HTTP，远程 AI 客户端）
 *
 * 纯只读查询端：不调用 Cache::configure / Monitor::init / Metrics::configure /
 * WebhookNotifier::configure，因此不写盘、不入库、不发通知，与 HTTP 侧数据隔离。
 */
final class McpCore
{
    /**
     * 工具注册表（name / description / inputSchema）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tools(): array
    {
        return [
            [
                'name' => 'ping_server',
                'description' => '查询一个 Minecraft Java 版服务器的实时状态，返回：是否在线、在线/最大人数、MOTD（含纯文本）、版本名称与协议号、服务器核心（Paper/Spigot/Fabric 等）、延迟毫秒、favicon 地址、Secure Chat 与 SRV 信息。服务器离线或不可达时返回 isError 与可读错误信息。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'host' => ['type' => 'string', 'description' => '服务器地址，如 mc.goldenapplepie.xyz'],
                        'port' => ['type' => 'integer', 'description' => '端口（默认 25565）', 'minimum' => 1, 'maximum' => 65535],
                    ],
                    'required' => ['host'],
                ],
            ],
            [
                'name' => 'ping_batch',
                'description' => '并发批量查询多个 Minecraft Java 版服务器（最多 20 台）。返回每台的在线状态、玩家数、版本、延迟；单台失败时该项带 error.code 与 error.message，不影响其余服务器。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'servers' => [
                            'type' => 'array',
                            'description' => '服务器列表，每项含 host（必填）与可选 port',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'host' => ['type' => 'string', 'description' => '服务器地址'],
                                    'port' => ['type' => 'integer', 'description' => '端口（默认 25565）', 'minimum' => 1, 'maximum' => 65535],
                                ],
                                'required' => ['host'],
                            ],
                        ],
                    ],
                    'required' => ['servers'],
                ],
            ],
        ];
    }

    /**
     * 调用工具并返回 MCP 响应结构（content / isError）。
     *
     * @param array<string, mixed> $args
     *
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    public static function call(string $name, array $args, array $config): array
    {
        $ok = static fn (array $data): array => [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]],
            'isError' => false,
        ];
        $err = static function (string $message, ?int $code = null): array {
            $detail = $code !== null ? ['code' => $code, 'message' => $message] : ['message' => $message];
            return [
                'content' => [['type' => 'text', 'text' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]],
                'isError' => true,
            ];
        };

        try {
            if ($name === 'ping_server') {
                $host = isset($args['host']) && is_string($args['host']) ? trim($args['host']) : '';
                if ($host === '') {
                    return $err('参数 host 必填且不能为空');
                }
                $port = isset($args['port']) ? (int)$args['port'] : (int)($config['default_port'] ?? 25565);
                $client = new PingClient($config);
                return $ok($client->ping($host, $port));
            }

            if ($name === 'ping_batch') {
                $rawServers = $args['servers'] ?? null;
                if (!is_array($rawServers) || $rawServers === []) {
                    return $err('参数 servers 必须是包含至少一个服务器的数组');
                }
                $servers = [];
                foreach ($rawServers as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $h = isset($item['host']) && is_string($item['host']) ? trim($item['host']) : '';
                    if ($h === '') {
                        continue;
                    }
                    $servers[] = [
                        'host' => $h,
                        'port' => isset($item['port']) ? (int)$item['port'] : (int)($config['default_port'] ?? 25565),
                    ];
                }
                if ($servers === []) {
                    return $err('服务器列表为空或格式无效');
                }
                $pinger = new BatchPinger($config);
                return $ok($pinger->pingBatch($servers));
            }

            return $err('未知工具：' . $name);
        } catch (PingException $e) {
            return $err($e->getMessage(), $e->getErrorCode());
        } catch (\Throwable $e) {
            return $err('查询失败：' . $e->getMessage());
        }
    }
}