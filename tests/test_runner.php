<?php

declare(strict_types=1);

/**
 * 轻量级单元测试运行器（无 PHPUnit 依赖）。
 *
 * 用法：php tests/test_runner.php
 *
 * 扫描本目录下所有 *_test.php 文件，每个测试文件须 return 一个关联数组：
 *   [ '测试名称' => function (): void { ...断言... }, ... ]
 *
 * 断言失败时抛出 \RuntimeException 即可；运行器统计 PASS/FAIL，
 * 存在任一失败时进程退出码为 1（便于 CI 集成）。
 */

require __DIR__ . '/../src/autoload.php';

/** 断言条件为真。 */
function assertTrue(bool $condition, string $message = '断言失败'): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

/** 断言条件为假。 */
function assertFalse(bool $condition, string $message = '断言失败'): void
{
    if ($condition) {
        throw new \RuntimeException($message);
    }
}

/** 断言严格相等（===）。 */
function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $msg = $message !== ''
            ? $message
            : '期望 ' . var_export($expected, true) . '，实际 ' . var_export($actual, true);
        throw new \RuntimeException($msg);
    }
}

$testFiles = glob(__DIR__ . '/*_test.php');
sort($testFiles);

if ($testFiles === []) {
    echo "未找到任何测试文件\n";
    exit(1);
}

$passed = 0;
$failed = 0;
$failures = [];

foreach ($testFiles as $file) {
    $tests = require $file;
    if (!is_array($tests)) {
        echo "SKIP " . basename($file) . "（未返回测试数组）\n";
        continue;
    }
    foreach ($tests as $name => $fn) {
        if (!is_callable($fn)) {
            $failed++;
            $failures[] = basename($file) . "::{$name} 不是可调用函数";
            echo "FAIL {$name}\n";
            continue;
        }
        try {
            $fn();
            $passed++;
            echo "PASS {$name}\n";
        } catch (\Throwable $e) {
            $failed++;
            $failures[] = "{$name}: {$e->getMessage()}";
            echo "FAIL {$name}: {$e->getMessage()}\n";
        }
    }
}

echo "\n共 {$passed} 个通过，{$failed} 个失败\n";
if ($failed > 0) {
    echo "失败明细：\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}
