<?php

declare(strict_types=1);

namespace McPing;

/**
 * 批量并发状态查询（P0-2）。
 *
 * 支持一次查询多台服务器（POST /api/ping/batch），核心特性：
 *   - 非阻塞 socket 并发：对每台服务器以 STREAM_CLIENT_ASYNC_CONNECT 建立连接，
 *     通过 stream_select 轮询「可写 -> 发送握手+状态请求 -> 可读 -> 读完整包」，
 *     全部服务器并行推进，总耗时受 batch_timeout_seconds 总预算约束；
 *   - 字节构造与响应解析复用 MinecraftPing 的静态方法，保证与单查协议一致；
 *   - SRV 兜底：直接连接失败且 srv_auto_resolve 开启时，解析
 *     _minecraft._tcp.<host> 并按 SRV 目标主机+端口重试一次（保留原始错误）；
 *   - 复用 P0-1 缓存：命中直接返回 cached=true，成功结果按「请求方视角
 *     host:port」写入缓存；
 *   - 每次查询结果均记录到 SQLite 可用性监控（Monitor::record）。
 *
 * 输入（每台服务器）：
 *   { "host": "..", "port": 25565 }（port 可选，默认 default_port）
 */
final class BatchPinger
{
    /** @var array<string, mixed> 合并后的配置 */
    private array $config;

    /** @var PingClient 门面实例（SRV 解析 + finalize 复用） */
    private PingClient $client;

    /** @var int 进行中的批量查询数（供 active_batch_requests gauge） */
    private static int $activeBatches = 0;

    /**
     * @param array<string, mixed>|null $config 覆盖配置；为 null 时加载 config/config.php
     * @param PingClient|null           $client 注入的门面实例（测试注入假 SRV 解析器用）
     */
    public function __construct(?array $config = null, ?PingClient $client = null)
    {
        $defaults = PingClient::defaultConfig();
        $this->config = $config === null ? $defaults : array_merge($defaults, $config);
        $this->client = $client ?? new PingClient($this->config);
    }

    /**
     * 批量查询入口。
     *
     * @param array<int, mixed> $servers 服务器列表
     * @return array{total: int, success: int, failed: int, results: array<int, array<string, mixed>>}
     * @throws PingException 批量参数无效（1009）
     */
    public function pingBatch(array $servers): array
    {
        $maxServers = (int)($this->config['batch_max_servers'] ?? 20);
        if (count($servers) > $maxServers) {
            throw new PingException(PingException::ERR_BATCH_PARAMS, '批量查询数量超过上限 ' . $maxServers . ' 台');
        }
        if ($servers === []) {
            throw new PingException(PingException::ERR_BATCH_PARAMS, 'servers 不能为空');
        }

        // 规范化并校验每条目（结构性问题统一按 1009 处理）
        $entries = [];
        foreach ($servers as $index => $server) {
            $entries[] = $this->normalizeEntry($server, $index);
        }

        self::$activeBatches++;
        Metrics::init();
        Metrics::gauge('active_batch_requests', (float)self::$activeBatches);
        try {
            $result = $this->run($entries);
        } finally {
            self::$activeBatches--;
            Metrics::gauge('active_batch_requests', (float)self::$activeBatches);
        }

        Metrics::increment('ping_success_total', [], (float)$result['success']);
        Metrics::increment('ping_failure_total', [], (float)$result['failed']);

        return $result;
    }

