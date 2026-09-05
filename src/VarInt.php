<?php

declare(strict_types=1);

namespace McPing;

/**
 * Minecraft 网络协议 VarInt 编解码工具。
 *
 * VarInt 是 Minecraft 协议中的变长整数：
 *   - 每个字节的低 7 位为有效数据；
 *   - 最高位（bit7）为"是否还有后续字节"的续位标记（1 表示继续，0 表示结束）；
 *   - 协议约定为 32 位有符号整数，最多 5 个字节。
 *
 * 注意：PHP 整数为 64 位有符号，编解码时需用 0x7F / 0xFFFFFFFF 掩码，
 * 防止负数算术右移产生大量 0xFF 以及移位溢出问题。
 */
final class VarInt
{
    /**
     * 将整数编码为 VarInt 字节串。
     *
     * @param int $value 32 位有符号整数（负数如 -1 会被当作无符号 32 位处理）
     * @return string 编码后的二进制字节串
     */
    public static function encode(int $value): string
    {
        // 先掩码为 32 位无符号，避免 -1 之类负数在算术右移时无限循环
        $value &= 0xFFFFFFFF;

        $out = '';
        do {
            $temp = $value & 0x7F;   // 取低 7 位
            $value >>= 7;            // 逻辑右移（掩码后为无符号）
            if ($value !== 0) {
                $temp |= 0x80;       // 还有后续字节，设置续位标记
            }
            $out .= chr($temp);
        } while ($value !== 0);

        return $out;
    }

    /**
     * 从字节串指定偏移处解码一个 VarInt，并推进偏移量。
     *
     * @param string $data  包含 VarInt 的字节串
     * @param int    $offset 起始偏移（引用传递，解码后自动前进）
     * @return int 解码后的 32 位有符号整数
     * @throws \RuntimeException 数据不完整或 VarInt 超过 5 字节上限
     */
    public static function decode(string $data, int &$offset = 0): int
    {
        $result = 0;
        $shift = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $byte = ord($data[$offset]);
            $offset++;

            // 低 7 位移到对应位置后并入结果
            $result |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                // 协议约定 VarInt 为 32 位有符号：最高位为 1 时转为负数
                if ($result >= 0x80000000) {
                    $result -= 0x100000000;
                }
                return $result;
            }

            $shift += 7;
            if ($shift >= 35) {
                throw new \RuntimeException('VarInt 超过 5 字节上限');
            }
        }

        throw new \RuntimeException('VarInt 数据不完整');
    }

    /**
     * 编码 Minecraft 字符串：varint 字节长度 + UTF-8 字节。
     *
     * @param string $value 原始字符串（UTF-8）
     * @return string 编码后的二进制字节串
     */
    public static function writeString(string $value): string
    {
        return self::encode(strlen($value)) . $value;
    }

    /**
     * 从字节串指定偏移处读取 Minecraft 字符串，并推进偏移量。
     *
     * @param string $data   包含字符串的字节串
     * @param int    $offset 起始偏移（引用传递，读取后自动前进）
     * @return string 解码后的 UTF-8 字符串
     * @throws \RuntimeException 字符串长度非法或数据不足
     */
    public static function readString(string $data, int &$offset): string
    {
        $stringLength = self::decode($data, $offset);
        if ($stringLength < 0) {
            throw new \RuntimeException('字符串长度不能为负');
        }
        if ($offset + $stringLength > strlen($data)) {
            throw new \RuntimeException('字符串数据不完整');
        }
        $value = substr($data, $offset, $stringLength);
        $offset += $stringLength;
        return $value;
    }
}
