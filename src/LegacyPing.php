<?php

declare(strict_types=1);

namespace McPing;

/**
 * Minecraft legacy 0xFE 传统协议实现（适用于 1.4 ~ 1.6 老版本服务器）。
 *
 * 协议流程：
 *   1. TCP 连接目标服务器；
 *   2. 发送 0xFE 0x01 两个字节；
 *   3. 读取响应：0xFF + 无符号短整型长度（UTF-16BE 字符数）+ UTF-16BE 编码内容；
 *   4. 内容格式（1.4+）：§1\0<协议版本>\0<版本名>\0<MOTD>\0<在线人数>\0<最大人数>
 *      （各字段以 NUL 分隔，首段以 §1 开头）；
 *   5. 更老版本（<=1.3）：内容为 ASCII §1<MOTD>§<在线人数>§<最大人数>。
 *
 * 注意：legacy 协议无玩家列表与 favicon，相关字段返回空。
 */
final class LegacyPing
{
    /** 连接与读写超时（秒） */
    private float $timeout;

    /** 单次响应最大字节数 */
    private int $maxPacketBytes;

    /**
     * @param array<string, mixed> $config 配置数组（至少包含 timeout_seconds / max_packet_bytes）
     */
    public function __construct(array $config = [])
    {
        $this->timeout = (float)($config['timeout_seconds'] ?? 5.0);
        $this->maxPacketBytes = (int)($config['max_packet_bytes'] ?? 2097152);
    }

    /**
     * 执行 legacy 状态查询。
     *
     * @param string $host 服务器地址（IPv6 需带方括号）
     * @param int    $port 端口
     * @return array<string, mixed> 解析后的状态数据
     * @throws PingException 连接失败 / 超时 / 协议错误时抛出
     */
    public function ping(string $host, int $port): array
    {
        $start = microtime(true);
        $fp = $this->connect($host, $port);
        try {
            // 发送 legacy 请求：0xFE 0x01
            $written = @fwrite($fp, "\xFE\x01");
            if ($written === false || $written !== 2) {
                throw new PingException(PingException::ERR_OFFLINE, '向服务器发送 legacy 请求失败');
            }

            // 响应首字节应为 0xFF
            $first = $this->readExactly($fp, 1);
            if ($first !== "\xFF") {
                throw new PingException(PingException::ERR_PROTOCOL, 'Legacy 响应首字节不是 0xFF');
            }

            // 长度字段：无符号短整型（UTF-16BE 字符数），2 字节大端
            $lengthBytes = $this->readExactly($fp, 2);
            $charCount = unpack('n', $lengthBytes)[1];
            if ($charCount <= 0 || $charCount > (int)floor($this->maxPacketBytes / 2)) {
                throw new PingException(PingException::ERR_PROTOCOL, 'Legacy 响应长度异常');
            }

            // 每个 UTF-16BE 码元占 2 字节，读取 $charCount * 2 字节
            $payload = $this->readExactly($fp, $charCount * 2);
            $text = $this->utf16beToUtf8($payload);

            $latencyMs = (int)round((microtime(true) - $start) * 1000);
            $parsed = self::parseLegacyPayload($text);
            $parsed['latency_ms'] = $latencyMs;
            return $parsed;
        } finally {
            fclose($fp);
        }
    }

