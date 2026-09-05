<?php

declare(strict_types=1);

namespace McPing;

/**
 * 状态统计 文本格式指标采集器（P1-4）。
 *
 * 进程内静态计数数组，由 public/index.php 在各路由响应的同时上报计数；
 * /metrics 路由直接调用 export() 输出 状态统计 文本格式。
 *
 * 指标清单：
 *   - http_requests_total{route}     各路由累计请求数（/metrics 自身不计入，避免循环污染）
 *   - http_errors_total{code}        按错误码累计的业务错误数
 *   - ping_success_total             服务器状态查询成功次数
 *   - ping_failure_total             服务器状态查询失败次数
 *   - cache_hits_total               缓存命中次数（读取 Cache::stats() 实时值）
 *   - cache_misses_total             缓存未命中次数（读取 Cache::stats() 实时值）
 *   - uptime_seconds                 进程启动至今秒数
 *   - active_batch_requests          进行中的批量查询数（gauge）
 *
 * 跨请求持久化：Windows 内置服务器每请求独立进程/线程，纯内存计数无法累计；
 * 前端控制器启动时调用 configure() 传入 metrics_file_path 后，
 * 计数在请求结束时写盘、下次请求加载（/metrics 因此能展示累计值）。
 * 单元测试不调用 configure()，保持纯内存行为。
 */
final class Metrics
{
    /** @var array<string, float> 计数表：key 为 "指标名|标签序列化" */
    private static array $counters = [];

    /** @var array<string, float> 仪表表：key 同上，值可增可减 */
    private static array $gauges = [];

    /** @var float 进程启动时间戳（微秒） */
    private static float $startTime = 0.0;

    /** @var bool 是否已从持久化文件加载计数 */
    private static bool $loaded = false;

    /** @var string|null 计数持久化文件路径 */
    private static ?string $countersPath = null;

    /** @var string|null 启动时间持久化文件路径 */
    private static ?string $uptimePath = null;

    /** @var bool 是否已注册请求结束写盘回调 */
    private static bool $shutdownRegistered = false;

