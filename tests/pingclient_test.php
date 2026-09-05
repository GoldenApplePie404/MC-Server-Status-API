<?php

declare(strict_types=1);

use McPing\PingClient;
use McPing\PingException;

/**
 * PingClient 门面（输入校验 + 错误码映射）集成测试。
 *
 * 说明：以下测试会真实发起网络连接。
 *   - DNS 失败测试使用 RFC 2606 保留域名 .invalid，必定解析失败；
 *   - 连接拒绝测试使用 127.0.0.1:1（本地通常无服务监听，快速返回拒绝）。
 */

$tests = [];

$tests['host 为空抛 1001'] = function (): void {
    $client = new PingClient(['timeout_seconds' => 2]);
    try {
        $client->ping('', 25565);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1001, $e->getErrorCode());
    }
};

$tests['host 为空白抛 1001'] = function (): void {
    $client = new PingClient(['timeout_seconds' => 2]);
    try {
        $client->ping("   \t ", 25565);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1001, $e->getErrorCode());
    }
};

$tests['host 超长抛 1001'] = function (): void {
    $client = new PingClient(['timeout_seconds' => 2]);
    try {
        $client->ping(str_repeat('a', 256), 25565);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1001, $e->getErrorCode());
    }
};

$tests['host 含非法字符抛 1001'] = function (): void {
    $client = new PingClient(['timeout_seconds' => 2]);
    try {
        $client->ping('bad/host', 25565);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1001, $e->getErrorCode());
    }
};

$tests['port 为 0 抛 1001'] = function (): void {
    $client = new PingClient(['timeout_seconds' => 2]);
    try {
        $client->ping('localhost', 0);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1001, $e->getErrorCode());
    }
};

$tests['port 超范围抛 1001'] = function (): void {
    $client = new PingClient(['timeout_seconds' => 2]);
    try {
        $client->ping('localhost', 65536);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1001, $e->getErrorCode());
    }
};

$tests['DNS 失败映射 1004'] = function (): void {
    $client = new PingClient(['timeout_seconds' => 2]);
    try {
        $client->ping('nonexistent-host-abc123.invalid', 25565);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1004, $e->getErrorCode());
    }
};

$tests['本地端口未监听返回拒绝或超时'] = function (): void {
    $client = new PingClient(['timeout_seconds' => 1]);
    try {
        // 本地未监听端口：正常环境返回连接被拒绝(1003)；
        // 个别沙箱/防火墙环境 SYN 被丢弃时表现为超时(1002)，两者均属"不可达"友好错误
        $client->ping('127.0.0.1', 1);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertTrue(in_array($e->getErrorCode(), [1002, 1003], true), '期望 1002 或 1003，实际 ' . $e->getErrorCode());
    }
};

return $tests;
