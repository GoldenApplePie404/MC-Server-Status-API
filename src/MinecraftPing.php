<?php

declare(strict_types=1);

namespace McPing;

/**
 * Minecraft 1.7+ 现代状态协议（Server List Ping）实现。
 *
 * 协议流程：
 *   1. TCP 连接目标服务器；
 *   2. 发送握手包（packet id 0x00，nextState=1 状态查询）；
 *   3. 发送状态请求包（packet id 0x00，内容为空）；
 *   4. 读取状态响应包（packet id 0x00，内含 JSON）；
 *   5. 可选：发送 Ping 包（0x01 + 8 字节负载）并接收 Pong 包，测量 RTT 延迟。
 *
 * 所有包结构均为 [varint 包长度][包内容]，包内容首字节为包 ID。
 */
final class MinecraftPing
{
    /** 连接与读写超时（秒） */
    private float $timeout;

    /** 单次响应包最大字节数（防恶意超大包） */
    private int $maxPacketBytes;

    /**
     * 握手协议版本候选列表。
     * 状态查询通常传 -1 即可，但个别服务器（如 Hypixel）会拒绝 -1 并直接断开，
     * 因此失败时依次尝试真实协议版本（如 767 = 1.20.6、765 = 1.20.4）。
     *
     * @var int[]
     */
    private array $protocolCandidates;

    /**
     * @param array<string, mixed> $config 配置数组（至少包含 timeout_seconds / max_packet_bytes）
     */
    public function __construct(array $config = [])
    {
        $this->timeout = (float)($config['timeout_seconds'] ?? 5.0);
        $this->maxPacketBytes = (int)($config['max_packet_bytes'] ?? 2097152);

        $primary = (int)($config['protocol_version'] ?? -1);
        $fallbacks = (array)($config['protocol_fallback_versions'] ?? [767, 769, 765, 771]);
        $this->protocolCandidates = array_values(array_unique(array_merge([$primary], $fallbacks)));
    }

    /**
     * 执行 1.7+ 状态查询。
     *
     * 连接层失败（DNS/拒绝/超时/不可达）直接抛出，不重试；
     * 已连接但协议被拒/响应异常时，按候选协议版本列表依次重试（每次重新连接）。
     *
     * @param string $host 服务器地址（IPv6 需带方括号）
     * @param int    $port 端口
     * @return array<string, mixed> 解析后的状态数据
     * @throws PingException 连接失败 / 超时 / 协议错误时抛出
     */
    public function ping(string $host, int $port): array
    {
        $lastError = null;

        foreach ($this->protocolCandidates as $protocol) {
            $fp = null;
            try {
                $fp = $this->connect($host, $port);
                return $this->pingConnected($fp, $host, $port, $protocol);
            } catch (PingException $e) {
                $lastError = $e;
                // 连接层失败（$fp 为 null）或读取超时：更换协议版本无意义，终止重试
                if ($fp === null || $e->getErrorCode() === PingException::ERR_TIMEOUT) {
                    break;
                }
                // 已连接但协议被拒/响应异常：继续尝试下一个协议版本
            } finally {
                if (is_resource($fp)) {
                    fclose($fp);
                }
            }
        }

        if ($lastError instanceof PingException) {
            throw $lastError;
        }
        throw new PingException(PingException::ERR_OFFLINE, '无法获取服务器状态');
    }

    /**
     * 在已建立的连接上执行握手、状态请求、读取与延迟测量。
     *
     * @param resource $fp       已连接的套接字
     * @param string   $host     服务器地址
     * @param int      $port     端口
     * @param int      $protocol 握手使用的协议版本号
     * @return array<string, mixed> 解析后的状态数据
     * @throws PingException 协议错误 / 超时 / 连接关闭时抛出
     */
    private function pingConnected($fp, string $host, int $port, int $protocol): array
    {
        $this->sendHandshake($fp, $host, $port, $protocol);
        $this->sendStatusRequest($fp);

        // 读取状态响应包。包结构为 [长度 varint][包内容]，
        // 包内容 = [包 ID 0x00][varint 字符串长度][JSON 字节]。
        // readPacket 已按长度读取完整包体，因此字符串长度与 JSON 须从包体缓冲区解析，
        // 不能再从流中二次读取（否则会在已读完的流上阻塞超时）。
        $body = $this->readPacket($fp);
        $json = self::parseStatusResponseBody($body);

        // 延迟测量失败不影响已获取的状态数据
        $latency = $this->measureLatency($fp);

        return self::buildResult($json, $latency);
    }

