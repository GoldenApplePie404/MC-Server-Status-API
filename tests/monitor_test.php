<?php

declare(strict_types=1);

use McPing\Monitor;

/**
 * SQLite 可用性监控（P0-3）单元测试。
 *
 * 依赖 pdo_sqlite 扩展（本机已启用）；未启用时跳过用例（返回空数组）。
 * 每个用例使用独立临时目录，避免 PDO 连接持有旧 DB 文件导致数据串扰。
 */

if (!extension_loaded('pdo_sqlite')) {
    // 扩展缺失：Monitor 应自动禁用且不崩溃（由 probe 与 API 层保证），此处直接跳过
    return [];
}

$tests = [];

/**
 * 创建独立临时监控目录，返回 [配置数组, 清理函数]。
 *
 * @return array{0: array<string, mixed>, 1: callable}
 */
function monitorTestEnv(): array
{
    $tempDir = sys_get_temp_dir() . '/mcapi_monitor_test_' . bin2hex(random_bytes(4));
    return [
        [
            'monitor_enabled' => true,
            'monitor_db_path' => $tempDir . '/monitor.sqlite',
            'monitor_history_days' => 30,
        ],
        static function () use ($tempDir): void {
            $files = glob($tempDir . '/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($tempDir);
        },
    ];
}

$tests['monitor_enabled=false 时禁用'] = function (): void {
    $tempDir = sys_get_temp_dir() . '/mcapi_monitor_test_' . bin2hex(random_bytes(4));
    Monitor::reset();
    Monitor::init([
        'monitor_enabled' => false,
        'monitor_db_path' => $tempDir . '/monitor.sqlite',
    ]);
    assertFalse(Monitor::isEnabled(), '关闭开关后应禁用');
    Monitor::record('example.com', 25565, ['online' => true]);
    assertSame([], Monitor::query('example.com', 50), '禁用时查询返回空');
    @unlink($tempDir . '/monitor.sqlite');
    @rmdir($tempDir);
};

$tests['记录成功与失败并查询'] = function (): void {
    [$config, $cleanup] = monitorTestEnv();
    Monitor::reset();
    Monitor::init($config);
    assertTrue(Monitor::isEnabled(), '启用后应可用');

    Monitor::record('mc.example.com', 25565, [
        'online' => true,
        'latency_ms' => 42,
        'players' => ['online' => 10, 'max' => 100],
        'version' => ['protocol' => 767],
        'protocol_used' => 'modern',
    ]);
    Monitor::record('mc.example.com', 25565, null); // 失败记录

    $records = Monitor::query('mc.example.com', 50);
    assertSame(2, count($records), '应有 2 条记录');

    $first = $records[0]; // 时间倒序，最新在前（失败记录）
    assertSame(0, (int)$first['online']);
    assertSame('mc.example.com', $first['host']);
    assertSame(25565, (int)$first['port']);

    $second = $records[1];
    assertSame(1, (int)$second['online']);
    assertSame(42, (int)$second['latency_ms']);
    assertSame(10, (int)$second['online_players']);
    assertSame(100, (int)$second['max_players']);
    assertSame(767, (int)$second['protocol']);
    assertSame('modern', $second['protocol_used']);
    $cleanup();
};

$tests['查询大小写不敏感与 limit 约束'] = function (): void {
    [$config, $cleanup] = monitorTestEnv();
    Monitor::reset();
    Monitor::init($config);
    for ($i = 0; $i < 5; $i++) {
        Monitor::record('MC.EXAMPLE.com', 25565, ['online' => true, 'latency_ms' => $i]);
    }
    $records = Monitor::query('mc.example.com', 3);
    assertSame(3, count($records), 'limit 应生效');
    $recordsAll = Monitor::query('mc.example.com', 200);
    assertSame(5, count($recordsAll), '大小写不敏感应命中全部');
    $cleanup();
};

$tests['记录字段含 protocol_used 与空值容错'] = function (): void {
    [$config, $cleanup] = monitorTestEnv();
    Monitor::reset();
    Monitor::init($config);
    Monitor::record('srv.example.com', 11822, [
        'online' => true,
        'latency_ms' => 12,
        'players' => ['online' => 3, 'max' => 20],
        'version' => ['protocol' => null],
        'protocol_used' => 'modern',
    ]);
    $records = Monitor::query('srv.example.com', 10);
    assertSame(1, count($records));
    assertSame(null, $records[0]['protocol'], 'protocol 为 null 应原样保存');
    $cleanup();
};

return $tests;
