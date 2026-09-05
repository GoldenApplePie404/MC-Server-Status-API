<?php

declare(strict_types=1);

namespace McPing;

/**
 * 统一 JSON 响应构建器。
 *
 * 所有 API 响应均为统一结构：
 *   {
 *     "success": bool,
 *     "code": int,
 *     "message": string,
 *     "data": { ... }
 *   }
 *
 * 错误码约定：
 *   0    = 成功
 *   1001 = 参数无效
 *   1002 = 连接超时
 *   1003 = 连接被拒绝
 *   1004 = DNS 解析失败
 *   1005 = 协议错误 / 响应异常
 *   1006 = 服务器离线
 *   1007 = 请求过于频繁（限流）
 *   1008 = API Key 无效 / 缺失（鉴权）
 *   1009 = 批量参数无效
 */
final class PingResponse
{
    /** @var array<int, string> 错误码 -> 中文提示 */
    private const MESSAGES = [
        0 => '成功',
        1001 => '参数无效',
        1002 => '连接超时',
        1003 => '连接被拒绝',
        1004 => 'DNS 解析失败',
        1005 => '协议错误或响应异常',
        1006 => '服务器离线',
        1007 => '请求过于频繁，请稍后再试',
        1008 => 'API Key 无效或缺失',
        1009 => '批量参数无效',
    ];

    /**
     * 构建成功响应。
     *
     * @param array<string, mixed> $data 业务数据
     * @return array<string, mixed> 统一响应结构
     */
    public static function ok(array $data): array
    {
        return [
            'success' => true,
            'code' => 0,
            'message' => self::MESSAGES[0],
            'data' => $data,
        ];
    }

    /**
     * 构建失败响应。
     *
     * @param int                  $code    错误码
     * @param string               $message 提示信息（留空时按错误码取默认文案）
     * @param array<string, mixed> $data    附带数据（如 offline 状态的 host/port）
     * @return array<string, mixed> 统一响应结构
     */
    public static function error(int $code, string $message = '', array $data = []): array
    {
        if ($message === '') {
            $message = self::messageForCode($code);
        }
        return [
            'success' => false,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * 根据错误码获取默认中文提示。
     */
    public static function messageForCode(int $code): string
    {
        return self::MESSAGES[$code] ?? '未知错误';
    }
}
