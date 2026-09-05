<?php

declare(strict_types=1);

/**
 * /metrics 与 /health 浏览器美化页测试。
 *
 * 通过常量 MCAPI_SKIP_EXECUTION 引入 public/index.php 的函数定义
 * （index.php 顶部有对应 goto 分支：不执行路由处理与副作用初始化），
 * 直接断言：
 *   - 输出格式决策逻辑（format 参数 + Accept 头判断）
 *   - 状态统计 文本解析（parsePrometheusText）
 *   - 时长可读格式化（formatUptime）
 *   - 美化页 HTML 关键片段（renderMetricsPage / renderHealthPage）
 *
 * 用法：php tests/test_runner.php（自动发现本文件）
 */

define('MCAPI_SKIP_EXECUTION', true);

// 仅加载函数定义，不执行路由处理（不会输出任何响应）
require __DIR__ . '/../public/index.php';

use McPing\Metrics;

$tests = [];

$tests['decideMetricsFormat raw 强制纯文本'] = function (): void {
    assertSame('plain', decideMetricsFormat('raw', 'text/html'));
    assertSame('plain', decideMetricsFormat('raw', '*/*'));
    assertSame('plain', decideMetricsFormat('raw', null));
};

$tests['decideMetricsFormat html 强制 HTML'] = function (): void {
    assertSame('html', decideMetricsFormat('html', 'text/plain'));
    assertSame('html', decideMetricsFormat('html', '*/*'));
    assertSame('html', decideMetricsFormat('html', null));
};

