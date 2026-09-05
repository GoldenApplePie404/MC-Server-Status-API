<?php

declare(strict_types=1);

namespace McPing;

/**
 * 服务器软件/核心识别器。
 *
 * 识别依据（按优先级）：
 *   1. version.name 前缀（如 "Paper 1.20.4" -> Paper、"git-Spigot-3194" -> Spigot）；
 *   2. 若 version.name 为纯版本号（如 "1.20.4"、"23w31a"）则判定为 Vanilla；
 *   3. 若以上均无结果，再从 MOTD 纯文本中尽力检索核心关键词（可能误报，仅供参考）。
 *
 * 已知核心：Paper / Spigot / Purpur / Folia / Pufferfish / Tuinity / Leaf / Leaves /
 * Gale / Fabric / Quilt / Forge / NeoForge / BungeeCord / Waterfall / Velocity /
 * Arclight / Mohist / CatServer / Magma / Vanilla
 */
final class BrandDetector
{
    /**
     * 核心关键词表：核心名 => 匹配关键词（大小写不敏感，子串匹配）。
     * 注意顺序：越具体的核心越靠前（如 NeoForge 在 Forge 之前）。
     */
    private const BRAND_KEYWORDS = [
        'NeoForge' => ['NeoForge'],
        'BungeeCord' => ['BungeeCord'],
        'Waterfall' => ['Waterfall'],
        'Velocity' => ['Velocity'],
        'Pufferfish' => ['Pufferfish'],
        'Tuinity' => ['Tuinity'],
        'Purpur' => ['Purpur'],
        'Folia' => ['Folia'],
        'Leaves' => ['Leaves'],
        'Leaf' => ['Leaf'],
        'Gale' => ['Gale'],
        'Arclight' => ['Arclight'],
        'CatServer' => ['CatServer'],
        'Mohist' => ['Mohist'],
        'Magma' => ['Magma'],
        'Paper' => ['Paper'],
        'Spigot' => ['Spigot'],
        'Fabric' => ['Fabric'],
        'Quilt' => ['Quilt'],
        'Forge' => ['Forge'],
        'Vanilla' => ['Vanilla'],
    ];

    /**
     * 识别服务器核心。
     *
     * @param string|null $versionName 版本名称（如 "Paper 1.20.4"）
     * @param string|null $motdPlain   MOTD 纯文本（已去除控制码）
     * @return string|null 核心名；无法识别时返回 null
     */
    public static function detect(?string $versionName, ?string $motdPlain): ?string
    {
        $fromName = self::matchBrand($versionName);
        if ($fromName !== null) {
            return $fromName;
        }

        // 纯版本号（正式版 / 预发布 / 快照）判定为 Vanilla
        if ($versionName !== null && self::looksLikeVanillaVersion(trim($versionName))) {
            return 'Vanilla';
        }

        return self::matchBrand($motdPlain);
    }

    /**
     * 在给定文本中检索核心关键词。
     */
    private static function matchBrand(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        // 核心关键词均为 ASCII，strtolower 对 UTF-8 字节串做 ASCII 小写转换即可（不依赖 mbstring）
        $haystack = strtolower($text);
        foreach (self::BRAND_KEYWORDS as $brand => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, strtolower($keyword))) {
                    return $brand;
                }
            }
        }
        return null;
    }

    /**
     * 判断版本名称是否为原版风格版本号：
     *   - 正式版：1.20.4 / 1.20.4-pre1 / 1.20.4-rc2
     *   - 快照版：23w31a / 24w14a
     */
    private static function looksLikeVanillaVersion(string $version): bool
    {
        if (preg_match('/^\d+\.\d+(\.\d+)?([- ].*)?$/', $version) === 1) {
            return true;
        }
        if (preg_match('/^\d{2}w\d{2}[a-z](-.*)?$/i', $version) === 1) {
            return true;
        }
        return false;
    }
}