    /**
     * 构造 1.7+ 状态查询的完整请求字节（批量并发查询复用）。
     *
     * 返回字节 = 握手包（[varint 长度][0x00][varint 协议][string host][pack('n',port)][varint 1]）
     *          + 状态请求包（[varint 长度][0x00]）。
     * 协议版本固定使用调用方传入值（默认 -1，服务器通常接受"仅用于状态查询"）。
     *
     * @param string $host     服务器地址（IPv6 需带方括号，与 tcp:// 地址一致）
     * @param int    $port     端口
     * @param int    $protocol 握手协议版本号
     */
    public static function buildStatusRequestBytes(string $host, int $port, int $protocol = -1): string
    {
        $content = VarInt::encode(0x00)          // 包 ID
            . VarInt::encode($protocol)           // 协议版本号
            . VarInt::writeString($host)          // 服务器地址（varint 长度 + UTF-8）
            . pack('n', $port)                    // 端口：2 字节大端（无符号短整型）
            . VarInt::encode(1);                  // nextState = 1（状态查询）
        $handshake = VarInt::encode(strlen($content)) . $content;
        $statusRequest = VarInt::encode(1) . "\x00";
        return $handshake . $statusRequest;
    }

    /**
     * 解析状态响应包体（[0x00][varint 字符串长度][JSON 字节]）为 JSON 数组。
     *
     * 与 pingConnected 共享同一套解析逻辑，供批量并发查询在非阻塞读取
     * 累积的缓冲区上解析完整响应。
     *
     * @param string $body 完整包体（含包 ID 首字节）
     * @return array<string, mixed> 状态响应 JSON
     * @throws PingException 包 ID 异常 / 字符串长度异常 / JSON 解析失败
     */
    public static function parseStatusResponseBody(string $body): array
    {
        if ($body === '' || $body[0] !== "\x00") {
            throw new PingException(PingException::ERR_PROTOCOL, '状态响应包 ID 异常');
        }
        $offset = 1;
        $jsonLength = VarInt::decode($body, $offset);
        if ($jsonLength < 0 || $offset + $jsonLength > strlen($body)) {
            throw new PingException(PingException::ERR_PROTOCOL, '状态响应字符串长度异常');
        }
        $jsonString = substr($body, $offset, $jsonLength);
        $json = json_decode($jsonString, true);
        if (!is_array($json)) {
            throw new PingException(PingException::ERR_PROTOCOL, '状态响应 JSON 解析失败');
        }
        return $json;
    }

    /**
     * 建立 TCP 连接并设置超时。
     *
     * @return resource 套接字资源
     * @throws PingException 连接失败时抛出（错误码映射到 DNS/拒绝/超时/离线）
     */
    private function connect(string $host, int $port)
    {
        $errno = 0;
        $errstr = '';
        $target = 'tcp://' . $host . ':' . $port;
        $fp = @stream_socket_client($target, $errno, $errstr, $this->timeout);
        if ($fp === false) {
            throw $this->mapConnectError($errno, $errstr, $host, $port);
        }
        // 设置读写超时：秒 + 微秒
        $seconds = (int)floor($this->timeout);
        $microseconds = (int)(($this->timeout - $seconds) * 1000000);
        stream_set_timeout($fp, $seconds, $microseconds);
        return $fp;
    }

    /**
     * 发送握手包。
     *
     * 握手包内容（包 ID 0x00）：
     *   [varint 协议版本号] [varint 字符串长度 + 字符串(服务器地址)]
     *   [无符号短整型(端口, 2 字节大端)] [varint nextState]
     * 状态查询时协议版本号通常传 -1（服务器不校验）；个别服务器拒绝 -1，
     * 此时由调用方改用真实协议版本重试（见 ping()）。
     */
    private function sendHandshake($fp, string $host, int $port, int $protocol): void
    {
        $content = VarInt::encode(0x00)          // 包 ID
            . VarInt::encode($protocol)           // 协议版本号
            . VarInt::writeString($host)          // 服务器地址（varint 长度 + UTF-8）
            . pack('n', $port)                    // 端口：2 字节大端（无符号短整型）
            . VarInt::encode(1);                  // nextState = 1（状态查询）
        $this->sendPacket($fp, $content);
    }

