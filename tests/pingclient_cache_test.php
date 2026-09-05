<?php

declare(strict_types=1);

use McPing\Cache;
use McPing\PingClient;
use McPing\PingException;

/**
 * PingClient 缓存集成（P0-1）单元测试。
 *
 * 覆盖：
 *   - 命中缓存直接返回 cached=true（预置缓存，无需真实网络）；
 *   - 查询失败不写入缓存；
 *   - 关闭 cache_enabled 时不读取缓存；
 *   - cacheKeyFor 规范化。
 */

Cache::reset();

$tests = [];

$tests['缓存命中返回 cached=true'] = function (): void {
    Cache::reset();
    $fakeData = [
        'online' => true,
        'host' => 'example.com',
        'port' => 25565,
        'latency_ms' => 5,
        'version' => ['name' => 'Test', 'protocol' => 767, 'brand' => 'Vanilla'],
        'players' => ['online' => 1, 'max' => 10, 'sample' => null],
        'motd' => ['raw' => 'x', 'plain_text' => 'x', 'has_legacy_codes' => false, 'html' => 'x'],
        'favicon' => ['base64' => null, 'saved_path' => null, 'url' => null],
        'protocol_used' => 'modern',
        'secure_chat' => ['enforces' => null, 'previews' => null],
        'srv_used' => false,
        'srv_record' => null,
    ];
    Cache::instance()->set('example.com:25565', $fakeData, 30);

    $client = new PingClient(['cache_enabled' => true, 'timeout_seconds' => 1]);
    $data = $client->ping('example.com', 25565);
    assertSame(true, $data['online']);
    assertSame(true, $data['cached'], '命中缓存应带 cached 标记');
    assertSame(5, $data['latency_ms']);
};

$tests['缓存值本身不含 cached 标记'] = function (): void {
    Cache::reset();
    $client = new PingClient(['cache_enabled' => true, 'timeout_seconds' => 1]);
    // 预置无 cached 的缓存值，二次读取仍只附加一次
    Cache::instance()->set('example.com:25565', ['online' => true, 'host' => 'example.com'], 30);
    $first = $client->ping('example.com', 25565);
    assertSame(true, $first['cached']);
    $second = $client->ping('example.com', 25565);
    assertSame(true, $second['cached']);
    // 存储的原始值不应被污染
    $stored = Cache::instance()->get('example.com:25565');
    assertFalse(array_key_exists('cached', $stored), '缓存存储值不应含 cached 字段');
};

$tests['关闭缓存时不命中'] = function (): void {
    Cache::reset();
    Cache::instance()->set('example.com:25565', ['online' => true], 30);
    $client = new PingClient(['cache_enabled' => false, 'timeout_seconds' => 1]);
    try {
        $client->ping('example.com', 25565);
        throw new \RuntimeException('关闭缓存后应发起真实查询并失败');
    } catch (PingException $e) {
        // 真实查询 example.com:25565 失败（网络错误）即证明未走缓存
        assertTrue(in_array($e->getErrorCode(), [1002, 1003, 1004, 1005, 1006], true), '错误码 ' . $e->getErrorCode());
    }
};

$tests['查询失败不写入缓存'] = function (): void {
    Cache::reset();
    $client = new PingClient(['cache_enabled' => true, 'timeout_seconds' => 1]);
    try {
        $client->ping('127.0.0.1', 1);
    } catch (PingException $e) {
        // 预期失败
    }
    assertFalse(Cache::instance()->has('127.0.0.1:1'), '失败结果不应缓存');
};

$tests['cacheKeyFor 规范化'] = function (): void {
    assertSame('example.com:25565', PingClient::cacheKeyFor('Example.COM', 25565));
    assertSame('[::1]:25565', PingClient::cacheKeyFor('[::1]', 25565));
};

return $tests;