$tests['decideMetricsFormat Accept text/html 判 HTML'] = function (): void {
    assertSame('html', decideMetricsFormat(null, 'text/html'));
    assertSame('html', decideMetricsFormat(null, 'application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'));
    assertSame('html', decideMetricsFormat(null, 'text/html;q=0.9'));
    assertSame('html', decideMetricsFormat(null, 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'));
};

$tests['decideMetricsFormat 其余保持纯文本'] = function (): void {
    assertSame('plain', decideMetricsFormat(null, '*/*'));
    assertSame('plain', decideMetricsFormat(null, 'text/plain'));
    assertSame('plain', decideMetricsFormat(null, 'application/json'));
    assertSame('plain', decideMetricsFormat(null, 'text/html;q=0'));
    assertSame('plain', decideMetricsFormat(null, null));
    assertSame('plain', decideMetricsFormat(null, ''));
    assertSame('plain', decideMetricsFormat('foo', 'text/html'));
    assertSame('plain', decideMetricsFormat('', 'text/html'));
};

$tests['decideHealthFormat html 与 Accept 判 HTML'] = function (): void {
    assertSame('html', decideHealthFormat('html', '*/*'));
    assertSame('html', decideHealthFormat('html', null));
    assertSame('html', decideHealthFormat(null, 'text/html'));
    assertSame('html', decideHealthFormat(null, 'text/html;q=0.8'));
    assertSame('html', decideHealthFormat('', 'text/html'));
};

$tests['decideHealthFormat 其余保持 JSON'] = function (): void {
    assertSame('json', decideHealthFormat(null, '*/*'));
    assertSame('json', decideHealthFormat(null, null));
    assertSame('json', decideHealthFormat(null, ''));
    assertSame('json', decideHealthFormat('raw', 'text/html'));
    assertSame('json', decideHealthFormat('foo', 'text/html'));
    assertSame('json', decideHealthFormat(null, 'text/html;q=0'));
};

$tests['acceptsHtmlHeader 大小写与 q 权重'] = function (): void {
    assertTrue(acceptsHtmlHeader('text/HTML'));
    assertTrue(acceptsHtmlHeader('TEXT/HTML; Q=0.5'));
    assertTrue(acceptsHtmlHeader('application/xhtml+xml'));
    assertFalse(acceptsHtmlHeader('application/json'));
    assertFalse(acceptsHtmlHeader('text/plain'));
    assertFalse(acceptsHtmlHeader(''));
    assertFalse(acceptsHtmlHeader(null));
    assertFalse(acceptsHtmlHeader('text/html;q=0'));
    assertFalse(acceptsHtmlHeader('text/html;q=0.0,*/*;q=0.8'));
};

$tests['parsePrometheusText 分组与标签'] = function (): void {
    $text = "# HELP http_requests_total 累计计数\n"
        . "# TYPE http_requests_total counter\n"
        . "http_requests_total{route=\"home\"} 5\n"
        . "http_requests_total{route=\"ping\"} 3\n"
        . "# HELP uptime_seconds 进程启动至今秒数\n"
        . "# TYPE uptime_seconds gauge\n"
        . "uptime_seconds 5708\n";
    $families = parsePrometheusText($text);
    assertTrue(isset($families['http_requests_total']), '应包含 http_requests_total');
    assertSame('counter', $families['http_requests_total']['type']);
    assertSame('累计计数', $families['http_requests_total']['help']);
    assertSame(2, count($families['http_requests_total']['samples']));
    assertSame('home', $families['http_requests_total']['samples'][0]['labels']['route']);
    assertSame(5.0, $families['http_requests_total']['samples'][0]['value']);
    assertSame('gauge', $families['uptime_seconds']['type']);
    assertSame(5708.0, $families['uptime_seconds']['samples'][0]['value']);
    assertSame([], $families['uptime_seconds']['samples'][0]['labels']);
};

$tests['parsePrometheusText 标签转义还原'] = function (): void {
    // Prometheus 文本中的 \" 与 \\ 应还原为 " 与 \
    $text = 'x_total{route="/a\"b\\\\c"} 1' . "\n";
    $families = parsePrometheusText($text);
    assertSame('/a"b\\c', $families['x_total']['samples'][0]['labels']['route']);
};

$tests['parsePrometheusText 字面量反斜杠加 n 不被误判为换行'] = function (): void {
    // 标签值为字面量 "a\nb"（a + 反斜杠 + n + b），Prometheus 转义后文本为 a\\nb；
    // 单遍解码应还原为字面量反斜杠加 n，而不是真实换行（回归 QA 发现的低危缺陷）
    $text = 'x_total{route="a\\\\nb"} 1' . "\n";
    $families = parsePrometheusText($text);
    assertSame('a\nb', $families['x_total']['samples'][0]['labels']['route']);
    assertFalse(str_contains($families['x_total']['samples'][0]['labels']['route'], "\n"), '不应出现真实换行');
};

$tests['parsePrometheusText 与 Metrics 往返（字面量反斜杠）'] = function (): void {
    Metrics::reset();
    Metrics::init();
    Metrics::increment('x_total', ['route' => 'a\\nb']); // 字面量：a 反斜杠 n b
    $families = parsePrometheusText(Metrics::export());
    assertSame('a\nb', $families['x_total']['samples'][0]['labels']['route'], 'Metrics 转义后应能正确还原');
};

$tests['parsePrometheusText 真实换行仍可还原'] = function (): void {
    // 标签值含真实换行：Prometheus 转义为 \n，解码后应还原为真实换行
    $text = 'x_total{route="a\\nb"} 1' . "\n"; // 文本中 \n 为转义序列（反斜杠加 n）
    $families = parsePrometheusText($text);
    assertSame("a\nb", $families['x_total']['samples'][0]['labels']['route'], '转义 \\n 应还原为真实换行');
};

$tests['parsePrometheusText 尾部孤立反斜杠不崩溃'] = function (): void {
    // 标签值 a\（a + 反斜杠）：Prometheus 转义后文本为 a\\（两个反斜杠），解码应还原为单个反斜杠
    $text = 'x_total{route="a\\\\"} 1' . "\n";
    $families = parsePrometheusText($text);
    assertSame('a\\', $families['x_total']['samples'][0]['labels']['route'], '尾部反斜杠应还原为单个反斜杠');
};

$tests['parsePrometheusText 空文本与仅头'] = function (): void {
    assertSame([], parsePrometheusText(''), '空文本应为空数组');
    $families = parsePrometheusText("# HELP x_total 无样本\n# TYPE x_total counter\n");
    assertTrue(isset($families['x_total']), '应保留 HELP/TYPE 头定义');
    assertSame([], $families['x_total']['samples'], '无样本应为空数组');
};

$tests['formatUptime 可读时长'] = function (): void {
    assertSame('0秒', formatUptime(0));
    assertSame('8秒', formatUptime(8));
    assertSame('35分8秒', formatUptime(2108));
    assertSame('1小时35分8秒', formatUptime(5708));
    assertSame('2天3小时4分5秒', formatUptime(2 * 86400 + 3 * 3600 + 4 * 60 + 5));
    assertSame('1天5秒', formatUptime(86405));
    assertSame('1小时35分8秒', formatUptime(5708.99));
};

$tests['formatMetricValue 数值展示'] = function (): void {
    assertSame('5', formatMetricValue(5.0));
    assertSame('3.142', formatMetricValue(3.14159));
    assertSame('0', formatMetricValue(0.0));
    assertSame('3.5', formatMetricValue(3.5));
};

$tests['renderMetricsPage 关键片段'] = function (): void {
    Metrics::reset();
    Metrics::init();
    Metrics::increment('http_requests_total', ['route' => 'home']);
    Metrics::increment('http_errors_total', ['code' => '1001']);
    Metrics::increment('ping_success_total');
    Metrics::increment('ping_failure_total');
    Metrics::gauge('active_batch_requests', 2.0);
    $config = require __DIR__ . '/../config/config.php';
    $html = renderMetricsPage($config);
    assertTrue(str_contains($html, '<!DOCTYPE html>'), '应包含文档声明');
    assertTrue(str_contains($html, 'site-header'), '应包含导航栏');
    assertTrue(str_contains($html, 'site-footer'), '应包含页脚');
    assertTrue(str_contains($html, 'metrics-content'), '应包含刷新容器');
    assertTrue(str_contains($html, '?format=raw'), '应包含查看原始文本链接');
    assertTrue(str_contains($html, 'http_requests_total'), '应包含请求计数指标名');
    assertTrue(str_contains($html, 'home'), '应包含路由标签值');
    assertTrue(str_contains($html, '1001'), '应包含错误码标签值');
    assertTrue(str_contains($html, 'active_batch_requests'), '应包含 gauge 指标');
    assertTrue(str_contains($html, 'uptime_seconds'), '应包含运行时长指标');
    assertFalse(str_contains($html, 'Warning'), '不应输出 PHP 警告');
    assertFalse(str_contains($html, 'Fatal error'), '不应输出致命错误');
};

$tests['renderMetricsPage uptime 可读时长展示'] = function (): void {
    Metrics::reset();
    Metrics::init();
    $config = require __DIR__ . '/../config/config.php';
    $html = renderMetricsPage($config);
    // 页面应包含可读时长（"秒"字样）与运行时长指标
    assertTrue(str_contains($html, '秒'), '页面应包含可读时长单位');
    assertTrue(str_contains($html, 'uptime_seconds'), '页面应包含运行时长指标');
};

$tests['renderHealthPage 关键片段'] = function (): void {
    $config = require __DIR__ . '/../config/config.php';
    $html = renderHealthPage($config);
    assertTrue(str_contains($html, '<!DOCTYPE html>'), '应包含文档声明');
    assertTrue(str_contains($html, 'site-header'), '应包含导航栏');
    assertTrue(str_contains($html, 'site-footer'), '应包含页脚');
    assertTrue(str_contains($html, '运行正常'), '应包含运行正常徽章');
    assertTrue(str_contains($html, 'mc-server-api'), '应包含服务名称');
    assertTrue(str_contains($html, PHP_VERSION), '应包含 PHP 版本');
    assertTrue(str_contains($html, 'Asia/Shanghai'), '应包含时区');
    assertSame(1, preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $html), '应包含本地时间');
    assertFalse(str_contains($html, 'Warning'), '不应输出 PHP 警告');
};

return $tests;