    /**
     * 发送状态请求包：包内容仅包 ID 0x00，无其他数据。
     */
    private function sendStatusRequest($fp): void
    {
        $this->sendPacket($fp, "\x00");
    }

    /**
     * 发送 Ping 包并测量 RTT 延迟。
     *
     * Ping 包：包 ID 0x01 + 8 字节随机负载；
     * 服务器返回 Pong 包（0x01 + 相同负载）。
     * 部分服务器在返回状态后立即关闭连接，此时延迟记为 null。
     *
     * @return int|null 延迟毫秒数；无法测量时为 null
     */
    private function measureLatency($fp): ?int
    {
        try {
            $payload = random_bytes(8);
            $this->sendPacket($fp, "\x01" . $payload);
            $start = microtime(true);
            $body = $this->readPacket($fp);
            $elapsedMs = (int)round((microtime(true) - $start) * 1000);
            if ($body === '' || $body[0] !== "\x01" || substr($body, 1) !== $payload) {
                return null;
            }
            return $elapsedMs;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 将解析后的 JSON 组织为统一的状态数据。
     *
     * 公开为静态方法，供批量并发查询（BatchPinger）在非阻塞读取完成后复用，
     * 保证单查与批量产出完全一致的中间结果结构。
     *
     * @param array<string, mixed> $json    状态响应 JSON
     * @param int|null             $latency 延迟毫秒数
     * @return array<string, mixed> 状态数据（favicon 原始数据存于 favicon_raw，由门面类落盘）
     */
    public static function buildResult(array $json, ?int $latency): array
    {
        $versionName = isset($json['version']['name']) ? (string)$json['version']['name'] : null;
        $protocol = isset($json['version']['protocol']) ? (int)$json['version']['protocol'] : null;

        $online = isset($json['players']['online']) ? (int)$json['players']['online'] : null;
        $max = isset($json['players']['max']) ? (int)$json['players']['max'] : null;

        $sample = [];
        if (isset($json['players']['sample']) && is_array($json['players']['sample'])) {
            foreach ($json['players']['sample'] as $player) {
                if (!is_array($player)) {
                    continue;
                }
                $sample[] = [
                    'name' => isset($player['name']) ? (string)$player['name'] : '',
                    'uuid' => isset($player['id']) ? (string)$player['id'] : '',
                ];
            }
        }
        if ($sample === []) {
            $sample = null;
        }

        $motd = MotdParser::parse($json['description'] ?? null);
        $favicon = isset($json['favicon']) ? (string)$json['favicon'] : null;

        return [
            'latency_ms' => $latency,
            'version' => [
                'name' => $versionName,
                'protocol' => $protocol,
            ],
            'players' => [
                'online' => $online,
                'max' => $max,
                'sample' => $sample,
            ],
            'motd' => $motd,
            'favicon_raw' => $favicon,
            'secure_chat' => [
                'enforces' => isset($json['enforcesSecureChat']) ? (bool)$json['enforcesSecureChat'] : null,
                'previews' => isset($json['previewsChat']) ? (bool)$json['previewsChat'] : null,
            ],
        ];
    }

    /**
     * 发送一个完整包：[varint 长度][内容]。
     */
    private function sendPacket($fp, string $payload): void
    {
        $packet = VarInt::encode(strlen($payload)) . $payload;
        $written = @fwrite($fp, $packet);
        if ($written === false || $written !== strlen($packet)) {
            throw new PingException(PingException::ERR_OFFLINE, '向服务器发送数据失败');
        }
    }

    /**
     * 从流中读取一个完整包（先读 varint 包长度，再读包内容）。
     *
     * @return string 包内容（含包 ID 首字节）
     * @throws PingException 长度非法/超时/连接关闭时抛出
     */
    private function readPacket($fp): string
    {
        $length = $this->readVarIntFromStream($fp);
        if ($length < 0 || $length > $this->maxPacketBytes) {
            throw new PingException(PingException::ERR_PROTOCOL, '包长度非法或超过上限');
        }
        return $this->readExactly($fp, $length);
    }

    /**
     * 从流中逐字节读取一个 VarInt。
     *
     * @throws PingException 超时/连接关闭/VarInt 过长时抛出
     */
    private function readVarIntFromStream($fp): int
    {
        $result = 0;
        $shift = 0;
        while (true) {
            $byteData = @fread($fp, 1);
            if ($byteData === false || $byteData === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    throw new PingException(PingException::ERR_TIMEOUT, '读取服务器响应超时');
                }
                throw new PingException(PingException::ERR_OFFLINE, '服务器在响应前关闭了连接');
            }
            $byte = ord($byteData);
            $result |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                if ($result >= 0x80000000) {
                    $result -= 0x100000000;
                }
                return $result;
            }
            $shift += 7;
            if ($shift >= 35) {
                throw new PingException(PingException::ERR_PROTOCOL, 'VarInt 长度字段异常');
            }
        }
    }