    /**
     * 配置持久化（供前端控制器启动时调用，须在 init() 之前）。
     *
     * @param array<string, mixed> $config 全局配置
     */
    public static function configure(array $config): void
    {
        $countersPath = (string)($config['metrics_file_path'] ?? '');
        self::$countersPath = $countersPath === '' ? null : $countersPath;
        $uptimePath = (string)($config['metrics_uptime_file'] ?? '');
        self::$uptimePath = $uptimePath === '' ? null : $uptimePath;

        if ((self::$countersPath !== null || self::$uptimePath !== null) && !self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                self::persistCounters();
            });
        }
    }

    /**
     * 初始化启动时间戳（幂等，进程内仅首次生效）。
     *
     * 配置了 metrics_uptime_file 时：文件不存在则创建并记录当前时间；
     * 已存在则以文件创建时间为启动时间（跨请求保持真实运行时长）。
     */
    public static function init(): void
    {
        self::ensureLoaded();
        if (self::$startTime !== 0.0) {
            return;
        }

        if (self::$uptimePath !== null) {
            if (is_file(self::$uptimePath)) {
                self::$startTime = (float)@filemtime(self::$uptimePath);
            } else {
                self::$startTime = microtime(true);
                $dir = dirname(self::$uptimePath);
                if ((is_dir($dir) || @mkdir($dir, 0755, true)) && @file_put_contents(self::$uptimePath, (string)time()) === false) {
                    // 写入失败不阻断（回退为内存启动时间）
                }
            }
            return;
        }

        self::$startTime = microtime(true);
    }

    /**
     * 计数自增（counter 语义）。
     *
     * @param string               $name   指标名（如 http_requests_total）
     * @param array<string, mixed> $labels 标签映射（如 ['route' => '/api/ping']）
     * @param float                $amount 增量（默认 1）
     */
    public static function increment(string $name, array $labels = [], float $amount = 1.0): void
    {
        self::ensureLoaded();
        $key = self::key($name, $labels);
        self::$counters[$key] = (self::$counters[$key] ?? 0.0) + $amount;
    }

    /**
     * 设置仪表值（gauge 语义，可增减）。
     *
     * @param string               $name   指标名
     * @param float                $value  当前值
     * @param array<string, mixed> $labels 标签映射
     */
    public static function gauge(string $name, float $value, array $labels = []): void
    {
        self::ensureLoaded();
        self::$gauges[self::key($name, $labels)] = $value;
    }

    /**
     * 导出 状态统计 文本格式。
     *
     * 输出顺序：先 counters（含 help/type 头），再 gauges，最后动态指标
     * （uptime_seconds 与缓存命中/未命中，后者从 Cache::stats() 实时读取）。
     * 标签级指标（http_requests_total / http_errors_total）在尚无样本时
     * 也会输出 HELP/TYPE 头（值 0 由 状态统计 采集端视为无样本亦可）。
     *
     * @return string 状态统计 文本
     */
    public static function export(): string
    {
        self::init();
        $lines = [];

        // 按指标名聚合输出：每个指标族仅输出一次 HELP/TYPE 头（状态统计 规范）
        $families = [];

        foreach (self::$counters as $key => $value) {
            [$name, $labelText] = self::splitKey($key);
            $families[$name]['type'] = 'counter';
            $families[$name]['samples'][] = $name . $labelText . ' ' . self::formatNumber($value);
        }
        foreach (self::$gauges as $key => $value) {
            [$name, $labelText] = self::splitKey($key);
            $families[$name]['type'] = 'gauge';
            $families[$name]['samples'][] = $name . $labelText . ' ' . self::formatNumber($value);
        }

        // 标签级已知指标：无样本也输出 HELP/TYPE（便于首次抓取即可见）
        foreach (['http_requests_total', 'http_errors_total'] as $labeled) {
            if (!isset($families[$labeled])) {
                $families[$labeled] = ['type' => 'counter', 'samples' => []];
            }
        }

        ksort($families);
        foreach ($families as $name => $family) {
            $lines[] = '# HELP ' . $name . ' 累计计数';
            $lines[] = '# TYPE ' . $name . ' ' . $family['type'];
            foreach ($family['samples'] as $sample) {
                $lines[] = $sample;
            }
        }

        // 动态指标：缓存命中/未命中（实时读取）与运行时长
        $cacheStats = Cache::instance()->stats();
        $lines[] = '# HELP cache_hits_total 缓存命中次数';
        $lines[] = '# TYPE cache_hits_total counter';
        $lines[] = 'cache_hits_total ' . self::formatNumber((float)$cacheStats['hits']);
        $lines[] = '# HELP cache_misses_total 缓存未命中次数';
        $lines[] = '# TYPE cache_misses_total counter';
        $lines[] = 'cache_misses_total ' . self::formatNumber((float)$cacheStats['misses']);

        $lines[] = '# HELP uptime_seconds 进程启动至今秒数';
        $lines[] = '# TYPE uptime_seconds gauge';
        $lines[] = 'uptime_seconds ' . self::formatNumber(microtime(true) - self::$startTime);

        return implode("\n", $lines) . "\n";
    }

    /**
     * 重置全部指标（测试隔离用）。
     */
    public static function reset(): void
    {
        self::$counters = [];
        self::$gauges = [];
        self::$startTime = 0.0;
        self::$loaded = false;
    }

    /**
     * 构造内部存储键：指标名 + 标签序列化。
     *
     * @param array<string, mixed> $labels
     */
    private static function key(string $name, array $labels): string
    {
        if ($labels === []) {
            return $name . '|';
        }
        ksort($labels);
        $parts = [];
        foreach ($labels as $label => $value) {
            $parts[] = $label . '="' . self::escapeLabelValue((string)$value) . '"';
        }
        return $name . '|' . implode(',', $parts);
    }

    /**
     * 拆分内部存储键为 [指标名, 标签文本]。
     *
     * @return array{0: string, 1: string}
     */
    private static function splitKey(string $key): array
    {
        $pos = strpos($key, '|');
        if ($pos === false) {
            return [$key, ''];
        }
        $name = substr($key, 0, $pos);
        $labels = substr($key, $pos + 1);
        return [$name, $labels === '' ? '' : '{' . $labels . '}'];
    }

    /**
     * 转义 状态统计 标签值（反斜杠、双引号、换行）。
     */
    private static function escapeLabelValue(string $value): string
    {
        return str_replace(
            ["\\", "\"", "\n"],
            ["\\\\", "\\\"", "\\n"],
            $value
        );
    }

    /**
     * 数字格式化：整数不带小数点，浮点保留 3 位。
     */
    private static function formatNumber(float $value): string
    {
        if (floor($value) === $value) {
            return (string)(int)$value;
        }
        return rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
    }

    /**
     * 首次访问时从持久化文件加载计数（进程/线程内仅一次）。
     */
    private static function ensureLoaded(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $path = self::$countersPath;
        if ($path === null || !is_file($path)) {
            return;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }
        self::$counters = isset($data['counters']) && is_array($data['counters']) ? $data['counters'] : [];
        self::$gauges = isset($data['gauges']) && is_array($data['gauges']) ? $data['gauges'] : [];
    }

    /**
     * 将计数写盘（请求结束时由 shutdown 回调调用）。
     */
    private static function persistCounters(): void
    {
        $path = self::$countersPath;
        if ($path === null) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $payload = json_encode([
            'counters' => self::$counters,
            'gauges' => self::$gauges,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($path, $payload === false ? '{}' : $payload, LOCK_EX);
    }
}
