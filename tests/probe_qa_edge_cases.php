<?php

declare(strict_types=1);

/**
 * QA 独立补充边界用例（probe）。
 *
 * 这些用例是测试套件中未覆盖的边界场景，由 QA 独立编写：
 *   - VarInt 极大值 / 0 / 负值 / 超 5 字节 / 空数据 / 多字节字符串
 *   - MOTD 空串 / 截断 § / RGB 8 位 / 超长文本 / score / 未知 translate
 *   - Legacy 截断响应 / 非数字人数 / 空串
 *   - BrandDetector 大小写 / 版本号边界 / Leaves vs Leaf
 *   - PingResponse 未知错误码
 *   - PingClient 输入校验边界（IPv6 规范化、端口边界）
 *   - MinecraftPing 状态响应包从包体解析字符串长度（回归工程师修复的 bug，走真实 TCP）
 *
 * 用法：php tests/probe_qa_edge_cases.php
 * 全部通过输出 OK，任一失败输出 FAIL 并以退出码 1 结束。
 */

require __DIR__ . '/../src/autoload.php';

use McPing\BrandDetector;
use McPing\LegacyPing;
use McPing\MinecraftPing;
use McPing\MotdParser;
use McPing\PingClient;
use McPing\PingException;
use McPing\PingResponse;
use McPing\VarInt;

$passed = 0;
$failed = 0;

