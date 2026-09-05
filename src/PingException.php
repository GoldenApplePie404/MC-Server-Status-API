<?php

declare(strict_types=1);

namespace McPing;

/**
 * 统一业务异常：携带 API 错误码（与 PingResponse 错误码保持一致）。
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
class PingException extends \RuntimeException
{
    public const ERR_INVALID_PARAMS = 1001;
    public const ERR_TIMEOUT = 1002;
    public const ERR_REFUSED = 1003;
    public const ERR_DNS = 1004;
    public const ERR_PROTOCOL = 1005;
    public const ERR_OFFLINE = 1006;
    public const ERR_RATE_LIMIT = 1007;
    public const ERR_API_KEY = 1008;
    public const ERR_BATCH_PARAMS = 1009;

    /** @var int 业务错误码 */
    private int $errorCode;

    public function __construct(int $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
        $this->errorCode = $errorCode;
    }

    /**
     * 获取业务错误码。
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }
}
