<?php

declare(strict_types=1);

use McPing\MotdParser;

/**
 * MOTD 解析单元测试（传统 § 代码 + Chat Component 对象）。
 */

$tests = [];

$tests['去除传统颜色代码'] = function (): void {
    assertSame('Hello World', MotdParser::stripLegacyCodes("§aHello §bWorld"));
};

$tests['去除粗体代码'] = function (): void {
    assertSame('Bold Text', MotdParser::stripLegacyCodes("§lBold Text"));
};

$tests['去除 RGB 渐变代码'] = function (): void {
    assertSame('Gradient', MotdParser::stripLegacyCodes("§x§F§F§A§A§0§0Gradient"));
};

$tests['RGB 截断 1 组保留剩余文本'] = function (): void {
    // 回归 BUG-1：§x 渐变不足 6 组时，不得吞掉后续普通文本或损坏 UTF-8
    assertSame('剩下的', MotdParser::stripLegacyCodes("§x§F剩下的"));
};

$tests['RGB 截断 2 组保留剩余文本'] = function (): void {
    // 回归 BUG-1：截断的 §x 渐变后紧跟普通 ASCII 文本应完整保留
    assertSame('Hello', MotdParser::stripLegacyCodes("§x§F§FHello"));
};

$tests['去除混合代码'] = function (): void {
    assertSame('A B C', MotdParser::stripLegacyCodes("§aA §bB §cC"));
};

$tests['containsLegacyCode 为真'] = function (): void {
    assertTrue(MotdParser::containsLegacyCode("§aHi"));
};

$tests['containsLegacyCode 为假'] = function (): void {
    assertFalse(MotdParser::containsLegacyCode('plain text'));
};

$tests['解析纯字符串'] = function (): void {
    $result = MotdParser::parse("§lWelcome");
    assertSame('Welcome', $result['plain_text']);
    assertTrue($result['has_legacy_codes']);
    assertSame('§lWelcome', $result['raw']);
};

$tests['解析 Chat Component 基础'] = function (): void {
    $result = MotdParser::parse(['text' => 'Hello ', 'extra' => [['text' => 'World', 'color' => 'red'], ['text' => '!']]]);
    assertSame('Hello World!', $result['plain_text']);
    assertFalse($result['has_legacy_codes']);
};

$tests['解析嵌套 extra'] = function (): void {
    $result = MotdParser::parse(['text' => 'A', 'extra' => [['text' => 'B', 'extra' => [['text' => 'C']]]]]);
    assertSame('ABC', $result['plain_text']);
};

$tests['解析 translate 带 with 参数'] = function (): void {
    $result = MotdParser::parse(['translate' => 'chat.type.text', 'with' => [['text' => 'Steve'], 'Hello']]);
    assertSame('<Steve> Hello', $result['plain_text']);
};

$tests['解析 translate 单参数'] = function (): void {
    $result = MotdParser::parse(['translate' => 'multiplayer.player.joined', 'with' => [['text' => 'Alex']]]);
    assertSame('Alex joined the game', $result['plain_text']);
};

$tests['解析数字 text'] = function (): void {
    assertSame('123', MotdParser::parse(['text' => 123])['plain_text']);
};

$tests['解析布尔 text'] = function (): void {
    assertSame('true', MotdParser::parse(['text' => true])['plain_text']);
};

$tests['解析组件列表'] = function (): void {
    $result = MotdParser::parse([['text' => 'Line1'], ['text' => 'Line2']]);
    assertSame('Line1Line2', $result['plain_text']);
};

$tests['解析 null'] = function (): void {
    $result = MotdParser::parse(null);
    assertSame(null, $result['plain_text']);
};

$tests['组件内嵌传统代码'] = function (): void {
    $result = MotdParser::parse(['text' => "§cRed", 'extra' => [['text' => ' and normal']]]);
    assertSame('Red and normal', $result['plain_text']);
    assertTrue($result['has_legacy_codes']);
};

$tests['解析 keybind'] = function (): void {
    $result = MotdParser::parse(['keybind' => 'key.jump']);
    assertSame('key.jump', $result['plain_text']);
};

return $tests;
