<?php

declare(strict_types=1);

use McPing\PingResponse;

/**
 * 统一响应构建器单元测试。
 */

$tests = [];

$tests['成功响应结构'] = function (): void {
    $result = PingResponse::ok(['a' => 1]);
    assertSame(true, $result['success']);
    assertSame(0, $result['code']);
    assertSame('成功', $result['message']);
    assertSame(['a' => 1], $result['data']);
};

$tests['失败响应结构'] = function (): void {
    $result = PingResponse::error(1001, '参数无效');
    assertSame(false, $result['success']);
    assertSame(1001, $result['code']);
    assertSame('参数无效', $result['message']);
};

$tests['失败响应默认文案'] = function (): void {
    $result = PingResponse::error(1002);
    assertSame('连接超时', $result['message']);
};

$tests['失败响应附带数据'] = function (): void {
    $result = PingResponse::error(1006, '', ['online' => false]);
    assertSame(['online' => false], $result['data']);
};

$tests['错误码文案映射'] = function (): void {
    assertSame('成功', PingResponse::messageForCode(0));
    assertSame('参数无效', PingResponse::messageForCode(1001));
    assertSame('连接超时', PingResponse::messageForCode(1002));
    assertSame('连接被拒绝', PingResponse::messageForCode(1003));
    assertSame('DNS 解析失败', PingResponse::messageForCode(1004));
    assertSame('协议错误或响应异常', PingResponse::messageForCode(1005));
    assertSame('服务器离线', PingResponse::messageForCode(1006));
    assertSame('未知错误', PingResponse::messageForCode(9999));
};

return $tests;
