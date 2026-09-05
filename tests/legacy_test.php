<?php

declare(strict_types=1);

use McPing\LegacyPing;
use McPing\PingException;

/**
 * Legacy 0xFE 协议响应解析单元测试。
 */

$tests = [];

$tests['解析 1.4+ NUL 分隔格式'] = function (): void {
    $text = "§1\0" . "47\0" . "1.4.6\0" . "A Minecraft Server\0" . "3\0" . "20";
    $result = LegacyPing::parseLegacyPayload($text);
    assertSame(47, $result['version']['protocol']);
    assertSame('1.4.6', $result['version']['name']);
    assertSame('A Minecraft Server', $result['motd']['plain_text']);
    assertSame(3, $result['players']['online']);
    assertSame(20, $result['players']['max']);
    assertSame(null, $result['players']['sample']);
    assertSame(null, $result['favicon_raw']);
};

$tests['解析 1.4+ 带颜色代码 MOTD'] = function (): void {
    $text = "§1\0" . "78\0" . "1.6.4\0" . "§aGreen MOTD§r\0" . "0\0" . "10";
    $result = LegacyPing::parseLegacyPayload($text);
    assertSame('Green MOTD', $result['motd']['plain_text']);
    assertTrue($result['motd']['has_legacy_codes']);
};

$tests['解析 1.3 及更早 § 分隔格式'] = function (): void {
    $text = "§1A Cool Server§12§100";
    $result = LegacyPing::parseLegacyPayload($text);
    assertSame('A Cool Server', $result['motd']['plain_text']);
    assertSame(12, $result['players']['online']);
    assertSame(100, $result['players']['max']);
    assertSame(null, $result['version']['protocol']);
};

$tests['解析带前导 NUL 的响应'] = function (): void {
    $text = "\x00§1\0" . "5\0" . "1.5.2\0" . "Hi\0" . "1\0" . "10";
    $result = LegacyPing::parseLegacyPayload($text);
    assertSame('Hi', $result['motd']['plain_text']);
    assertSame(1, $result['players']['online']);
    assertSame(10, $result['players']['max']);
};

$tests['字段缺失时返回 null'] = function (): void {
    $text = "§1\0" . "47\0" . "1.4.6\0" . "MOTD";
    $result = LegacyPing::parseLegacyPayload($text);
    assertSame('MOTD', $result['motd']['plain_text']);
    assertSame(null, $result['players']['online']);
    assertSame(null, $result['players']['max']);
};

$tests['无法识别格式抛协议错误'] = function (): void {
    try {
        LegacyPing::parseLegacyPayload('random garbage no format');
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1005, $e->getErrorCode());
    }
};

return $tests;
