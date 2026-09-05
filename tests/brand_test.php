<?php

declare(strict_types=1);

use McPing\BrandDetector;

/**
 * 服务器核心识别单元测试。
 */

$tests = [];

$tests['Paper'] = function (): void {
    assertSame('Paper', BrandDetector::detect('Paper 1.20.4', null));
};

$tests['Spigot'] = function (): void {
    assertSame('Spigot', BrandDetector::detect('Spigot 1.20.4', null));
};

$tests['Spigot git 版本'] = function (): void {
    assertSame('Spigot', BrandDetector::detect('git-Spigot-3194', null));
};

$tests['Purpur'] = function (): void {
    assertSame('Purpur', BrandDetector::detect('Purpur 1.20.4', null));
};

$tests['Fabric'] = function (): void {
    assertSame('Fabric', BrandDetector::detect('Fabric 1.20.4', null));
};

$tests['Forge 后缀'] = function (): void {
    assertSame('Forge', BrandDetector::detect('1.20.1-Forge', null));
};

$tests['NeoForge'] = function (): void {
    assertSame('NeoForge', BrandDetector::detect('1.20.4-NeoForge', null));
};

$tests['BungeeCord'] = function (): void {
    assertSame('BungeeCord', BrandDetector::detect('BungeeCord 1.20.4', null));
};

$tests['Velocity'] = function (): void {
    assertSame('Velocity', BrandDetector::detect('Velocity 3.3.0', null));
};

$tests['Vanilla 纯版本号'] = function (): void {
    assertSame('Vanilla', BrandDetector::detect('1.20.4', null));
};

$tests['Vanilla 带前缀'] = function (): void {
    assertSame('Vanilla', BrandDetector::detect('Vanilla 1.20.4', null));
};

$tests['Vanilla 快照版'] = function (): void {
    assertSame('Vanilla', BrandDetector::detect('23w31a', null));
};

$tests['未知核心返回 null'] = function (): void {
    assertSame(null, BrandDetector::detect('SuperDuper 1.0', null));
};

$tests['从 MOTD 识别 Paper'] = function (): void {
    assertSame('Paper', BrandDetector::detect(null, 'Welcome to our Paper server'));
};

$tests['从 MOTD 识别 Fabric'] = function (): void {
    assertSame('Fabric', BrandDetector::detect(null, 'A Fabric server'));
};

$tests['空输入返回 null'] = function (): void {
    assertSame(null, BrandDetector::detect(null, null));
};

$tests['版本明确时优先于 MOTD 关键词'] = function (): void {
    assertSame('Vanilla', BrandDetector::detect('1.20.4', 'Join our Paper-like server'));
};

return $tests;
