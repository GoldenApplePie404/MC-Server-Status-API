<?php

declare(strict_types=1);

namespace McPing;

/**
 * 进程内内存 TTL 缓存（P0-1）。
 *
 * 用于缓存服务器状态查询的成功结果，避免在 TTL 窗口内对同一台服务器
 * 反复发起真实 TCP 连接，降低目标服务器压力并显著提升重复查询响应速度。
 *
 * 特性：
 *   - 纯内存存储为主，无外部依赖；
 *   - 支持 TTL 过期（惰性过期：读取时检查时间戳并清理）；
 *   - 记录命中/未命中计数，供 /metrics 与 stats() 输出；
 *   - 单例模式（instance()），测试可用 reset() 重置全部状态。
 *
 * 跨请求持久化（重要）：
 *   Windows 下 PHP 内置服务器（php -S）为每个请求启动独立进程/线程，
 *   纯内存状态无法跨请求保留。因此前端控制器在启动时调用 configure()
 *   传入持久化文件路径：缓存状态在请求结束时写盘、下次请求加载，
 *   从而实现跨请求命中（Linux/macOS 单进程内置服务器下同样生效）。
 *   单元测试不调用 configure()，保持纯内存行为。
 */
final class Cache
{
    /** @var array<string, array{value: mixed, expires_at: float}> 缓存条目 */
    private array $items = [];

    /** @var int 命中次数 */
    private int $hits = 0;

    /** @var int 未命中次数 */
    private int $misses = 0;

    /** @var bool 是否已从持久化文件加载 */
    private bool $loaded = false;

    /** @var Cache|null 单例实例 */
    private static ?Cache $instance = null;

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
    public static function instance(): Cache
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 配置持久化（供前端控制器启动时调用）。
     *
     * 传入 cache_file_path 后，缓存会在每次请求结束（shutdown）时写盘，
     * 下次请求首次访问时加载，实现跨请求命中。
     *
     * @param array<string, mixed> $config 全局配置
     */
    public static function configure(array $config): void
    {
        $path = (string)($config['cache_file_path'] ?? '');
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
     * 重置单例与全部状态（主要用于单元测试隔离）。
     *
     * 测试场景需要干净的缓存视图时调用；生产代码不建议使用。
     * 注意：reset 仅重建内存实例，不修改持久化路径配置。
     */
    public static function reset(): void
    {
        self::$instance = new self();
    }

    /**
     * 读取缓存。
     *
     * 命中时返回缓存值（可能为任意类型）；未命中或已过期时返回 null。
     * 注意：由于缓存值可能本身就是 null，判断是否命中请优先使用 has()。
     *
     * @param string $key 缓存键
     * @return mixed 缓存值；未命中/过期返回 null
     */
    public function get(string $key): mixed
    {
        $this->ensureLoaded();
        if (!$this->has($key)) {
            return null;
        }
        return $this->items[$key]['value'];
    }

    /**
     * 判断缓存键是否存在且未过期。
     *
     * @param string $key 缓存键
     */
    public function has(string $key): bool
    {
        $this->ensureLoaded();
        if (!isset($this->items[$key])) {
            $this->misses++;
            return false;
        }
        if ($this->items[$key]['expires_at'] < microtime(true)) {
            unset($this->items[$key]);
            $this->misses++;
            return false;
        }
        $this->hits++;
        return true;
    }

    /**
     * 写入缓存。
     *
     * @param string $key        缓存键
     * @param mixed  $value      缓存值（建议为可 JSON 序列化的数组）
     * @param int    $ttlSeconds 有效期（秒）；小于等于 0 表示立即过期（等价于不缓存）
     */
    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->ensureLoaded();
        if ($ttlSeconds <= 0) {
            unset($this->items[$key]);
            return;
        }
        $this->items[$key] = [
            'value' => $value,
            'expires_at' => microtime(true) + $ttlSeconds,
        ];
    }

    /**
     * 删除指定缓存键。
     *
     * @param string $key 缓存键
     */
    public function delete(string $key): void
    {
        $this->ensureLoaded();
        unset($this->items[$key]);
    }

    /**
     * 清空全部缓存条目（保留计数统计）。
     */
    public function clear(): void
    {
        $this->ensureLoaded();
        $this->items = [];
    }

    /**
     * 缓存统计（命中/未命中计数）。
     *
     * @return array{hits: int, misses: int}
     */
    public function stats(): array
    {
        $this->ensureLoaded();
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
        ];
    }

    /**
     * 当前缓存条目数量（便于诊断与测试）。
     */
    public function count(): int
    {
        $this->ensureLoaded();
        return count($this->items);
    }

    /**
     * 首次访问时从持久化文件加载缓存状态（进程/线程内仅一次）。
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
        if (!is_array($data)) {
            return;
        }
        $this->items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
        $this->hits = max(0, (int)($data['hits'] ?? 0));
        $this->misses = max(0, (int)($data['misses'] ?? 0));

        // 加载时顺带清理已过期条目
        $now = microtime(true);
        foreach ($this->items as $key => $entry) {
            if (!is_array($entry) || !isset($entry['expires_at']) || $entry['expires_at'] < $now) {
                unset($this->items[$key]);
            }
        }
    }

    /**
     * 将当前缓存状态写盘（请求结束时由 shutdown 回调调用）。
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
        $payload = json_encode([
            'items' => $this->items,
            'hits' => $this->hits,
            'misses' => $this->misses,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($path, $payload === false ? '{}' : $payload, LOCK_EX);
    }
}
