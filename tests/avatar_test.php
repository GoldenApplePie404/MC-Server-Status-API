<?php

declare(strict_types=1);

use McPing\AvatarProxy;
use McPing\PingClient;

/**
 * 玩家头像代理（P1-2）单元测试。
 *
 * 覆盖：UUID 格式校验、PNG 魔数校验、上游抓取（file:// 本地文件模拟）与
 * 本地缓存读写（命中缓存不再访问上游）。真实 HTTP 上游由 API 层 curl 实测。
 */

$tests = [];

$tests['isValidUuid 32 位 hex'] = function (): void {
    assertTrue(AvatarProxy::isValidUuid('069a79f444e94726a5befca90e38aaf5'));
    assertTrue(AvatarProxy::isValidUuid('069A79F444E94726A5BEFCA90E38AAF5'));
};

$tests['isValidUuid 标准 36 位带横杠'] = function (): void {
    assertTrue(AvatarProxy::isValidUuid('069a79f4-44e9-4726-a5be-fca90e38aaf5'));
};

$tests['isValidUuid 非法输入'] = function (): void {
    assertFalse(AvatarProxy::isValidUuid(''));
    assertFalse(AvatarProxy::isValidUuid('not-a-uuid'));
    assertFalse(AvatarProxy::isValidUuid('069a79f444e94726a5befca90e38aaf'));  // 31 位
    assertFalse(AvatarProxy::isValidUuid('069a79f4-44e9-4726-a5be-fca90e38aaf')); // 缺一段
    assertFalse(AvatarProxy::isValidUuid('069a79f444e94726a5befca90e38aafzz')); // 含非法字符
    assertFalse(AvatarProxy::isValidUuid('../../etc/passwd'));
};

$tests['isPng 校验魔数'] = function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
    assertTrue(AvatarProxy::isPng((string)$png));
    assertFalse(AvatarProxy::isPng('<html>404</html>'));
    assertFalse(AvatarProxy::isPng(''));
    assertFalse(AvatarProxy::isPng('short'));
};

$tests['fetch 上游抓取并写本地缓存'] = function (): void {
    $tempDir = sys_get_temp_dir() . '/mcapi_avatar_test_' . bin2hex(random_bytes(4));
    @mkdir($tempDir, 0755, true);
    $cacheDir = $tempDir . '/avatars';
    $upstreamDir = $tempDir . '/upstream';
    @mkdir($upstreamDir, 0755, true);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
    $uuid = '069a79f444e94726a5befca90e38aaf5';
    file_put_contents($upstreamDir . '/' . $uuid . '.png', $png);

    $template = 'file:///' . ltrim(str_replace('\\', '/', $upstreamDir), '/') . '/{uuid}.png';
    $config = [
        'avatar_api_template' => $template,
        'avatar_cache_dir' => $cacheDir,
        'avatar_cache_ttl_hours' => 24,
    ];

    $result = AvatarProxy::fetch($uuid, $config);
    assertTrue(is_string($result) && AvatarProxy::isPng($result), '应抓取到合法 PNG');

    // 缓存文件已写入
    $cacheFile = $cacheDir . '/' . hash('sha256', $uuid) . '.png';
    assertTrue(is_file($cacheFile), '本地缓存文件应存在');
    assertSame($png, (string)file_get_contents($cacheFile), '缓存内容应与上游一致');

    // 第二次抓取：篡改上游文件后仍应命中缓存（返回原始内容）
    file_put_contents($upstreamDir . '/' . $uuid . '.png', 'corrupted');
    $second = AvatarProxy::fetch($uuid, $config);
    assertSame($png, $second, '命中缓存应返回缓存内容而非上游');

    // 清理
    @unlink($cacheFile);
    @unlink($upstreamDir . '/' . $uuid . '.png');
    @rmdir($cacheDir);
    @rmdir($upstreamDir);
    @rmdir($tempDir);
};

$tests['fetch 上游返回非 PNG 不缓存'] = function (): void {
    $tempDir = sys_get_temp_dir() . '/mcapi_avatar_test_' . bin2hex(random_bytes(4));
    @mkdir($tempDir, 0755, true);
    $cacheDir = $tempDir . '/avatars';
    $upstreamDir = $tempDir . '/upstream';
    @mkdir($upstreamDir, 0755, true);

    $uuid = '069a79f444e94726a5befca90e38aaf5';
    file_put_contents($upstreamDir . '/' . $uuid . '.png', '<html>404 Not Found</html>');

    $template = 'file:///' . ltrim(str_replace('\\', '/', $upstreamDir), '/') . '/{uuid}.png';
    $config = [
        'avatar_api_template' => $template,
        'avatar_cache_dir' => $cacheDir,
        'avatar_cache_ttl_hours' => 24,
    ];

    assertSame(null, AvatarProxy::fetch($uuid, $config), '非 PNG 上游应返回 null');
    $cacheFile = $cacheDir . '/' . hash('sha256', $uuid) . '.png';
    assertFalse(is_file($cacheFile), '非 PNG 不应写缓存');

    // 清理
    @unlink($upstreamDir . '/' . $uuid . '.png');
    @rmdir($cacheDir);
    @rmdir($upstreamDir);
    @rmdir($tempDir);
};

$tests['fetch 模板缺少 {uuid} 占位符返回 null'] = function (): void {
    assertSame(null, AvatarProxy::fetch('069a79f444e94726a5befca90e38aaf5', [
        'avatar_api_template' => 'https://minotar.net/avatar/static.png',
        'avatar_cache_dir' => '',
    ]));
};

$tests['PingClient defaultConfig 含新配置'] = function (): void {
    $config = PingClient::defaultConfig();
    assertSame(true, $config['cache_enabled']);
    assertSame(30, $config['cache_ttl_seconds']);
    assertSame(20, $config['batch_max_servers']);
    assertSame(10.0, $config['batch_timeout_seconds']);
    assertSame(true, $config['monitor_enabled']);
    assertSame([], $config['api_keys']);
    assertSame(true, $config['rate_limit_enabled']);
    assertSame(60, $config['rate_limit_per_minute']);
    assertTrue(str_contains((string)$config['avatar_api_template'], '{uuid}'), '头像模板应含 {uuid} 占位符');
    assertSame(false, $config['trust_proxy_headers']);
};

return $tests;
