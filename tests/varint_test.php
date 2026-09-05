<?php

declare(strict_types=1);

use McPing\VarInt;

/**
 * VarInt 编解码单元测试。
 */

$tests = [];

$tests['encode 0'] = function (): void {
    assertSame("\x00", VarInt::encode(0));
};

$tests['encode 1'] = function (): void {
    assertSame("\x01", VarInt::encode(1));
};

$tests['encode 127'] = function (): void {
    assertSame("\x7f", VarInt::encode(127));
};

$tests['encode 128'] = function (): void {
    assertSame("\x80\x01", VarInt::encode(128));
};

$tests['encode 255'] = function (): void {
    assertSame("\xff\x01", VarInt::encode(255));
};

$tests['encode 300'] = function (): void {
    assertSame("\xac\x02", VarInt::encode(300));
};

$tests['encode 2147483647'] = function (): void {
    assertSame("\xff\xff\xff\xff\x07", VarInt::encode(2147483647));
};

$tests['encode -1 为 5 字节'] = function (): void {
    assertSame("\xff\xff\xff\xff\x0f", VarInt::encode(-1));
};

$tests['decode -1 按有符号解释'] = function (): void {
    $offset = 0;
    assertSame(-1, VarInt::decode("\xff\xff\xff\xff\x0f", $offset));
    assertSame(5, $offset);
};

$tests['decode 2147483647'] = function (): void {
    $offset = 0;
    assertSame(2147483647, VarInt::decode("\xff\xff\xff\xff\x07", $offset));
};

$tests['decode 偏移量自动前进'] = function (): void {
    $data = "\x01\x02\x03";
    $offset = 0;
    assertSame(1, VarInt::decode($data, $offset));
    assertSame(1, $offset);
    assertSame(2, VarInt::decode($data, $offset));
    assertSame(2, $offset);
};

$tests['正数随机往返'] = function (): void {
    for ($i = 0; $i < 1000; $i++) {
        $value = random_int(0, 2147483647);
        $offset = 0;
        assertSame($value, VarInt::decode(VarInt::encode($value), $offset));
    }
};

$tests['负数往返'] = function (): void {
    foreach ([-1, -2, -100, -2147483648] as $value) {
        $offset = 0;
        assertSame($value, VarInt::decode(VarInt::encode($value), $offset));
    }
};

$tests['writeString/readString 往返'] = function (): void {
    $data = VarInt::writeString('hello world');
    $offset = 0;
    assertSame('hello world', VarInt::readString($data, $offset));
    assertSame(strlen($data), $offset);
};

$tests['readString 空字符串'] = function (): void {
    $offset = 0;
    assertSame('', VarInt::readString("\x00", $offset));
};

$tests['readString 数据不足抛异常'] = function (): void {
    $offset = 0;
    try {
        VarInt::readString("\x05ab", $offset);
        throw new \RuntimeException('应当抛出异常');
    } catch (\RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), '不完整'));
    }
};

$tests['decode 不完整抛异常'] = function (): void {
    $offset = 0;
    try {
        VarInt::decode("\x80", $offset);
        throw new \RuntimeException('应当抛出异常');
    } catch (\RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), '不完整'));
    }
};

return $tests;
