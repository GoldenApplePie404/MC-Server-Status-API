<?php

declare(strict_types=1);

namespace McPing;

/**
 * Webhook 状态变化通知器。
 *
 * 服务器状态（在线/离线）发生变化时，向配置的 Webhook 地址推送一条消息。
 * 支持常见平台：飞书（feishu）、钉钉（dingtalk，可带加签密钥）、企业微信（wecom）、通用（generic）。
 *
 * 防抖：同一台服务器两次状态变化通知之间至少间隔 cooldown_seconds，
 * 避免网络抖动导致短时间内反复"上线/离线"刷屏。
 * 状态快照持久化到 state_file（JSON），实现跨请求生效（Windows 内置服务器每请求独立进程）。
 */
final class WebhookNotifier
{
    /** @var array<string, mixed> 运行配置快照 */
    private static array $config = [];

    /** @var bool 是否已配置（幂等，仅首次生效） */
    private static bool $configured = false;

    /**
     * 配置（幂等，与项目其它单例类保持一致）。
     *
     * @param array<string, mixed> $config 全局配置
     */
    public static function configure(array $config): void
    {
        if (self::$configured) {
            return;
        }
        self::$configured = true;
        self::$config = $config;
    }

    /**
     * 状态变化时发送通知；未启用 / 无 URL / 状态未变化时静默返回。
     *
     * @param string               $host 服务器地址
     * @param int                  $port 端口
     * @param array<string, mixed> $data 查询结果（需含 online 布尔）
     */
    public static function notifyIfChanged(string $host, int $port, array $data): void
    {
        $cfg = self::$config;
        $wh = is_array($cfg['webhook'] ?? null) ? $cfg['webhook'] : [];
        $enabled = (bool)($wh['enabled'] ?? false);
        $url = trim((string)($wh['url'] ?? ''));
        if (!$enabled || $url === '' || $host === '') {
            return;
        }

        $notifyOnline = (bool)($wh['notify_online'] ?? true);
        $cooldown = max(0, (int)($wh['cooldown_seconds'] ?? 300));
        $stateFile = (string)($wh['state_file'] ?? '');

        $online = ($data['online'] ?? false) === true;
        $currentStatus = $online ? 'online' : 'offline';
        $key = strtolower($host) . ':' . $port;

        $state = self::loadState($stateFile);
        $now = time();
        $prev = is_array($state[$key] ?? null) ? $state[$key] : null;
        $prevStatus = $prev === null ? '' : (string)($prev['status'] ?? '');
        $prevNotifiedAt = $prev === null ? 0 : (int)($prev['notified_at'] ?? 0);

        // 状态未变化：直接返回
        if ($prevStatus === $currentStatus) {
            return;
        }

        // 状态变化：是否允许发送（离线必通知；上线按配置；冷却期内抑制）
        $canNotify = $online ? $notifyOnline : true;
        if ($canNotify && ($now - $prevNotifiedAt) < $cooldown) {
            $canNotify = false;
        }

        $newState = ['status' => $currentStatus, 'notified_at' => $prevNotifiedAt];
        if ($canNotify) {
            $payload = self::buildPayload($currentStatus, $host, $port, $data);
            if (self::postJson($url, $payload)) {
                $newState['notified_at'] = $now;
            } else {
                // 发送失败：记录状态变化避免无限重试，notified_at 保留旧值
            }
        }
        $state[$key] = $newState;
        self::saveState($stateFile, $state);
    }

    /**
     * 按平台构建通知 payload。
     *
     * @return array<string, mixed>
     */
    private static function buildPayload(string $status, string $host, int $port, array $data): array
    {
        $platform = strtolower((string)(self::$config['webhook']['platform'] ?? 'generic'));
        $title = 'Minecraft 服务器状态';
        $stateText = $status === 'online' ? '上线' : '离线';
        $addr = $host . ':' . $port;

        $extra = '';
        if ($status === 'online') {
            $players = $data['players'] ?? [];
            $onl = $players['online'] ?? null;
            $max = $players['max'] ?? null;
            if ($onl !== null && $max !== null) {
                $extra .= "，当前 {$onl}/{$max} 人";
            }
            $versionName = $data['version']['name'] ?? null;
            if (is_string($versionName) && $versionName !== '') {
                $extra .= "，版本 {$versionName}";
            }
            $latency = $data['latency_ms'] ?? null;
            if ($latency !== null) {
                $extra .= "，延迟 {$latency}ms";
            }
        }

        $text = "【{$title}】{$addr} {$stateText}{$extra}";

        switch ($platform) {
            case 'feishu':
                return ['msg_type' => 'text', 'content' => ['text' => $text]];
            case 'dingtalk':
                return ['msg_type' => 'text', 'text' => ['content' => $text]];
            case 'wecom':
                return ['msgtype' => 'text', 'text' => ['content' => $text]];
            default:
                return ['text' => $text, 'title' => $title, 'status' => $status, 'host' => $host, 'port' => $port];
        }
    }

    /**
     * POST JSON 到 webhook（curl 优先，降级 file_get_contents；支持钉钉加签）。
     */
    private static function postJson(string $url, array $payload): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        // 钉钉加签（可选）：timestamp + 密钥 HMAC-SHA256 签名
        $secret = (string)(self::$config['webhook']['secret'] ?? '');
        $finalUrl = $url;
        if ($secret !== '') {
            $ts = round(microtime(true) * 1000);
            $stringToSign = $ts . "\n" . $secret;
            $sign = urlencode(base64_encode(hash_hmac('sha256', $stringToSign, $secret, true)));
            $finalUrl = $url . (str_contains($url, '?') ? '&' : '?') . 'timestamp=' . $ts . '&sign=' . $sign;
        }

        $headers = ['Content-Type: application/json'];

        if (function_exists('curl_init')) {
            $ch = curl_init($finalUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno !== 0) {
                error_log('[mc-server-api] Webhook 发送失败 (curl errno ' . $errno . ')');
                return false;
            }
            return true;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $json,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        $resp = @file_get_contents($finalUrl, false, $ctx);
        if ($resp === false) {
            error_log('[mc-server-api] Webhook 发送失败（无 curl）');
            return false;
        }
        return true;
    }

    /**
     * 读取状态快照文件。
     *
     * @return array<string, array{status: string, notified_at: int}>
     */
    private static function loadState(string $file): array
    {
        if ($file === '' || !is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return [];
        }
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    /**
     * 原子写入状态快照文件（LOCK_EX 互斥）。
     */
    private static function saveState(string $file, array $state): void
    {
        if ($file === '') {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $fp = @fopen($file, 'c');
        if ($fp === false) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, $json);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    /** 重置配置（供测试使用）。 */
    public static function reset(): void
    {
        self::$configured = false;
        self::$config = [];
    }
}
