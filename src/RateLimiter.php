<?php

declare(strict_types=1);

namespace McPing;

/**
 * 固定窗口限流器（P1-3）。
 *
 * 按「客户端 IP + 路由」作为桶键，在每分钟固定窗口内统计请求次数；
 * 超过上限返回 429 + 业务错误码 1007（由前端控制器负责 HTTP 状态码与 JSON 组装）。
 *
 * 实现要点：
 *   - 固定窗口：窗口起点为当前分钟（floor(now / 60) * 60），跨分钟自动重建计数；
 *   - 内存存储为主；前端控制器启动时调用 configure() 传入持久化文件路径后，
 *     桶状态在请求结束时写盘、下次请求加载，确保 Windows 内置服务器
 *     （每请求独立进程/线程）下限流跨请求生效；
 *   - consume() 一次调用同时完成"判断 + 计数"，返回是否放行与剩余额度；
 *   - 提供 remaining() 只读查询，供响应头 X-RateLimit-Remaining 使用。
 */
final class RateLimiter
{
    /** @var array<string, array{window_start: int, count: int}> 桶表 */
    private array $buckets = [];

    /** @var bool 是否已从持久化文件加载 */
    private bool $loaded = false;

    /** @var RateLimiter|null 单例实例 */
    private static ?RateLimiter $instance = null;

    /** @var string|null 持久化文件路径（configure 后非 null） */
    private static ?string $persistPath = null;

    /** @var bool 是否已注册请求结束写盘回调 */
    private static bool $shutdownRegistered = false;

    /**
     * 禁止外部直接实例化（单例）。
     */
    private function __construct()
    {
    }

    /**
     * 获取全局单例。
     */
    public static function instance(): RateLimiter
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 配置持久化（供前端控制器启动时调用）。
     *
     * @param array<string, mixed> $config 全局配置
     */
    public static function configure(array $config): void
    {
        $path = (string)($config['ratelimit_file_path'] ?? '');
        self::$persistPath = $path === '' ? null : $path;
        if (self::$persistPath !== null && !self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                $instance = self::$instance;
                if ($instance !== null) {
                    $instance->persist();
                }
            });
        }
    }

    /**
     * 重置全部状态（测试隔离用）。
     */
    public static function reset(): void
    {
        self::$instance = new self();
    }

    /**
     * 消费一次请求额度。
     *
     * 若当前窗口未超限则计数并放行；已超限则拒绝（不再累计）。
     *
     * @param string $bucketKey 桶键（建议 "客户端IP|路由"）
     * @param int    $limit     每分钟上限；小于等于 0 表示不限流（恒放行）
     * @return array{allowed: bool, remaining: int, retry_after: int}
     *         allowed 是否放行；remaining 剩余额度（拒绝时为 0）；
     *         retry_after 距下个窗口的秒数（拒绝时供 429 Retry-After 头使用）
     */
    public function consume(string $bucketKey, int $limit): array
    {
        $this->ensureLoaded();

        if ($limit <= 0) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0];
        }

        $now = time();
        $windowStart = (int)floor($now / 60) * 60;
        $bucket = $this->buckets[$bucketKey] ?? ['window_start' => $windowStart, 'count' => 0];

        // 跨窗口：重建计数
        if ($bucket['window_start'] !== $windowStart) {
            $bucket = ['window_start' => $windowStart, 'count' => 0];
        }

        if ($bucket['count'] >= $limit) {
            $this->buckets[$bucketKey] = $bucket;
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => max(1, 60 - ($now - $windowStart)),
            ];
        }

        $bucket['count']++;
        $this->buckets[$bucketKey] = $bucket;
        return [
            'allowed' => true,
            'remaining' => $limit - $bucket['count'],
            'retry_after' => 0,
        ];
    }

    /**
     * 只读查询当前窗口剩余额度（不消费）。
     *
     * @param string $bucketKey 桶键
     * @param int    $limit     每分钟上限
     */
    public function remaining(string $bucketKey, int $limit): int
    {
        $this->ensureLoaded();
        if ($limit <= 0) {
            return PHP_INT_MAX;
        }
        $now = time();
        $windowStart = (int)floor($now / 60) * 60;
        $bucket = $this->buckets[$bucketKey] ?? null;
        if ($bucket === null || $bucket['window_start'] !== $windowStart) {
            return $limit;
        }
        return max(0, $limit - $bucket['count']);
    }

    /**
     * 校验 API Key 是否合法（空 key 列表视为不启用鉴权）。
     *
     * @param string|null $apiKey   请求携带的 key（可为 null）
     * @param array<int, string> $apiKeys 配置中的合法 key 列表
     */
    public static function validApiKey(?string $apiKey, array $apiKeys): bool
    {
        if ($apiKeys === []) {
            return true; // 未配置任何 key：鉴权关闭，任何请求均视为合法
        }
        if ($apiKey === null || $apiKey === '') {
            return false;
        }
        return in_array($apiKey, $apiKeys, true);
    }

    /**
     * 首次访问时从持久化文件加载桶状态（进程/线程内仅一次）。
     */
    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $path = self::$persistPath;
        if ($path === null || !is_file($path)) {
            return;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['buckets']) || !is_array($data['buckets'])) {
            return;
        }
        $this->buckets = $data['buckets'];
    }

    /**
     * 将桶状态写盘（请求结束时由 shutdown 回调调用）。
     */
    private function persist(): void
    {
        $path = self::$persistPath;
        if ($path === null) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $payload = json_encode(['buckets' => $this->buckets], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($path, $payload === false ? '{}' : $payload, LOCK_EX);
    }
}
