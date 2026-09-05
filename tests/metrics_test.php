<?php

declare(strict_types=1);

use McPing\Cache;
use McPing\Metrics;

/**
 * 状态统计 指标采集器（P1-4）单元测试。
 */

Metrics::reset();
Cache::reset();
Metrics::init();

$tests = [];

$tests['increment 与 export 基本格式'] = function (): void {
    Metrics::reset();
    Metrics::init();
    Metrics::increment('http_requests_total', ['route' => '/api/ping']);
    Metrics::increment('http_requests_total', ['route' => '/api/ping']);
    $text = Metrics::export();
    assertTrue(str_contains($text, '# TYPE http_requests_total counter'), '应输出 TYPE 头');
    assertTrue(str_contains($text, 'http_requests_total{route="/api/ping"} 2'), '应包含计数 2：' . $text);
};

$tests['http_errors_total 标签'] = function (): void {
    Metrics::reset();
    Metrics::init();
    Metrics::increment('http_errors_total', ['code' => '1001']);
    $text = Metrics::export();
    assertTrue(str_contains($text, 'http_errors_total{code="1001"} 1'), '应包含错误码标签');
};

$tests['gauge 输出'] = function (): void {
    Metrics::reset();
    Metrics::init();
    Metrics::gauge('active_batch_requests', 3.0);
    $text = Metrics::export();
    assertTrue(str_contains($text, '# TYPE active_batch_requests gauge'), '应输出 gauge TYPE');
    assertTrue(str_contains($text, 'active_batch_requests 3'), '应包含仪表值');
};

$tests['动态指标 cache 与 uptime'] = function (): void {
    Metrics::reset();
    Metrics::init();
    Cache::reset();
    Cache::instance()->set('k', 1, 30);
    Cache::instance()->has('k');
    Cache::instance()->has('miss');
    $text = Metrics::export();
    assertTrue(str_contains($text, 'cache_hits_total 1'), '应包含缓存命中');
    assertTrue(str_contains($text, 'cache_misses_total 1'), '应包含缓存未命中');
    assertTrue(str_contains($text, 'uptime_seconds'), '应包含 uptime_seconds');
};

$tests['标签值转义'] = function (): void {
    Metrics::reset();
    Metrics::init();
    Metrics::increment('http_requests_total', ['route' => '/a"b\\c']);
    $text = Metrics::export();
    assertTrue(str_contains($text, 'route="/a\"b\\\\c"'), '标签值应转义反斜杠与引号：' . $text);
};

$tests['数字格式化整数无小数点'] = function (): void {
    Metrics::reset();
    Metrics::init();
    Metrics::increment('x_total', [], 5);
    $text = Metrics::export();
    assertTrue(str_contains($text, 'x_total 5'), '整数应无小数点');
};

$tests['reset 清空全部'] = function (): void {
    Metrics::reset();
    Metrics::increment('a_total');
    Metrics::gauge('b_gauge', 1.0);
    Metrics::reset();
    $text = Metrics::export();
    assertFalse(str_contains($text, 'a_total'), 'reset 后不应包含旧计数');
    assertFalse(str_contains($text, 'b_gauge'), 'reset 后不应包含旧仪表');
};

return $tests;
