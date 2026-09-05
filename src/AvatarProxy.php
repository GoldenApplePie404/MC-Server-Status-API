<?php

declare(strict_types=1);

namespace McPing;

/**
 * 玩家头像代理（P1-2）。
 *
 * GET /avatar/{uuid}.png 时按配置的上游模板抓取 Mojang 风格正版玩家头像，
 * 并在本地缓存（避免每次请求都访问上游，降低延迟与上游压力）。
 *
 * 特性：
 *   - UUID 格式校验（32 位 hex 或标准 36 位带横杠格式，正则白名单）；
 *   - 上游抓取使用 file_get_contents + stream context（超时可控）；
 *   - 响应体校验 PNG 魔数后才允许写入本地缓存，防止缓存垃圾数据；
 *   - 本地缓存文件名 = sha256(uuid) . '.png'，天然防目录穿越；
 *   - 上游失败统一返回 null，由前端控制器转为 404 + JSON 错误。
 *
 * 限制说明：
 *   默认上游 minotar.net / crafatar 等仅覆盖 Mojang 正版（Minecraft: Java Edition）
 *   账号 UUID；使用外置登录服（authlib-injector / 第三方 Yggdrasil）的玩家 UUID
 *   属于第三方体系，官方头像站无法返回其皮肤。可通过配置 avatar_api_template
 *   指向支持外置登录 UUID 的皮肤站 API（模板中 {uuid} 会被替换为请求的 UUID）。
 */
final class AvatarProxy
{
    /** PNG 魔数（用于校验上游返回内容确为 PNG） */
    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    /**
     * 校验 UUID 格式（32 位 hex 或标准 36 位带横杠格式）。
     *
     * @param string $uuid 原始 UUID
     */
    public static function isValidUuid(string $uuid): bool
    {
        $uuid = trim($uuid);
        if (preg_match('/^[0-9a-fA-F]{32}$/', $uuid) === 1) {
            return true;
        }
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid) === 1) {
            return true;
        }
        return false;
    }

    /**
     * 抓取玩家头像 PNG 字节（含本地缓存）。
     *
     * 流程：校验 UUID -> 检查本地缓存（未过期直接读文件）-> 上游抓取 ->
     * 校验 PNG 魔数 -> 写入本地缓存。
     *
     * @param string               $uuid   已通过 isValidUuid 校验的 UUID
     * @param array<string, mixed> $config 全局配置（avatar_api_template / avatar_cache_dir / avatar_cache_ttl_hours / avatar_timeout_seconds）
     * @return string|null PNG 字节；任何失败返回 null（不缓存失败结果）
     */
    public static function fetch(string $uuid, array $config): ?string
    {
        $uuid = strtolower(trim($uuid));

        // 本地缓存目录
        $cacheDir = (string)($config['avatar_cache_dir'] ?? '');
        $cacheFile = '';
        if ($cacheDir !== '') {
            $cacheFile = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $uuid) . '.png';
        }

        // 命中本地缓存（TTL 内）直接返回
        $ttlHours = max(0, (int)($config['avatar_cache_ttl_hours'] ?? 24));
        if ($cacheFile !== '' && is_file($cacheFile)) {
            if ($ttlHours === 0 || (time() - (int)filemtime($cacheFile)) < $ttlHours * 3600) {
                $data = @file_get_contents($cacheFile);
                if ($data !== false && self::isPng($data)) {
                    return $data;
                }
            }
            // 过期或损坏：删除后重新抓取
            @unlink($cacheFile);
        }

        // 上游抓取
        $template = (string)($config['avatar_api_template'] ?? 'https://minotar.net/avatar/{uuid}.png');
        if (!str_contains($template, '{uuid}')) {
            return null;
        }
        $url = str_replace('{uuid}', $uuid, $template);
        $timeout = (float)($config['avatar_timeout_seconds'] ?? 5.0);
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true,
                'user_agent' => 'mc-server-api/1.0 (+avatar proxy)',
            ],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false || !self::isPng($data)) {
            return null;
        }

        // 写入本地缓存（失败不影响返回）
        if ($cacheFile !== '') {
            $dir = dirname($cacheFile);
            if ((is_dir($dir) || @mkdir($dir, 0755, true)) && @file_put_contents($cacheFile, $data) === false) {
                // 缓存写失败仅记录日志，不阻断头像返回
                error_log('[mc-server-api] 头像缓存写入失败：' . $cacheFile);
            }
        }

        return $data;
    }

    /**
     * 校验字节串是否为合法 PNG（魔数 + 最小长度）。
     *
     * @param string $data 待校验字节
     */
    public static function isPng(string $data): bool
    {
        return strlen($data) >= 8 && strncmp($data, self::PNG_MAGIC, strlen(self::PNG_MAGIC)) === 0;
    }
}
