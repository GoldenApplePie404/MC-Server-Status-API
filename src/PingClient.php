<?php

declare(strict_types=1);

namespace McPing;

/**
 * 门面类：对外提供统一的 ping() 入口。
 *
 * 自动降级策略：
 *   1. 先尝试 1.7+ 现代状态协议（modern）；
 *   2. 失败后自动尝试 legacy 0xFE 协议（1.4~1.6 老服务器）；
 *   3. 两者均失败时抛出第一个（现代协议）错误，供上层转为统一 JSON 错误响应。
 *
 * SRV 自动解析（srv_auto_resolve）：
 *   默认端口（25565）或用户显式指定端口查询失败时，自动解析
 *   _minecraft._tcp.<host> 的 SRV 记录并按 SRV 目标主机+端口重试一次，
 *   便于命中使用非 25565 端口的外置登录服务器（如 BungeeCord 后端、高防前置）。
 *   用户显式指定端口时仍以直接查询优先，仅失败后做 SRV 兜底，不会覆盖用户指定端口。
 *
 * 同时负责：输入校验、IPv6 规范化、核心识别、favicon 解码落盘与 URL 生成。
 */
final class PingClient
{
    /** @var array<string, mixed> 合并后的配置 */
    private array $config;

    /** @var callable(string): array|null SRV 查询回调；为 null 时使用 dns_get_record 真实查询（测试注入用） */
    private $srvResolver = null;

    /** @var float SRV DNS 查询超时（秒）；dns_get_record 无超时参数，该值保留给自定义解析器使用 */
    private float $srvTimeout;

    /**
     * @param array<string, mixed>|null $config 覆盖配置；为 null 时加载 config/config.php
     */
    public function __construct(?array $config = null)
    {
        $defaults = self::defaultConfig();
        $this->config = $config === null ? $defaults : array_merge($defaults, $config);
        $this->srvTimeout = (float)($this->config['srv_timeout'] ?? 1.0);
    }

    /**
     * 注入自定义 SRV 查询回调（主要用于单元测试注入假 SRV 数据）。
     *
     * 回调签名：function (string $srvName): array，返回与 dns_get_record 同构的
     * SRV 记录数组；查询失败返回 [] 或 false 均可（内部统一转为 []）。
     * 传入 null 时恢复默认的 dns_get_record 真实查询。
     *
     * @param callable(string): array|null $resolver SRV 查询回调
     */
    public function setSrvResolver(?callable $resolver): void
    {
        $this->srvResolver = $resolver;
    }

