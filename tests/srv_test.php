<?php

declare(strict_types=1);

use McPing\PingClient;
use McPing\PingException;

/**
 * SRV 记录自动解析（resolveSrv + ping 兜底编排）测试。
 *
 * 覆盖：
 *   - resolveSrv：命中（target 不同/相同/为空）、未命中（无记录/查询失败）、
 *     IP 字面量跳过、非 SRV 类型过滤、端口越界忽略、多记录按 pri/weight 选优
 *   - ping 编排：直接失败后 SRV 兜底重试、重试仍失败保留原始错误、
 *     关闭 srv_auto_resolve 时不查询 SRV、参数错误不触发 SRV
 *   - finalize 反射：srv_used / srv_record 字段正确写入
 *   - 真实 DNS：mc.eqmemory.cn / mc.goldenapplepie.xyz 命中 SRV
 *
 * 说明：SRV 查询通过 setSrvResolver 注入替身，无需真实网络即可覆盖逻辑分支；
 * 仅"真实 DNS"用例依赖本机 DNS 解析（与既有 pingclient_test.php 的 DNS 用例一致）。
 */

$tests = [];

/**
 * 构造一个固定 SRV 记录的替身解析器（可记录调用）。
 *
 * @param array<int, array<string, mixed>> $records 返回给调用方的记录数组
 * @param string[]|null                    $calls   按引用收集查询名列表
 * @return callable(string): array
 */
function makeFakeResolver(array $records, ?array &$calls = null): callable
{
    $calls = [];
    return static function (string $srvName) use ($records, &$calls): array {
        $calls[] = $srvName;
        return $records;
    };
}

/**
 * 构造一个标准的 SRV 记录条目（dns_get_record 同构）。
 *
 * @return array<string, mixed>
 */
function makeSrvRecord(int $port, string $target, int $pri = 0, int $weight = 5): array
{
    return [
        'host' => '_minecraft._tcp.example.com',
        'class' => 'IN',
        'ttl' => 60,
        'type' => 'SRV',
        'pri' => $pri,
        'weight' => $weight,
        'port' => $port,
        'target' => $target,
    ];
}

$tests['resolveSrv 命中：target 不同用 SRV 目标'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $client->setSrvResolver(makeFakeResolver([makeSrvRecord(11874, 'gapmc.goldenapplepie.xyz')]));
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    $result = $ref->invoke($client, 'mc.goldenapplepie.xyz');
    assertSame('gapmc.goldenapplepie.xyz', $result['host']);
    assertSame(11874, $result['port']);
    assertSame('gapmc.goldenapplepie.xyz', $result['target']);
};

$tests['resolveSrv 命中：target 相同保留原 host'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $client->setSrvResolver(makeFakeResolver([makeSrvRecord(11822, 'mc.eqmemory.cn')]));
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    $result = $ref->invoke($client, 'mc.eqmemory.cn');
    assertSame('mc.eqmemory.cn', $result['host']);
    assertSame(11822, $result['port']);
    assertSame('mc.eqmemory.cn', $result['target']);
};

$tests['resolveSrv 命中：target 为空回退原 host'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $client->setSrvResolver(makeFakeResolver([makeSrvRecord(25565, '')]));
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    $result = $ref->invoke($client, 'example.com');
    assertSame('example.com', $result['host']);
    assertSame(25565, $result['port']);
};

$tests['resolveSrv 命中：target 带尾部点被剥离'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $client->setSrvResolver(makeFakeResolver([makeSrvRecord(11874, 'gapmc.goldenapplepie.xyz.')]));
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    $result = $ref->invoke($client, 'mc.goldenapplepie.xyz');
    assertSame('gapmc.goldenapplepie.xyz', $result['host']);
};

$tests['resolveSrv 命中：多记录按 pri/weight 选优'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $client->setSrvResolver(makeFakeResolver([
        makeSrvRecord(20001, 'backup.example.com', 10, 1),
        makeSrvRecord(20000, 'primary.example.com', 0, 9),
    ]));
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    $result = $ref->invoke($client, 'example.com');
    assertSame('primary.example.com', $result['host']);
    assertSame(20000, $result['port']);
};

$tests['resolveSrv 未命中：无记录返回 null'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $client->setSrvResolver(makeFakeResolver([]));
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    assertSame(null, $ref->invoke($client, 'example.com'));
};

$tests['resolveSrv 未命中：查询失败返回 null'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $client->setSrvResolver(static function (string $srvName): bool {
        return false;
    });
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    assertSame(null, $ref->invoke($client, 'example.com'));
};

$tests['resolveSrv 跳过 IP 字面量'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $calls = [];
    $client->setSrvResolver(makeFakeResolver([makeSrvRecord(25565, 'example.com')], $calls));
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    assertSame(null, $ref->invoke($client, '127.0.0.1'));
    assertSame(null, $ref->invoke($client, '[::1]'));
    assertSame([], $calls, 'IP 字面量不应触发 DNS 查询');
};

$tests['resolveSrv 过滤非 SRV 类型'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $client->setSrvResolver(makeFakeResolver([
        ['type' => 'A', 'ip' => '1.2.3.4'],
        ['type' => 'CNAME', 'target' => 'example.org'],
    ]));
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    assertSame(null, $ref->invoke($client, 'example.com'));
};