    /**
     * 解析 legacy 响应文本（UTF-8 转换后）。
     *
     * 兼容 1.4+ 的 NUL 分隔格式与 <=1.3 的 § 分隔格式。
     * 公开为静态方法便于单元测试。
     *
     * @param string $text 已转换为 UTF-8 的 legacy 响应内容
     * @return array<string, mixed> 状态数据
     * @throws PingException 无法识别格式时抛出协议错误
     */
    public static function parseLegacyPayload(string $text): array
    {
        // 部分实现会在开头带一个 NUL，先剔除
        $s = ltrim($text, "\x00");

        // 1.4+ 格式：NUL 分隔
        if (str_contains($s, "\x00")) {
            $parts = explode("\x00", $s);
            // 首段以 §1 开头（部分服务器 §1 后直接跟字段，无额外 NUL）。
            // 注意：UTF-8 下 "§1" 占 3 字节（C2 A7 31），须用字节偏移 3 裁剪。
            if (isset($parts[0]) && str_starts_with($parts[0], '§1')) {
                $parts[0] = substr($parts[0], 3);
                if ($parts[0] === '') {
                    array_shift($parts);
                }
            }

            $protocol = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : null;
            $version = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
            $motd = isset($parts[2]) ? $parts[2] : '';
            $online = isset($parts[3]) && is_numeric($parts[3]) ? (int)$parts[3] : null;
            $max = isset($parts[4]) && is_numeric($parts[4]) ? (int)$parts[4] : null;
        } else {
            // <=1.3 格式：§1<MOTD>§<在线人数>§<最大人数>
            if (!str_starts_with($s, '§1')) {
                throw new PingException(PingException::ERR_PROTOCOL, '无法解析 Legacy 响应格式');
            }
            // "§1" 为 3 字节（C2 A7 31），用字节偏移 3 裁剪
            $rest = substr($s, 3);
            $parts = explode('§', $rest);
            $motd = $parts[0] ?? '';
            $online = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
            $max = isset($parts[2]) && is_numeric($parts[2]) ? (int)$parts[2] : null;
            $protocol = null;
            $version = null;
        }

        $motdParsed = MotdParser::parse($motd);

        return [
            'latency_ms' => null,
            'version' => [
                'name' => $version,
                'protocol' => $protocol,
            ],
            'players' => [
                'online' => $online,
                'max' => $max,
                'sample' => null,
            ],
            'motd' => $motdParsed,
            'favicon_raw' => null,
            'secure_chat' => [
                'enforces' => null,
                'previews' => null,
            ],
        ];
    }

    /**
     * 建立 TCP 连接并设置超时（与 MinecraftPing 逻辑一致）。
     *
     * @return resource 套接字资源
     * @throws PingException 连接失败时抛出
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
        $seconds = (int)floor($this->timeout);
        $microseconds = (int)(($this->timeout - $seconds) * 1000000);
        stream_set_timeout($fp, $seconds, $microseconds);
        return $fp;
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
     * 将 UTF-16BE 二进制转换为 UTF-8。
     *
     * 优先使用 mbstring，其次 iconv，最后手工解码（含代理对处理），
     * 保证无扩展环境也可运行。
     *
     * @param string $bytes UTF-16BE 原始字节
     * @return string UTF-8 字符串
     */
    private function utf16beToUtf8(string $bytes): string
    {
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
            if (is_string($converted)) {
                return $converted;
            }
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-16BE', 'UTF-8', $bytes);
            if (is_string($converted)) {
                return $converted;
            }
        }

        // 手工解码：按 2 字节一组取码元，处理 UTF-16 代理对
        $out = '';
        $length = strlen($bytes);
        for ($i = 0; $i + 1 < $length; $i += 2) {
            $code = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
            if ($code >= 0xD800 && $code <= 0xDBFF && $i + 3 < $length) {
                $low = (ord($bytes[$i + 2]) << 8) | ord($bytes[$i + 3]);
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
                    $i += 2;
                }
            }
            $out .= self::codePointToUtf8($code);
        }
        return $out;
    }

    /**
     * 将 Unicode 码点编码为 UTF-8 字节串。
     */
    private static function codePointToUtf8(int $code): string
    {
        if ($code < 0x80) {
            return chr($code);
        }
        if ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        }
        if ($code < 0x10000) {
            return chr(0xE0 | ($code >> 12))
                . chr(0x80 | (($code >> 6) & 0x3F))
                . chr(0x80 | ($code & 0x3F));
        }
        return chr(0xF0 | ($code >> 18))
            . chr(0x80 | (($code >> 12) & 0x3F))
            . chr(0x80 | (($code >> 6) & 0x3F))
            . chr(0x80 | ($code & 0x3F));
    }

    /**
     * 将 stream_socket_client 的错误映射为带错误码的 PingException。
     * 错误特征与 MinecraftPing::mapConnectError 一致。
     */
    private function mapConnectError(int $errno, string $errstr, string $host, int $port): PingException
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
