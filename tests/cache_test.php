<?php

declare(strict_types=1);

use McPing\Cache;

/**
 * 进程内内存 TTL 缓存（P0-1）单元测试。
 */

Cache::reset();

$tests = [];

$tests['set/get 基本往返'] = function (): void {
    $cache = Cache::instance();
    $cache->clear();
    $cache->set('key-a', ['online' => true, 'host' => 'a'], 30);
    $data = $cache->get('key-a');
    assertTrue(is_array($data), '应取回数组');
    assertSame(true, $data['online']);
    assertSame('a', $data['host']);
};

$tests['get 未命中返回 null'] = function (): void {
    $cache = Cache::instance();
    $cache->clear();
    assertSame(null, $cache->get('not-exists'));
};

$tests['has 命中与未命中'] = function (): void {
    $cache = Cache::instance();
    $cache->clear();
    assertFalse($cache->has('x'));
    $cache->set('x', 1, 30);
    assertTrue($cache->has('x'));
};

$tests['TTL 过期后不可读'] = function (): void {
    $cache = Cache::instance();
    $cache->clear();
    $cache->set('expire', 'v', 1);
    assertTrue($cache->has('expire'));
    usleep(1100000); // 1.1 秒，确保超过 1 秒 TTL
    assertFalse($cache->has('expire'));
    assertSame(null, $cache->get('expire'));
};

$tests['TTL 小于等于 0 视为不缓存'] = function (): void {
    $cache = Cache::instance();
    $cache->clear();
    $cache->set('zero', 'v', 0);
    assertFalse($cache->has('zero'));
    $cache->set('neg', 'v', -5);
    assertFalse($cache->has('neg'));
};

$tests['delete 删除指定键'] = function (): void {
    $cache = Cache::instance();
    $cache->clear();
    $cache->set('del', 'v', 30);
    assertTrue($cache->has('del'));
    $cache->delete('del');
    assertFalse($cache->has('del'));
};

$tests['clear 清空全部'] = function (): void {
    $cache = Cache::instance();
    $cache->clear();
    $cache->set('a', 1, 30);
    $cache->set('b', 2, 30);
    assertSame(2, $cache->count());
    $cache->clear();
    assertSame(0, $cache->count());
};

$tests['stats 命中与未命中计数'] = function (): void {
    $cache = Cache::instance();
    $cache->clear();
    $before = $cache->stats();
    $cache->has('miss-1');
    $cache->has('miss-2');
    $cache->set('hit', 1, 30);
    $cache->has('hit');
    $after = $cache->stats();
    assertSame($before['misses'] + 2, $after['misses']);
    assertSame($before['hits'] + 1, $after['hits']);
};

$tests['reset 重置单例'] = function (): void {
    Cache::instance()->set('r', 1, 30);
    Cache::reset();
    $fresh = Cache::instance();
    $stats = $fresh->stats();
    assertSame(0, $stats['hits']);
    assertSame(0, $stats['misses']);
    assertFalse($fresh->has('r'));
    assertSame(0, $fresh->count());
};

return $tests;
