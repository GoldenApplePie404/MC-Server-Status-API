<?php

declare(strict_types=1);

use McPing\RateLimiter;

/**
 * 固定窗口限流器（P1-3）单元测试。
 */

RateLimiter::reset();

$tests = [];

$tests['窗口内放行并计数'] = function (): void {
    RateLimiter::reset();
    $r1 = RateLimiter::instance()->consume('ip|/api/ping', 3);
    assertSame(true, $r1['allowed']);
    assertSame(2, $r1['remaining']);
    $r2 = RateLimiter::instance()->consume('ip|/api/ping', 3);
    assertSame(true, $r2['allowed']);
    assertSame(1, $r2['remaining']);
};

$tests['超过上限拒绝'] = function (): void {
    RateLimiter::reset();
    for ($i = 0; $i < 3; $i++) {
        $r = RateLimiter::instance()->consume('ip|/api/ping', 3);
        assertSame(true, $r['allowed']);
    }
    $r = RateLimiter::instance()->consume('ip|/api/ping', 3);
    assertSame(false, $r['allowed']);
    assertSame(0, $r['remaining']);
    assertTrue($r['retry_after'] >= 1, 'retry_after 应 >= 1');
};

$tests['不同桶互不影响'] = function (): void {
    RateLimiter::reset();
    for ($i = 0; $i < 3; $i++) {
        RateLimiter::instance()->consume('ip1|/api/ping', 3);
    }
    $r = RateLimiter::instance()->consume('ip2|/api/ping', 3);
    assertSame(true, $r['allowed']);
    $r = RateLimiter::instance()->consume('ip1|/metrics', 3);
    assertSame(true, $r['allowed'], '不同路由分桶独立');
};

$tests['limit 小于等于 0 恒放行'] = function (): void {
    RateLimiter::reset();
    for ($i = 0; $i < 10; $i++) {
        $r = RateLimiter::instance()->consume('ip|/x', 0);
        assertSame(true, $r['allowed']);
        assertSame(PHP_INT_MAX, $r['remaining']);
    }
};

$tests['remaining 只读查询'] = function (): void {
    RateLimiter::reset();
    assertSame(5, RateLimiter::instance()->remaining('ip|/x', 5));
    RateLimiter::instance()->consume('ip|/x', 5);
    assertSame(4, RateLimiter::instance()->remaining('ip|/x', 5));
};

$tests['validApiKey 空列表视为鉴权关闭'] = function (): void {
    assertSame(true, RateLimiter::validApiKey(null, []));
    assertSame(true, RateLimiter::validApiKey('anything', []));
};

$tests['validApiKey 命中与未命中'] = function (): void {
    $keys = ['secret-1', 'secret-2'];
    assertSame(true, RateLimiter::validApiKey('secret-1', $keys));
    assertSame(true, RateLimiter::validApiKey('secret-2', $keys));
    assertSame(false, RateLimiter::validApiKey('wrong', $keys));
    assertSame(false, RateLimiter::validApiKey('', $keys));
    assertSame(false, RateLimiter::validApiKey(null, $keys));
};

$tests['reset 清空全部桶'] = function (): void {
    RateLimiter::reset();
    for ($i = 0; $i < 3; $i++) {
        RateLimiter::instance()->consume('ip|/x', 3);
    }
    assertSame(false, RateLimiter::instance()->consume('ip|/x', 3)['allowed']);
    RateLimiter::reset();
    assertSame(true, RateLimiter::instance()->consume('ip|/x', 3)['allowed']);
};

return $tests;