$tests['resolveSrv 端口越界被忽略'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $client->setSrvResolver(makeFakeResolver([
        makeSrvRecord(0, 'bad.example.com'),
        makeSrvRecord(70000, 'bad2.example.com'),
        makeSrvRecord(30000, 'good.example.com'),
    ]));
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    $result = $ref->invoke($client, 'example.com');
    assertSame('good.example.com', $result['host']);
    assertSame(30000, $result['port']);
};

$tests['resolveSrv 真实 DNS：mc.eqmemory.cn 命中 11822'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    $result = $ref->invoke($client, 'mc.eqmemory.cn');
    assertTrue(is_array($result), 'mc.eqmemory.cn 应命中 SRV');
    assertSame(11822, $result['port']);
};

$tests['resolveSrv 真实 DNS：mc.goldenapplepie.xyz 命中 11874'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    $result = $ref->invoke($client, 'mc.goldenapplepie.xyz');
    assertTrue(is_array($result), 'mc.goldenapplepie.xyz 应命中 SRV');
    assertSame('gapmc.goldenapplepie.xyz', $result['host']);
    assertSame(11874, $result['port']);
};

$tests['resolveSrv 真实 DNS：无 SRV 记录返回 null'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $ref = new \ReflectionMethod(PingClient::class, 'resolveSrv');
    $ref->setAccessible(true);
    assertSame(null, $ref->invoke($client, 'play.cubecraft.net'));
};

$tests['ping 直接失败后 SRV 兜底重试'] = function (): void {
    $calls = [];
    $client = new PingClient(['timeout_seconds' => 1, 'srv_auto_resolve' => true]);
    // 假 SRV 指向 127.0.0.1:1（本地无服务，重试必然失败）
    $client->setSrvResolver(makeFakeResolver([makeSrvRecord(1, '127.0.0.1')], $calls));
    try {
        $client->ping('nonexistent-host-abc123.invalid', 25565);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1004, $e->getErrorCode(), 'SRV 重试仍失败时保留原始错误');
        assertSame(['_minecraft._tcp.nonexistent-host-abc123.invalid'], $calls, '应仅查询一次 SRV');
    }
};

$tests['ping 关闭 srv_auto_resolve 不查 SRV'] = function (): void {
    $calls = [];
    $client = new PingClient(['timeout_seconds' => 1, 'srv_auto_resolve' => false]);
    $client->setSrvResolver(makeFakeResolver([makeSrvRecord(1, '127.0.0.1')], $calls));
    try {
        $client->ping('nonexistent-host-abc123.invalid', 25565);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1004, $e->getErrorCode());
        assertSame([], $calls, '关闭开关后不应触发 SRV 查询');
    }
};

$tests['ping 参数错误不触发 SRV'] = function (): void {
    $calls = [];
    $client = new PingClient(['timeout_seconds' => 1, 'srv_auto_resolve' => true]);
    $client->setSrvResolver(makeFakeResolver([makeSrvRecord(1, '127.0.0.1')], $calls));
    try {
        $client->ping('', 25565);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1001, $e->getErrorCode());
        assertSame([], $calls, '参数错误不应触发 SRV 查询');
    }
};

$tests['ping SRV 未命中返回原始错误'] = function (): void {
    $client = new PingClient(['timeout_seconds' => 1, 'srv_auto_resolve' => true]);
    // 假 SRV 解析返回空数组（无记录）
    $client->setSrvResolver(makeFakeResolver([]));
    try {
        $client->ping('nonexistent-host-abc123.invalid', 25565);
        throw new \RuntimeException('应当抛出异常');
    } catch (PingException $e) {
        assertSame(1004, $e->getErrorCode());
    }
};

$tests['finalize 写入 srv_used 与 srv_record'] = function (): void {
    $client = new PingClient(['srv_auto_resolve' => true]);
    $ref = new \ReflectionMethod(PingClient::class, 'finalize');
    $ref->setAccessible(true);
    $result = [
        'latency_ms' => 5,
        'version' => ['name' => 'Test 1.21', 'protocol' => 767],
        'players' => ['online' => 1, 'max' => 10, 'sample' => null],
        'motd' => ['raw' => 'x', 'plain_text' => 'x', 'has_legacy_codes' => false],
        'favicon_raw' => null,
        'secure_chat' => ['enforces' => null, 'previews' => null],
    ];
    $srvRecord = ['target' => 'gapmc.example.com', 'port' => 11874];
    $data = $ref->invoke($client, $result, 'example.com', 25565, 'modern', true, $srvRecord);
    assertSame(true, $data['srv_used']);
    assertSame($srvRecord, $data['srv_record']);
    assertSame('example.com', $data['host']);
    assertSame(25565, $data['port']);

    $dataDefault = $ref->invoke($client, $result, 'example.com', 25565, 'modern');
    assertSame(false, $dataDefault['srv_used']);
    assertSame(null, $dataDefault['srv_record']);
};

$tests['默认配置启用 SRV 自动解析'] = function (): void {
    $config = PingClient::defaultConfig();
    assertSame(true, $config['srv_auto_resolve']);
    assertTrue(isset($config['srv_timeout']), '配置应包含 srv_timeout');
};

return $tests;
