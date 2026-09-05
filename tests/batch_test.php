<?php

declare(strict_types=1);

use McPing\BatchPinger;
use McPing\Cache;
use McPing\MinecraftPing;
use McPing\PingClient;
use McPing\PingException;

/**
 * 批量并发查询（P0-2）单元测试。
 *
 * 覆盖：参数校验（超限/空/非法条目 -> 1009）、缓存命中、失败条目、
 * SRV 兜底（注入假解析器）、请求字节构造与响应解析（静态方法）。
 * 成功路径依赖真实 MC 服务器，由 API 层 curl 实测覆盖。
 */

Cache::reset();

$tests = [];

$tests['批量数量超过上限抛 1009'] = function (): void {
    $servers = [];
    for ($i = 0; $i < 21; $i++) {
        $servers[] = ['host' => 'h' . $i . '.example.com'];
    }
    $pinger = new BatchPinger(['batch_max_servers' => 20]);
    try {
        $pinger->pingBatch($servers);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1009, $e->getErrorCode());
    }
};

$tests['servers 为空抛 1009'] = function (): void {
    $pinger = new BatchPinger();
    try {
        $pinger->pingBatch([]);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1009, $e->getErrorCode());
    }
};

$tests['条目缺少 host 抛 1009'] = function (): void {
    $pinger = new BatchPinger();
    try {
        $pinger->pingBatch([['port' => 25565]]);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1009, $e->getErrorCode());
    }
};

$tests['条目 host 非法抛 1009'] = function (): void {
    $pinger = new BatchPinger();
    try {
        $pinger->pingBatch([['host' => 'bad/host']]);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1009, $e->getErrorCode());
    }
};

$tests['条目 port 越界抛 1009'] = function (): void {
    $pinger = new BatchPinger();
    try {
        $pinger->pingBatch([['host' => 'example.com', 'port' => 0]]);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1009, $e->getErrorCode());
    }
};

$tests['缓存命中直接返回 cached'] = function (): void {
    Cache::reset();
    Cache::instance()->set('example.com:25565', [
        'online' => true,
        'host' => 'example.com',
        'port' => 25565,
        'latency_ms' => 1,
    ], 30);
    $pinger = new BatchPinger(['cache_enabled' => true]);
    $result = $pinger->pingBatch([['host' => 'example.com', 'port' => 25565]]);
    assertSame(1, $result['total']);
    assertSame(1, $result['success']);
    assertSame(0, $result['failed']);
    assertSame(true, $result['results'][0]['online']);
    assertSame(true, $result['results'][0]['cached'], '命中缓存应带 cached 标记');
};

$tests['不可达服务器为失败条目'] = function (): void {
    Cache::reset();
    $pinger = new BatchPinger(['batch_timeout_seconds' => 3, 'srv_auto_resolve' => false]);
    $result = $pinger->pingBatch([['host' => '127.0.0.1', 'port' => 1]]);
    assertSame(1, $result['total']);
    assertSame(0, $result['success']);
    assertSame(1, $result['failed']);
    $item = $result['results'][0];
    assertSame(false, $item['online']);
    assertTrue(isset($item['error']['code']), '失败条目应带 error.code');
    assertTrue(in_array($item['error']['code'], [1002, 1003, 1006], true), '错误码应为连接类：' . $item['error']['code']);
};

$tests['失败不写入缓存'] = function (): void {
    Cache::reset();
    $pinger = new BatchPinger(['batch_timeout_seconds' => 3, 'srv_auto_resolve' => false]);
    $pinger->pingBatch([['host' => '127.0.0.1', 'port' => 1]]);
    assertFalse(Cache::instance()->has('127.0.0.1:1'), '失败结果不应缓存');
};

$tests['SRV 兜底仍失败保留原始错误'] = function (): void {
    Cache::reset();
    // 假 SRV 指向本地未监听端口；直接连接 DNS 失败(1004)，SRV 重试仍失败应保留 1004
    $calls = [];
    $client = new PingClient(['srv_auto_resolve' => true]);
    $client->setSrvResolver(static function (string $srvName) use (&$calls): array {
        $calls[] = $srvName;
        return [[
            'host' => '_minecraft._tcp.nonexistent.invalid',
            'class' => 'IN',
            'ttl' => 60,
            'type' => 'SRV',
            'pri' => 0,
            'weight' => 5,
            'port' => 1,
            'target' => '127.0.0.1',
        ]];
    });
    $pinger = new BatchPinger(['batch_timeout_seconds' => 3, 'srv_auto_resolve' => true], $client);
    $result = $pinger->pingBatch([['host' => 'nonexistent-host-abc123.invalid', 'port' => 25565]]);
    assertSame(1, $result['failed']);
    assertSame(1004, $result['results'][0]['error']['code'], 'SRV 重试失败保留原始 DNS 错误');
    assertSame(['_minecraft._tcp.nonexistent-host-abc123.invalid'], $calls, '应查询一次 SRV');
};

$tests['buildStatusRequestBytes 结构正确'] = function (): void {
    // 握手包 + 状态请求包：长度前缀逐层校验
    $bytes = MinecraftPing::buildStatusRequestBytes('example.com', 25565, -1);
    // 第一个 varint 长度
    $offset = 0;
    $handshakeLen = \McPing\VarInt::decode($bytes, $offset);
    assertTrue($handshakeLen > 0, '握手包长度应 > 0');
    $handshake = substr($bytes, $offset, $handshakeLen);
    assertSame("\x00", $handshake[0], '握手包 ID 应为 0x00');
    // 剩余为状态请求包：[varint 1][0x00]
    $rest = substr($bytes, $offset + $handshakeLen);
    $restOffset = 0;
    $statusLen = \McPing\VarInt::decode($rest, $restOffset);
    assertSame(1, $statusLen);
    assertSame("\x00", substr($rest, $restOffset, 1), '状态请求包内容应为 0x00');
};

$tests['parseStatusResponseBody 解析正确'] = function (): void {
    $json = json_encode(['description' => ['text' => 'hi'], 'players' => ['online' => 1, 'max' => 10]]);
    $body = "\x00" . \McPing\VarInt::encode(strlen($json)) . $json;
    $parsed = MinecraftPing::parseStatusResponseBody($body);
    assertSame('hi', $parsed['description']['text']);
    assertSame(1, $parsed['players']['online']);
};

$tests['parseStatusResponseBody 包 ID 异常抛 1005'] = function (): void {
    try {
        MinecraftPing::parseStatusResponseBody("\x01\x00");
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1005, $e->getErrorCode());
    }
};

$tests['mapConnectErrorStatic 错误分类'] = function (): void {
    $e = MinecraftPing::mapConnectErrorStatic(111, 'Connection refused', 'h', 1);
    assertSame(1003, $e->getErrorCode());
    $e = MinecraftPing::mapConnectErrorStatic(110, 'Operation timed out', 'h', 1);
    assertSame(1002, $e->getErrorCode());
    $e = MinecraftPing::mapConnectErrorStatic(0, 'php_network_getaddresses: getaddrinfo failed', 'h', 1);
    assertSame(1004, $e->getErrorCode());
};

return $tests;