    /**
     * 规范化并校验单个服务器条目。
     *
     * @param mixed $server 原始条目
     * @param int   $index  在数组中的下标（用于错误提示）
     * @return array{host: string, port: int, index: int} 规范化条目
     * @throws PingException 条目结构不合法时抛出 1009
     */
    private function normalizeEntry(mixed $server, int $index): array
    {
        if (!is_array($server)) {
            throw new PingException(PingException::ERR_BATCH_PARAMS, 'servers 第 ' . ($index + 1) . ' 项必须是对象');
        }

        $host = isset($server['host']) && is_string($server['host']) ? trim($server['host']) : '';
        if ($host === '') {
            throw new PingException(PingException::ERR_BATCH_PARAMS, 'servers 第 ' . ($index + 1) . ' 项缺少 host');
        }
        $maxHostLength = (int)($this->config['host_max_length'] ?? 255);
        if (strlen($host) > $maxHostLength) {
            throw new PingException(PingException::ERR_BATCH_PARAMS, 'servers 第 ' . ($index + 1) . ' 项 host 长度不能超过 ' . $maxHostLength . ' 个字符');
        }
        if (preg_match('/[\s\/\\\\\x00-\x1f\x7f]/', $host) === 1) {
            throw new PingException(PingException::ERR_BATCH_PARAMS, 'servers 第 ' . ($index + 1) . ' 项 host 包含非法字符');
        }

        $port = (int)($this->config['default_port'] ?? 25565);
        if (array_key_exists('port', $server)) {
            $portRaw = $server['port'];
            if (!is_int($portRaw) && !(is_string($portRaw) && is_numeric($portRaw))) {
                throw new PingException(PingException::ERR_BATCH_PARAMS, 'servers 第 ' . ($index + 1) . ' 项 port 必须是 1-65535 的整数');
            }
            $port = (int)$portRaw;
            if ($port < 1 || $port > 65535) {
                throw new PingException(PingException::ERR_BATCH_PARAMS, 'servers 第 ' . ($index + 1) . ' 项 port 必须是 1-65535 的整数');
            }
        }

        return [
            'host' => $this->normalizeHost($host),
            'port' => $port,
            'index' => $index,
        ];
    }

    /**
     * 批量查询主编排：预检缓存 -> 直接并发 -> SRV 兜底并发 -> 超时兜底 -> 组装结果。
     *
     * @param array<int, array{host: string, port: int, index: int}> $entries 规范化条目
     * @return array{total: int, success: int, failed: int, results: array<int, array<string, mixed>>}
     */
    private function run(array $entries): array
    {
        $cacheEnabled = (bool)($this->config['cache_enabled'] ?? true);
        $cacheTtl = (int)($this->config['cache_ttl_seconds'] ?? 30);
        $srvEnabled = (bool)($this->config['srv_auto_resolve'] ?? true);
        $totalBudget = (float)($this->config['batch_timeout_seconds'] ?? 10.0);
        $deadline = microtime(true) + max(0.1, $totalBudget);

        $results = [];   // 条目索引 => 最终 data
        $failures = [];  // 条目索引 => ['code' => int, 'message' => string]
        $firstErrors = []; // 条目索引 => 直接连接阶段的原始错误（SRV 重试失败时保留）
        $success = 0;
        $failed = 0;

        // 待处理任务：requestHost/requestPort 为请求方视角（缓存键与监控用）
        $pending = [];

        // 预检缓存：命中直接返回
        foreach ($entries as $entry) {
            $i = $entry['index'];
            $cacheKey = PingClient::cacheKeyFor($entry['host'], $entry['port']);
            if ($cacheEnabled && Cache::instance()->has($cacheKey)) {
                $cached = Cache::instance()->get($cacheKey);
                if (is_array($cached)) {
                    $cached['cached'] = true;
                    $results[$i] = $cached;
                    $success++;
                    continue;
                }
            }
            $pending[$i] = [
                'requestHost' => $entry['host'],
                'requestPort' => $entry['port'],
                'host' => $entry['host'],
                'port' => $entry['port'],
                'isSrv' => false,
                'srv' => null,
            ];
        }

        // 主循环：每轮并发查询当前 pending；失败且允许 SRV 时排入下一轮
        while (!empty($pending) && microtime(true) < $deadline) {
            $outcomes = $this->queryConcurrent($pending, $deadline);
            $nextPending = [];

            foreach ($outcomes as $i => $outcome) {
                $task = $pending[$i];

                if ($outcome['ok']) {
                    $data = $outcome['data'];
                    if ($cacheEnabled) {
                        Cache::instance()->set(
                            PingClient::cacheKeyFor($task['requestHost'], $task['requestPort']),
                            $data,
                            $cacheTtl
                        );
                    }
                    Monitor::record($task['requestHost'], $task['requestPort'], $data);
                    $results[$i] = $data;
                    $success++;
                    continue;
                }

                // 失败：直接连接阶段且允许 SRV 时尝试兜底
                if (!$task['isSrv'] && $srvEnabled && $this->canResolveSrv($task['requestHost'])) {
                    $srv = $this->client->resolveSrv($task['requestHost']);
                    if ($srv !== null) {
                        $firstErrors[$i] = ['code' => $outcome['code'], 'message' => $outcome['message']];
                        $nextPending[$i] = [
                            'requestHost' => $task['requestHost'],
                            'requestPort' => $task['requestPort'],
                            'host' => $srv['host'],
                            'port' => $srv['port'],
                            'isSrv' => true,
                            'srv' => ['target' => $srv['target'], 'port' => $srv['port']],
                        ];
                        continue;
                    }
                }

                // 无 SRV 可兜底或 SRV 重试仍失败：保留直接连接阶段的原始错误
                $error = $firstErrors[$i] ?? ['code' => $outcome['code'], 'message' => $outcome['message']];
                unset($firstErrors[$i]);
                Monitor::record($task['requestHost'], $task['requestPort'], null);
                $failures[$i] = $error;
                $failed++;
            }

            $pending = $nextPending;
        }

        // 预算耗尽仍未完成：统一按超时失败
        foreach ($pending as $i => $task) {
            Monitor::record($task['requestHost'], $task['requestPort'], null);
            $failures[$i] = [
                'code' => PingException::ERR_TIMEOUT,
                'message' => '批量查询超时：服务器 ' . $task['requestHost'] . ':' . $task['requestPort'] . ' 无响应',
            ];
            $failed++;
        }

        // 按输入顺序组装结果
        $ordered = [];
        foreach ($entries as $entry) {
            $i = $entry['index'];
            if (isset($results[$i])) {
                $ordered[] = $results[$i];
            } else {
                $failure = $failures[$i] ?? ['code' => PingException::ERR_OFFLINE, 'message' => '服务器离线'];
                $ordered[] = $this->failureData($entry['host'], $entry['port'], $failure['code'], $failure['message']);
            }
        }

        return [
            'total' => count($entries),
            'success' => $success,
            'failed' => $failed,
            'results' => $ordered,
        ];
    }