    /**
     * 加载默认配置（config/config.php）。
     *
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        $file = dirname(__DIR__) . '/config/config.php';
        if (is_file($file)) {
            $config = require $file;
            return is_array($config) ? $config : [];
        }
        return [];
    }

    /**
     * 查询服务器状态（自动降级 modern -> legacy；失败时可选 SRV 兜底重试）。
     *
     * 编排流程：
     *   1. 输入校验与 IPv6 规范化；
     *   2. 使用调用方给定的 host:port 尝试查询（modern -> legacy 自动降级）；
     *   3. 查询失败（非参数错误）且 srv_auto_resolve 开启时，解析
     *      _minecraft._tcp.<host> 的 SRV 记录并按 SRV 目标主机+端口重试一次；
     *   4. SRV 未命中、查询本身失败或重试仍失败时，抛出第一次查询的原始错误。
     *
     * 用户显式指定端口时同样保留"直接查询优先"，仅在失败后做 SRV 兜底，
     * 不会用 SRV 覆盖用户显式指定的端口作为首选；直接查询成功时 srv_used 为 false。
     *
     * @param string $host 服务器地址（支持域名 / IPv4 / IPv6）
     * @param int    $port 端口
     * @return array<string, mixed> 统一状态数据
     * @throws PingException 参数无效 / 连接失败 / 超时 / 协议错误时抛出
     */
    public function ping(string $host, int $port): array
    {
        $host = trim($host);
        $this->validateHostPort($host, $port);
        $host = $this->normalizeHost($host);

        // P0-1：缓存命中直接返回（仅成功结果入缓存，失败不缓存）。
        // 缓存键使用「请求方视角 host:port」，SRV 解析结果只存在 data 里，
        // 避免 SRV 兜底结果污染显式端口查询。
        $cacheKey = self::cacheKeyFor($host, $port);
        if ($this->isCacheEnabled() && Cache::instance()->has($cacheKey)) {
            $cached = Cache::instance()->get($cacheKey);
            if (is_array($cached)) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        try {
            $data = $this->attemptQuery($host, $port, false, null);
            $this->cacheAndRecord($cacheKey, $host, $port, $data);
            return $data;
        } catch (PingException $e) {
            // 参数错误不参与 SRV 兜底（正常流程校验已通过，不会走到这里，属防御性判断）
            if ($e->getErrorCode() === PingException::ERR_INVALID_PARAMS) {
                throw $e;
            }
            if ($this->isSrvEnabled()) {
                $srv = $this->resolveSrv($host);
                if ($srv !== null) {
                    try {
                        $data = $this->attemptQuery(
                            $srv['host'],
                            $srv['port'],
                            true,
                            ['target' => $srv['target'], 'port' => $srv['port']]
                        );
                        $this->cacheAndRecord($cacheKey, $host, $port, $data);
                        return $data;
                    } catch (PingException $retryError) {
                        // SRV 重试仍失败：保持返回原始错误，便于上层给出与用户输入一致的提示
                        Monitor::record($host, $port, null);
                        throw $e;
                    }
                }
            }
            Monitor::record($host, $port, null);
            throw $e;
        }
    }

    /**
     * 生成缓存键（请求方视角 host:port，统一小写规范化）。
     *
     * 单查与批量共用同一套键规则，保证缓存可互相命中。
     *
     * @param string $host 主机（可含 IPv6 方括号）
     * @param int    $port 端口
     */
    public static function cacheKeyFor(string $host, int $port): string
    {
        return strtolower($host) . ':' . $port;
    }

    /**
     * 缓存写入（成功结果）+ 监控记录（成功）。
     *
     * @param string $cacheKey 规范化缓存键
     * @param string $host     请求方视角主机
     * @param int    $port     端口
     * @param array<string, mixed> $data 统一状态数据
     */
    private function cacheAndRecord(string $cacheKey, string $host, int $port, array $data): void
    {
        if ($this->isCacheEnabled()) {
            Cache::instance()->set($cacheKey, $data, (int)($this->config['cache_ttl_seconds'] ?? 30));
        }
        Monitor::record($host, $port, $data);
    }

    /**
     * cache_enabled 配置开关。
     */
    private function isCacheEnabled(): bool
    {
        return (bool)($this->config['cache_enabled'] ?? true);
    }

    /**
     * 尝试一次实际查询（modern -> legacy 自动降级），并补全统一 data 结构。
     *
     * @param array<string, mixed>|null $srvRecord SRV 记录信息 {target, port}；未使用 SRV 时为 null
     * @param string                    $host      规范化后的主机
     * @param int                       $port      端口
     * @param bool                      $srvUsed   本次查询是否经由 SRV 解析
     * @return array<string, mixed> 统一状态数据
     * @throws PingException 所有协议均失败时抛出
     */
    private function attemptQuery(string $host, int $port, bool $srvUsed, ?array $srvRecord): array
    {
        $modernError = null;
        if ($this->config['modern_enabled'] ?? true) {
            try {
                $result = (new MinecraftPing($this->config))->ping($host, $port);
                return $this->finalize($result, $host, $port, 'modern', $srvUsed, $srvRecord);
            } catch (PingException $e) {
                $modernError = $e;
            } catch (\Throwable $e) {
                $modernError = new PingException(PingException::ERR_PROTOCOL, '现代协议异常：' . $e->getMessage(), $e);
            }
        }

        if ($this->config['legacy_enabled'] ?? true) {
            try {
                $result = (new LegacyPing($this->config))->ping($host, $port);
                return $this->finalize($result, $host, $port, 'legacy', $srvUsed, $srvRecord);
            } catch (PingException $e) {
                // 忽略 legacy 错误，统一使用现代协议错误
            } catch (\Throwable $e) {
                // 忽略
            }
        }

        if ($modernError instanceof PingException) {
            throw $modernError;
        }
        throw new PingException(PingException::ERR_OFFLINE, '服务器离线：无法通过任何协议获取状态');
    }

    /**
     * 解析 _minecraft._tcp.<host> 的 SRV 记录。
     *
     * 公开为公共方法，供批量并发查询（BatchPinger）在直接连接失败后做
     * SRV 兜底解析；行为与单查完全一致（含测试注入的替身解析器）。
     *
     * 命中时返回：
     *   ['host' => 目标主机（为空/与原 host 相同时回退原 host）, 'port' => SRV 端口,
     *    'target' => SRV 原始目标]；
     * 无记录、查询失败或输入为 IP 字面量时返回 null。
     *
     * 多记录时按优先级（pri 小者优先）与权重（weight 大者优先）选取最优记录。
     *
     * @param string $host 规范化后的主机（域名 / IPv4 / IPv6，IPv6 已带方括号）
     * @return array{host: string, port: int, target: string}|null
     */
    public function resolveSrv(string $host): ?array
    {
        // IP 字面量（IPv4 / IPv6，含方括号形式）没有 SRV 记录，直接跳过
        if (str_starts_with($host, '[')) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        // 去掉 FQDN 尾部点，构造 _minecraft._tcp 查询名
        $queryHost = rtrim($host, '.');
        if ($queryHost === '' || $queryHost === '.') {
            return null;
        }
        $srvName = '_minecraft._tcp.' . $queryHost;

        $records = $this->lookupSrv($srvName);
        if ($records === []) {
            return null;
        }

        // 过滤出合法 SRV 记录：类型必须为 SRV、端口必须在 1-65535
        $candidates = [];
        foreach ($records as $record) {
            if (!is_array($record) || ($record['type'] ?? '') !== 'SRV') {
                continue;
            }
            $port = (int)($record['port'] ?? 0);
            if ($port < 1 || $port > 65535) {
                continue;
            }
            $target = isset($record['target']) ? rtrim((string)$record['target'], '.') : '';
            if ($target === '' || $target === '.') {
                // target 为空（RFC 2782 服务不可用标记）时回退原主机
                $target = $queryHost;
            }
            $candidates[] = [
                'pri' => (int)($record['pri'] ?? 0),
                'weight' => (int)($record['weight'] ?? 0),
                'target' => $target,
                'port' => $port,
            ];
        }
        if ($candidates === []) {
            return null;
        }

        // 排序：pri 小者优先；同 pri 时 weight 大者优先
        usort($candidates, static function (array $a, array $b): int {
            if ($a['pri'] !== $b['pri']) {
                return $a['pri'] <=> $b['pri'];
            }
            return $b['weight'] <=> $a['weight'];
        });
        $best = $candidates[0];

        // target 与原 host 相同时仍使用原 host（大小写不敏感比较）；
        // 目标为 IPv6 裸地址时补方括号，供 tcp:// 地址使用
        $resolvedHost = strcasecmp($best['target'], $queryHost) === 0 ? $queryHost : $best['target'];
        $resolvedHost = $this->normalizeHost($resolvedHost);

        return [
            'host' => $resolvedHost,
            'port' => $best['port'],
            'target' => $best['target'],
        ];
    }

    /**
     * 查询 _minecraft._tcp.<host> 的 SRV 记录。
     *
     * 默认使用 dns_get_record 真实查询；已通过 setSrvResolver 注入替身时使用替身
     * （单元测试据此注入假 SRV 数据，无需真实网络）。
     *
     * @param string $srvName 完整的 SRV 查询名（如 _minecraft._tcp.example.com）
     * @return array<int, array<string, mixed>> SRV 记录数组；查询失败返回 []
     */
    private function lookupSrv(string $srvName): array
    {
        if ($this->srvResolver !== null) {
            $records = ($this->srvResolver)($srvName);
            return is_array($records) ? $records : [];
        }
        $records = @dns_get_record($srvName, DNS_SRV);
        return is_array($records) ? $records : [];
    }

    /**
     * srv_auto_resolve 配置开关。
     */
    private function isSrvEnabled(): bool
    {
        return (bool)($this->config['srv_auto_resolve'] ?? true);
    }

    /**
     * 输入校验：host 必填、非空、长度受限、无非法字符；port 1-65535。
     *
     * 公开为公共方法，供批量并发查询（BatchPinger）复用同一套校验规则。
     *
     * @throws PingException 校验失败时抛出 1001
     */
    public static function validateHostPortStatic(string $host, int $port, int $hostMaxLength = 255): void
    {
        if ($host === '') {
            throw new PingException(PingException::ERR_INVALID_PARAMS, 'host 不能为空');
        }
        if (strlen($host) > $hostMaxLength) {
            throw new PingException(PingException::ERR_INVALID_PARAMS, "host 长度不能超过 {$hostMaxLength} 个字符");
        }
        // 拒绝空白、斜杠、反斜杠、控制字符（防止路径注入与异常输入）
        if (preg_match('/[\s\/\\\\\x00-\x1f\x7f]/', $host) === 1) {
            throw new PingException(PingException::ERR_INVALID_PARAMS, 'host 包含非法字符');
        }
        if ($port < 1 || $port > 65535) {
            throw new PingException(PingException::ERR_INVALID_PARAMS, 'port 必须是 1-65535 的整数');
        }
    }

    /**
     * 实例版输入校验（委托静态方法，沿用实例配置的 host_max_length）。
     *
     * @throws PingException 校验失败时抛出 1001
     */
    private function validateHostPort(string $host, int $port): void
    {
        self::validateHostPortStatic($host, $port, (int)($this->config['host_max_length'] ?? 255));
    }

    /**
     * IPv6 规范化：裸 IPv6（如 ::1）自动补上方括号，供 tcp:// 地址使用。
     * 已带方括号或为域名/IPv4 时原样返回。
     */
    private function normalizeHost(string $host): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            return '[' . $host . ']';
        }
        return $host;
    }

    /**
     * 将协议解析结果补全为统一的 API data 结构：
     * 核心识别、favicon 解码落盘、字段兜底、SRV 使用标记。
     *
     * 公开为公共方法，供批量并发查询（BatchPinger）复用同一套补全逻辑，
     * 保证单查与批量返回完全一致的 data 结构。
     *
     * @param array<string, mixed>       $result    协议解析结果
     * @param string                     $host      规范化后的主机
     * @param int                        $port      端口
     * @param string                     $protocol  使用的协议（modern / legacy）
     * @param bool                       $srvUsed   本次查询是否经由 SRV 解析
     * @param array<string, mixed>|null  $srvRecord SRV 记录信息 {target, port}；未使用 SRV 时为 null
     * @return array<string, mixed> 统一状态数据
     */
    public function finalize(array $result, string $host, int $port, string $protocol, bool $srvUsed = false, ?array $srvRecord = null): array
    {
        $versionName = isset($result['version']['name']) ? (string)$result['version']['name'] : null;
        $motdPlain = isset($result['motd']['plain_text']) ? (string)$result['motd']['plain_text'] : null;
        $brand = BrandDetector::detect($versionName, $motdPlain);

        $favicon = $this->saveFavicon($host, $port, $result['favicon_raw'] ?? null);

        return [
            'online' => true,
            'host' => $host,
            'port' => $port,
            'latency_ms' => $result['latency_ms'] ?? null,
            'version' => [
                'name' => $versionName,
                'protocol' => $result['version']['protocol'] ?? null,
                'brand' => $brand,
            ],
            'players' => [
                'online' => $result['players']['online'] ?? null,
                'max' => $result['players']['max'] ?? null,
                'sample' => $result['players']['sample'] ?? null,
            ],
            'motd' => [
                'raw' => $result['motd']['raw'] ?? null,
                'plain_text' => $motdPlain,
                'has_legacy_codes' => $result['motd']['has_legacy_codes'] ?? false,
                'html' => $result['motd']['html'] ?? null,
            ],
            'favicon' => $favicon,
            'protocol_used' => $protocol,
            'secure_chat' => [
                'enforces' => $result['secure_chat']['enforces'] ?? null,
                'previews' => $result['secure_chat']['previews'] ?? null,
            ],
            'srv_used' => $srvUsed,
            'srv_record' => $srvRecord,
        ];
    }

    /**
     * 解码并保存 favicon（Base64 PNG），返回 base64 / 保存路径 / URL。
     *
     * token 使用 sha256(host:port) 生成，天然为 64 位十六进制、
     * 不含路径分隔符，可安全用于文件名；同一服务器每次 ping 会覆盖旧文件。
     * 目录不存在时自动创建（0755）。
     *
     * @param string      $host       服务器地址
     * @param int         $port       端口
     * @param string|null $faviconRaw 原始 favicon 字段（可能带 data:image/png;base64, 前缀）
     * @return array{base64: ?string, saved_path: ?string, url: ?string}
     */
    private function saveFavicon(string $host, int $port, ?string $faviconRaw): array
    {
        $empty = ['base64' => null, 'saved_path' => null, 'url' => null];
        if ($faviconRaw === null || $faviconRaw === '') {
            return $empty;
        }

        // 去掉 data URI 前缀
        $base64 = $faviconRaw;
        if (str_starts_with($base64, 'data:image/png;base64,')) {
            $base64 = substr($base64, strlen('data:image/png;base64,'));
        }
        $base64 = trim($base64);
        if ($base64 === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $base64) !== 1) {
            return $empty;
        }

        $png = base64_decode($base64, true);
        if ($png === false || $png === '') {
            return $empty;
        }

        // 校验 PNG 魔数：89 50 4E 47 0D 0A 1A 0A
        $magic = "\x89PNG\r\n\x1a\n";
        if (strncmp($png, $magic, strlen($magic)) !== 0) {
            return $empty;
        }

        $dir = (string)($this->config['favicon_dir'] ?? '');
        if ($dir === '') {
            return $empty;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            // 目录创建失败时仍返回 base64，不阻断查询
            return ['base64' => $base64, 'saved_path' => null, 'url' => null];
        }

        $token = hash('sha256', $host . ':' . $port);
        $file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $token . '.png';
        if (@file_put_contents($file, $png) === false) {
            return ['base64' => $base64, 'saved_path' => null, 'url' => null];
        }

        $prefix = rtrim((string)($this->config['favicon_url_prefix'] ?? '/favicon'), '/');
        $basePath = trim((string)($this->config['base_path'] ?? ''), '/');
        if ($basePath !== '') {
            $prefix = '/' . $basePath . $prefix;
        }
        return [
            'base64' => $base64,
            'saved_path' => $file,
            'url' => $prefix . '/' . $token . '.png',
        ];
    }
}