function check(bool $cond, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "OK   {$label}\n";
    } else {
        $failed++;
        echo "FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function throws(string $expected, callable $fn, string $label): void
{
    try {
        $fn();
        check(false, $label, '未抛出异常');
    } catch (\Throwable $e) {
        if ($expected === 'any') {
            check(true, $label, '异常: ' . get_class($e) . ': ' . $e->getMessage());
        } else {
            check($e instanceof $expected, $label, '异常类型 ' . get_class($e) . '，期望 ' . $expected);
        }
    }
}

// ============ VarInt 边界 ============

// 32 位有符号边界：encode(2147483648) 应编码为 5 字节，decode 后按有符号解释为 -2147483648
$v = VarInt::encode(2147483648);
check($v === "\x80\x80\x80\x80\x08", 'VarInt encode(2147483648) 为 5 字节', bin2hex($v));
$offset = 0;
$decoded = VarInt::decode($v, $offset);
check($decoded === -2147483648, 'VarInt decode(2147483648 编码) 按有符号解释为 -2147483648', (string)$decoded);

// encode(-2147483648) 与 encode(2147483648) 字节一致
check(VarInt::encode(-2147483648) === "\x80\x80\x80\x80\x08", 'VarInt encode(-2147483648) 字节一致');

// 4 字节最大值 268435455 (0x0FFFFFFF)
check(bin2hex(VarInt::encode(268435455)) === 'ffffff7f', 'VarInt encode(268435455) 为 4 字节', bin2hex(VarInt::encode(268435455)));

// 5 字节最小值 268435456 (0x10000000)
check(VarInt::encode(268435456) === "\x80\x80\x80\x80\x01", 'VarInt encode(268435456) 为 5 字节', bin2hex(VarInt::encode(268435456)));

// 3/4 字节边界
check(VarInt::encode(2097151) === "\xff\xff\x7f", 'VarInt encode(2097151) 3 字节', bin2hex(VarInt::encode(2097151)));
check(VarInt::encode(2097152) === "\x80\x80\x80\x01", 'VarInt encode(2097152) 4 字节', bin2hex(VarInt::encode(2097152)));

// 第 5 字节仍带续位标记 → 超上限
throws(\RuntimeException::class, function (): void {
    $o = 0;
    VarInt::decode("\xff\xff\xff\xff\xff", $o);
}, 'VarInt 第 5 字节带续位抛异常');

// 空数据
throws(\RuntimeException::class, function (): void {
    $o = 0;
    VarInt::decode('', $o);
}, 'VarInt decode 空数据抛异常');

// 偏移量超出数据长度
throws(\RuntimeException::class, function (): void {
    $o = 5;
    VarInt::decode("\x01", $o);
}, 'VarInt decode 偏移越界抛异常');

// 负字符串长度 → readString 抛异常
throws(\RuntimeException::class, function (): void {
    $o = 0;
    VarInt::readString("\xff\xff\xff\xff\x0f" . 'abc', $o);
}, 'readString 负长度抛异常');

// 多字节 UTF-8 字符串（长度按字节计）
$data = VarInt::writeString('你好世界 Minecraft 服务器');
$o = 0;
check(VarInt::readString($data, $o) === '你好世界 Minecraft 服务器', 'writeString/readString 多字节 UTF-8 往返', $o . '/' . strlen($data));

// readString 长度字段正确（中文 3 字节/字）
$o = 0;
$len = VarInt::decode($data, $o);
check($len === strlen('你好世界 Minecraft 服务器'), 'writeString 中文按字节计长度', (string)$len);

// 损坏 VarInt：0x80 0x80（未终止）
throws(\RuntimeException::class, function (): void {
    $o = 0;
    VarInt::decode("\x80\x80", $o);
}, 'VarInt 未终止字节抛异常');

// ============ MotdParser 边界 ============

// 空字符串
$r = MotdParser::parse('');
check($r['plain_text'] === '' && $r['has_legacy_codes'] === false, 'MOTD 空字符串');

// 纯 §（C2 A7）→ 空文本
check(MotdParser::stripLegacyCodes('§') === '', 'stripLegacyCodes 单个 §', bin2hex(MotdParser::stripLegacyCodes('§')));

// §x 只有 1 组（损坏输入）：期望保留剩余文本 —— 当前源码会吞掉后续文本并产生损坏 UTF-8（源码 bug，见报告）
$truncatedOut = MotdParser::stripLegacyCodes('§x§F剩下的');
check($truncatedOut === '剩下的', 'stripLegacyCodes §x 不完整保留文本（当前为源码缺陷，期望 FAIL）', bin2hex($truncatedOut) . ' = ' . $truncatedOut);

// RGB 8 位十六进制（8 组 §+hex）：前 6 组被 RGB 逻辑跳过，后 2 组按普通格式码剥离
check(MotdParser::stripLegacyCodes('§x§F§F§A§A§0§0§F§FGradient8') === 'Gradient8', 'stripLegacyCodes RGB 8 位', MotdParser::stripLegacyCodes('§x§F§F§A§A§0§0§F§FGradient8'));

// 单字节 0xA7 形式
$single = "\xA7" . 'a' . "\xA7" . 'lText';
check(MotdParser::stripLegacyCodes($single) === 'Text', 'stripLegacyCodes 单字节 §', MotdParser::stripLegacyCodes($single));
check(MotdParser::containsLegacyCode($single) === true, 'containsLegacyCode 单字节 §');

// 超长 MOTD（100KB）不崩溃且正确
$long = str_repeat('段落文本与§a颜色§r混合', 5000);
$r = MotdParser::parse($long);
check($r['plain_text'] === str_repeat('段落文本与颜色混合', 5000), 'MOTD 超长文本(100KB) 解析正确', 'len=' . strlen($r['plain_text']));
check($r['has_legacy_codes'] === true, 'MOTD 超长文本 has_legacy_codes');

// score 组件
$r = MotdParser::parse(['score' => ['name' => 'Steve', 'objective' => 'kills']]);
check($r['plain_text'] === 'Steve:kills', 'Chat Component score');

// 未知 translate 键 + 参数 → 空格拼接
$r = MotdParser::parse(['translate' => 'some.unknown.key', 'with' => ['A', 'B']]);
check($r['plain_text'] === 'A B', '未知 translate 键拼接 with', $r['plain_text']);

// 已知键但无参数 → 不崩溃
$r = MotdParser::parse(['translate' => 'chat.type.text']);
check(is_string($r['plain_text']), '已知 translate 键无参数不崩溃', $r['plain_text']);

// text 为数组（嵌套）
$r = MotdParser::parse(['text' => ['text' => 'Nested']]);
check($r['plain_text'] === 'Nested', 'text 字段为嵌套组件', $r['plain_text']);

// 数字/浮点/布尔 parse
check(MotdParser::parse(42)['plain_text'] === '42', 'parse 整数');
check(MotdParser::parse(3.5)['plain_text'] === '3.5', 'parse 浮点');
check(MotdParser::parse(false)['plain_text'] === 'false', 'parse 布尔 false');

// 组件内空 extra
$r = MotdParser::parse(['text' => 'A', 'extra' => []]);
check($r['plain_text'] === 'A', '组件空 extra');

// ============ Legacy 解析边界 ============

// 空串 → 1005
throws(PingException::class, function (): void {
    LegacyPing::parseLegacyPayload('');
}, 'Legacy 空串抛协议错误');

// 截断响应（仅 "§1"）→ 不抛异常，字段为 null
$r = LegacyPing::parseLegacyPayload('§1');
check($r['motd']['plain_text'] === '' && $r['players']['online'] === null, 'Legacy 仅 §1 不崩溃');

// 非数字人数 → null
$r = LegacyPing::parseLegacyPayload("§1\0" . "47\0" . "1.4.6\0" . "MOTD\0" . "abc\0" . "def");
check($r['players']['online'] === null && $r['players']['max'] === null, 'Legacy 非数字人数为 null');

// 1.4+ 格式带 RGB MOTD
$r = LegacyPing::parseLegacyPayload("§1\0" . "47\0" . "1.4.6\0" . "§x§F§F§A§A§0§0Gradient§r\0" . "1\0" . "10");
check($r['motd']['plain_text'] === 'Gradient', 'Legacy MOTD RGB 渐变', $r['motd']['plain_text']);

// 1.3 格式人数缺失
$r = LegacyPing::parseLegacyPayload('§1OnlyMotd');
check($r['motd']['plain_text'] === 'OnlyMotd' && $r['players']['online'] === null, 'Legacy 1.3 仅 MOTD');

// ============ BrandDetector 边界 ============

check(BrandDetector::detect('leaves 1.20.1', null) === 'Leaves', 'Brand Leaf 子串优先 Leaves');
check(BrandDetector::detect('paper 1.20.4', null) === 'Paper', 'Brand 大小写不敏感');
check(BrandDetector::detect('1.20.4-pre1', null) === 'Vanilla', 'Brand 预发布版为 Vanilla');
check(BrandDetector::detect('1.20.4-rc2', null) === 'Vanilla', 'Brand RC 版为 Vanilla');
check(BrandDetector::detect('24w14a', null) === 'Vanilla', 'Brand 快照版为 Vanilla');
check(BrandDetector::detect('1.21.1', null) === 'Vanilla', 'Brand 纯版本号为 Vanilla');
check(BrandDetector::detect('NotAVersion', null) === null, 'Brand 未知返回 null');
check(BrandDetector::detect('', null) === null, 'Brand 空版本返回 null');
check(BrandDetector::detect(null, 'WE ARE VELOCITY') === 'Velocity', 'Brand MOTD 大小写不敏感');

// ============ PingResponse 边界 ============

check(PingResponse::messageForCode(-1) === '未知错误', 'PingResponse 未知错误码');
$r = PingResponse::error(1001);
check($r['success'] === false && $r['code'] === 1001, 'PingResponse error 结构');

// ============ PingClient 输入校验 / IPv6 规范化（反射） ============

$client = new PingClient(['timeout_seconds' => 1]);

// 端口边界
foreach ([0, -1, 65536, 100000] as $badPort) {
    try {
        $client->ping('localhost', $badPort);
        check(false, "PingClient port={$badPort} 抛 1001");
    } catch (PingException $e) {
        check($e->getErrorCode() === 1001, "PingClient port={$badPort} 抛 1001");
    }
}
// 合法边界 1 与 65535 不抛参数错误（网络层错误可接受，但不能是 1001）
try {
    $client->ping('127.0.0.1', 1);
    check(false, 'PingClient port=1 不应成功');
} catch (PingException $e) {
    check($e->getErrorCode() !== 1001, 'PingClient port=1 合法（非 1001）', (string)$e->getErrorCode());
}
try {
    $client->ping('127.0.0.1', 65535);
    check(false, 'PingClient port=65535 不应成功');
} catch (PingException $e) {
    check($e->getErrorCode() !== 1001, 'PingClient port=65535 合法（非 1001）', (string)$e->getErrorCode());
}

// IPv6 规范化（私有方法反射）
$ref = new \ReflectionMethod(PingClient::class, 'normalizeHost');
$ref->setAccessible(true);
check($ref->invoke($client, '::1') === '[::1]', 'normalizeHost ::1 → [::1]');
check($ref->invoke($client, '2001:db8::1') === '[2001:db8::1]', 'normalizeHost 2001:db8::1 → 括号');
check($ref->invoke($client, '[::1]') === '[::1]', 'normalizeHost 已带括号不变');
check($ref->invoke($client, 'example.com') === 'example.com', 'normalizeHost 域名不变');
check($ref->invoke($client, '127.0.0.1') === '127.0.0.1', 'normalizeHost IPv4 不变');

// host 包含冒号但不带括号（如 域名:端口 误用）→ 会被规范化，属预期行为（不崩溃）
try {
    $client->ping('example.com:25565', 25565);
    check(false, 'host 带冒号不应成功');
} catch (PingException $e) {
    check(in_array($e->getErrorCode(), [1004, 1005, 1006], true), 'host 带冒号映射合理错误', (string)$e->getErrorCode());
}

// ============ MinecraftPing 现代协议走真实 TCP ============
// 说明：本环境沙箱限制 proc_open 子进程监听回环端口，
// 该场景由 Bash 编排的 tests/integration_mock_modern.php 独立执行
// （启动 tests/probe_mock_server.php 后台进程后再调用本类）。

echo "\n结果：{$passed} 通过，{$failed} 失败\n";
exit($failed > 0 ? 1 : 0);