    /**
     * 并发查询一批任务（非阻塞 socket + stream_select 轮询）。
     *
     * @param array<int, array<string, mixed>> $tasks    任务列表（键为条目索引）
     * @param float                            $deadline 截止时间戳（微秒）
     * @return array<int, array<string, mixed>> 每个任务的结果：
     *         ['ok' => true, 'data' => 统一 data] 或 ['ok' => false, 'code' => int, 'message' => string]
     */
    private function queryConcurrent(array $tasks, float $deadline): array
    {
        $results = [];
        $sockets = [];     // 条目索引 => socket 资源
        $requests = [];    // 条目索引 => 请求字节
        $writeOffsets = []; // 条目索引 => 已写入字节数（非阻塞可能半写）
        $sent = [];        // 条目索引 => 请求是否已全部写入
        $buffers = [];     // 条目索引 => 累积读取缓冲区
        $startedAt = [];   // 条目索引 => 请求写完时刻（微秒）
        $socketDeadlines = []; // 条目索引 => 单任务超时截止（逐包超时，受总预算封顶）
        $protocolVersion = (int)($this->config['protocol_version'] ?? -1);
        $perSocketTimeout = (float)($this->config['timeout_seconds'] ?? 5.0);

        // 第一轮：为每个任务打开异步连接并准备请求字节
        foreach ($tasks as $i => $task) {
            $fp = @stream_socket_client(
                'tcp://' . $task['host'] . ':' . $task['port'],
                $errno,
                $errstr,
                0,
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
            );
            if ($fp === false) {
                $exception = MinecraftPing::mapConnectErrorStatic($errno, $errstr, $task['host'], $task['port']);
                $results[$i] = ['ok' => false, 'code' => $exception->getErrorCode(), 'message' => $exception->getMessage()];
                continue;
            }
            stream_set_blocking($fp, false);
            $sockets[$i] = $fp;
            $requests[$i] = MinecraftPing::buildStatusRequestBytes($task['host'], $task['port'], $protocolVersion);
            // 逐包超时：连接建立 + 读写合计不超过 timeout_seconds，且不越过总预算
            $socketDeadlines[$i] = min($deadline, microtime(true) + $perSocketTimeout);
        }

        // 轮询循环：可写 -> 发送；可读 -> 读取并尝试解析完整包
        while (!empty($sockets) && microtime(true) < $deadline) {
            $read = [];
            $write = [];
            foreach ($sockets as $i => $fp) {
                $read[] = $fp;
                if (!isset($sent[$i])) {
                    $write[] = $fp;
                }
            }
            if ($read === [] && $write === []) {
                break;
            }

            // 本轮等待时长 = min(总预算, 各任务逐包超时)
            $nextDeadline = $deadline;
            foreach ($sockets as $i => $unused) {
                if (($socketDeadlines[$i] ?? $deadline) < $nextDeadline) {
                    $nextDeadline = $socketDeadlines[$i];
                }
            }
            $remain = $nextDeadline - microtime(true);
            if ($remain <= 0) {
                // 清理已超过逐包超时的 socket（统一判读取超时）
                $now = microtime(true);
                foreach ($sockets as $i => $fp) {
                    if (($socketDeadlines[$i] ?? $now) <= $now) {
                        $this->closeSocket($sockets, $i);
                        if (!isset($results[$i])) {
                            $results[$i] = [
                                'ok' => false,
                                'code' => PingException::ERR_TIMEOUT,
                                'message' => '读取服务器响应超时',
                            ];
                        }
                    }
                }
                continue;
            }
            $sec = (int)floor($remain);
            $usec = (int)(($remain - $sec) * 1000000);
            $except = null;
            $selected = @stream_select($read, $write, $except, $sec, $usec);
            if ($selected === false) {
                break;
            }
            if ($selected === 0) {
                // select 超时：回到循环顶部统一处理逐包超时
                continue;
            }

            // 可写事件：确认连接建立后发送请求（处理半写）
            foreach ($write as $fp) {
                $i = $this->socketIndex($fp, $sockets);
                if ($i === null || isset($sent[$i])) {
                    continue;
                }
                if (@stream_socket_get_name($fp, true) === false) {
                    // 异步连接失败
                    $this->closeSocket($sockets, $i);
                    $results[$i] = [
                        'ok' => false,
                        'code' => PingException::ERR_REFUSED,
                        'message' => '连接失败：无法连接到服务器 ' . $tasks[$i]['host'] . ':' . $tasks[$i]['port'],
                    ];
                    continue;
                }
                $request = $requests[$i];
                $offset = $writeOffsets[$i] ?? 0;
                $written = @fwrite($fp, substr($request, $offset));
                if ($written === false || $written === 0) {
                    $this->closeSocket($sockets, $i);
                    $results[$i] = [
                        'ok' => false,
                        'code' => PingException::ERR_REFUSED,
                        'message' => '连接被拒绝：服务器 ' . $tasks[$i]['host'] . ':' . $tasks[$i]['port'] . ' 未监听或不可达',
                    ];
                    continue;
                }
                $writeOffsets[$i] = $offset + $written;
                if ($writeOffsets[$i] >= strlen($request)) {
                    $sent[$i] = true;
                    $startedAt[$i] = microtime(true);
                }
            }

            // 可读事件：读取数据并尝试解析完整状态响应包
            foreach ($read as $fp) {
                $i = $this->socketIndex($fp, $sockets);
                if ($i === null) {
                    continue;
                }
                if (!isset($sent[$i])) {
                    // 尚未发送请求即可读：连接失败或对端立即关闭，交由可写事件处理
                    continue;
                }
                $chunk = @fread($fp, 65536);
                if ($chunk === false || $chunk === '') {
                    $meta = stream_get_meta_data($fp);
                    if (!empty($meta['timed_out'])) {
                        $this->closeSocket($sockets, $i);
                        $results[$i] = ['ok' => false, 'code' => PingException::ERR_TIMEOUT, 'message' => '读取服务器响应超时'];
                        continue;
                    }
                    $this->closeSocket($sockets, $i);
                    $results[$i] = ['ok' => false, 'code' => PingException::ERR_PROTOCOL, 'message' => '服务器响应中断或关闭连接'];
                    continue;
                }
                $buffers[$i] = ($buffers[$i] ?? '') . $chunk;

                try {
                    $packet = $this->extractCompletePacket($buffers[$i]);
                } catch (PingException $e) {
                    $this->closeSocket($sockets, $i);
                    $results[$i] = ['ok' => false, 'code' => $e->getErrorCode(), 'message' => $e->getMessage()];
                    continue;
                }
                if ($packet === null) {
                    // 包不完整：继续等待更多数据
                    continue;
                }

                $this->closeSocket($sockets, $i);
                try {
                    $json = MinecraftPing::parseStatusResponseBody($packet);
                    $latency = (int)round((microtime(true) - $startedAt[$i]) * 1000);
                    $intermediate = MinecraftPing::buildResult($json, $latency);
                    $data = $this->client->finalize(
                        $intermediate,
                        $tasks[$i]['host'],
                        $tasks[$i]['port'],
                        'modern',
                        (bool)$tasks[$i]['isSrv'],
                        is_array($tasks[$i]['srv']) ? $tasks[$i]['srv'] : null
                    );
                    $results[$i] = ['ok' => true, 'data' => $data];
                } catch (PingException $e) {
                    $results[$i] = ['ok' => false, 'code' => $e->getErrorCode(), 'message' => $e->getMessage()];
                } catch (\Throwable $e) {
                    $results[$i] = ['ok' => false, 'code' => PingException::ERR_PROTOCOL, 'message' => '协议异常：' . $e->getMessage()];
                }
            }
        }

        // 预算耗尽仍未完成：关闭连接并统一判超时
        foreach ($sockets as $i => $fp) {
            $this->closeSocket($sockets, $i);
            if (!isset($results[$i])) {
                $results[$i] = [
                    'ok' => false,
                    'code' => PingException::ERR_TIMEOUT,
                    'message' => '批量查询超时：服务器 ' . $tasks[$i]['host'] . ':' . $tasks[$i]['port'] . ' 无响应',
                ];
            }
        }

        return $results;
    }