    /**
     * 从流中读取精确长度的字节（循环读取，处理 TCP 分片）。
     *
     * @throws PingException 超时/连接关闭/超过大小限制时抛出
     */
    private function readExactly($fp, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($fp, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    throw new PingException(PingException::ERR_TIMEOUT, '读取服务器响应超时');
                }
                throw new PingException(PingException::ERR_PROTOCOL, '服务器响应中断或关闭连接');
            }
            $data .= $chunk;
            if (strlen($data) > $this->maxPacketBytes) {
                throw new PingException(PingException::ERR_PROTOCOL, '响应包超过大小限制');
            }
        }
        return $data;
    }

    /**
     * 将 stream_socket_client 的错误映射为带错误码的 PingException。
     *
     * 常见错误特征：
     *   - getaddrinfo / php_network_getaddresses -> DNS 解析失败（1004）
     *   - Connection refused（111 / 10061）      -> 连接被拒绝（1003）
     *   - Operation timed out（110 / 10060）     -> 连接超时（1002）
     *   - Network is unreachable（113/10051/10065）-> 服务器离线（1006）
     */
    private function mapConnectError(int $errno, string $errstr, string $host, int $port): PingException
    {
        return self::mapConnectErrorStatic($errno, $errstr, $host, $port);
    }

    /**
     * 将 stream_socket_client 的错误映射为带错误码的 PingException（静态版本）。
     *
     * 供批量并发查询（BatchPinger）复用同一套连接错误分类规则，
     * 保证单查与批量返回的错误码语义一致。
     */
    public static function mapConnectErrorStatic(int $errno, string $errstr, string $host, int $port): PingException
    {
        $lower = strtolower($errstr . ' ' . $errno);

        if (
            $errno === 0
            || str_contains($lower, 'getaddrinfo')
            || str_contains($lower, 'php_network_getaddresses')
            || str_contains($lower, 'name or service not known')
            || str_contains($lower, 'nodename nor servname')
        ) {
            return new PingException(PingException::ERR_DNS, "DNS 解析失败：无法解析主机 {$host}");
        }

        if (str_contains($lower, 'refused') || $errno === 111 || $errno === 10061) {
            return new PingException(PingException::ERR_REFUSED, "连接被拒绝：服务器 {$host}:{$port} 未监听或不可达");
        }

        if (str_contains($lower, 'timed out') || $errno === 110 || $errno === 10060) {
            return new PingException(PingException::ERR_TIMEOUT, "连接超时：服务器 {$host}:{$port} 无响应");
        }

        if (
            str_contains($lower, 'unreachable')
            || $errno === 113
            || $errno === 10051
            || $errno === 10065
        ) {
            return new PingException(PingException::ERR_OFFLINE, "服务器离线：网络不可达（{$host}:{$port}）");
        }

        return new PingException(PingException::ERR_OFFLINE, "无法连接到服务器 {$host}:{$port}（{$errstr}）");
    }
}