    /**
     * 从累积缓冲区中尝试提取一个完整的 status 响应包体。
     *
     * 包结构为 [varint 包长度][包体]。缓冲区可能只包含半个包，
     * 此时返回 null（等待更多数据）；包长度非法或超过上限时抛协议错误。
     *
     * @param string $buffer 累积读取缓冲区
     * @return string|null 完整包体；不完整返回 null
     * @throws PingException 包长度 varint 异常或超过上限
     */
    private function extractCompletePacket(string $buffer): ?string
    {
        $bufLen = strlen($buffer);
        $offset = 0;
        $packetLength = 0;
        $shift = 0;
        $varintComplete = false;

        while ($offset < $bufLen && $offset < 5) {
            $byte = ord($buffer[$offset]);
            $offset++;
            $packetLength |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                $varintComplete = true;
                break;
            }
            $shift += 7;
        }

        if (!$varintComplete) {
            // 5 字节内未读到终止位：要么缓冲区太小，要么 varint 非法
            if ($bufLen >= 5) {
                throw new PingException(PingException::ERR_PROTOCOL, '包长度 varint 异常');
            }
            return null;
        }

        if ($packetLength < 0 || $packetLength > (int)($this->config['max_packet_bytes'] ?? 2097152)) {
            throw new PingException(PingException::ERR_PROTOCOL, '包长度非法或超过上限');
        }
        if ($bufLen < $offset + $packetLength) {
            return null;
        }
        return substr($buffer, $offset, $packetLength);
    }

    /**
     * 判断主机是否可能具有 SRV 记录（域名可解析；IP 字面量跳过）。
     */
    private function canResolveSrv(string $host): bool
    {
        if (str_starts_with($host, '[')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        return true;
    }

    /**
     * 查找 socket 资源对应的条目索引。
     *
     * @param resource               $fp      socket 资源
     * @param array<int, resource>   $sockets 索引 -> 资源 映射
     */
    private function socketIndex($fp, array $sockets): ?int
    {
        foreach ($sockets as $i => $candidate) {
            if ($candidate === $fp) {
                return $i;
            }
        }
        return null;
    }

    /**
     * 关闭并移除指定索引的 socket。
     *
     * @param array<int, resource> $sockets 索引 -> 资源 映射（引用传递）
     */
    private function closeSocket(array &$sockets, int $index): void
    {
        if (isset($sockets[$index]) && is_resource($sockets[$index])) {
            @fclose($sockets[$index]);
        }
        unset($sockets[$index]);
    }

    /**
     * IPv6 规范化：裸 IPv6 自动补方括号（与 PingClient 一致）。
     */
    private function normalizeHost(string $host): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            return '[' . $host . ']';
        }
        return $host;
    }

    /**
     * 构造失败条目数据（含友好错误信息）。
     *
     * @return array<string, mixed>
     */
    private function failureData(string $host, int $port, int $code, string $message): array
    {
        return [
            'online' => false,
            'host' => $host,
            'port' => $port,
            'latency_ms' => null,
            'version' => ['name' => null, 'protocol' => null, 'brand' => null],
            'players' => ['online' => null, 'max' => null, 'sample' => null],
            'motd' => ['raw' => null, 'plain_text' => null, 'has_legacy_codes' => false, 'html' => null],
            'favicon' => ['base64' => null, 'saved_path' => null, 'url' => null],
            'protocol_used' => null,
            'secure_chat' => ['enforces' => null, 'previews' => null],
            'srv_used' => false,
            'srv_record' => null,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
