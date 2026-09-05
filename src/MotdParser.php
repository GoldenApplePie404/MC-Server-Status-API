<?php

declare(strict_types=1);

namespace McPing;

/**
 * MOTD（服务器描述）解析器。
 *
 * 支持两种形式：
 *   1. 传统字符串：可能包含 § 颜色/格式代码（§a、§l、§x§R§G§B...RGB 渐变等），
 *      输出 plain_text（去除所有控制码）与 has_legacy_codes 标记。
 *   2. 新版 Chat Component 对象：text / extra / with / translate 递归提取文本，
 *      extra 可嵌套；translate 取 with 参数；color 记录颜色（解析时忽略但保留 raw）。
 *
 * 统一输出结构：
 *   [
 *     'raw'              => 原始输入（string 或 array 或 null），
 *     'plain_text'       => 纯文本（去除所有控制码/组件包装），
 *     'has_legacy_codes' => 是否包含 § 传统控制码,
 *   ]
 */
final class MotdParser
{
    /**
     * 传统 § 颜色代码 16 色映射表（小写 hex 键 -> CSS 颜色值）。
     */
    private const COLOR_MAP = [
        '0' => '#000000', // 黑
        '1' => '#0000AA', // 深蓝
        '2' => '#00AA00', // 深绿
        '3' => '#00AAAA', // 深青
        '4' => '#AA0000', // 深红
        '5' => '#AA00AA', // 深紫
        '6' => '#FFAA00', // 金
        '7' => '#AAAAAA', // 灰
        '8' => '#555555', // 深灰
        '9' => '#5555FF', // 蓝
        'a' => '#55FF55', // 绿
        'b' => '#55FFFF', // 青
        'c' => '#FF5555', // 红
        'd' => '#FF55FF', // 粉
        'e' => '#FFFF55', // 黄
        'f' => '#FFFFFF', // 白
    ];

    /**
     * Chat Component 颜色名 -> CSS 颜色值映射（含常见别名）。
     */
    private const COLOR_NAME_MAP = [
        'black' => '#000000',
        'dark_blue' => '#0000AA',
        'dark_green' => '#00AA00',
        'dark_aqua' => '#00AAAA',
        'dark_red' => '#AA0000',
        'dark_purple' => '#AA00AA',
        'gold' => '#FFAA00',
        'gray' => '#AAAAAA',
        'grey' => '#AAAAAA',
        'dark_gray' => '#555555',
        'dark_grey' => '#555555',
        'blue' => '#5555FF',
        'green' => '#55FF55',
        'aqua' => '#55FFFF',
        'red' => '#FF5555',
        'light_purple' => '#FF55FF',
        'yellow' => '#FFFF55',
        'white' => '#FFFFFF',
    ];

    /**
     * 解析 MOTD（支持字符串、Chat Component 数组、标量、null）。
     *
     * 输出结构（纯追加，向后兼容）：
     *   [
     *     'raw'              => 原始输入（string 或 array 或 null），
     *     'plain_text'       => 纯文本（去除所有控制码/组件包装），
     *     'has_legacy_codes' => 是否包含 § 传统控制码,
     *     'html'             => 彩色 HTML（P1-1，全部文本已做 XSS 转义），
     *   ]
     *
     * @param mixed $raw 原始 MOTD
     * @return array{raw: mixed, plain_text: ?string, has_legacy_codes: bool, html: ?string}
     */
    public static function parse(mixed $raw): array
    {
        $defaultStyle = self::defaultStyle();

        if ($raw === null) {
            return ['raw' => null, 'plain_text' => null, 'has_legacy_codes' => false, 'html' => null];
        }

        if (is_string($raw)) {
            $hasLegacy = self::containsLegacyCode($raw);
            return [
                'raw' => $raw,
                'plain_text' => self::stripLegacyCodes($raw),
                'has_legacy_codes' => $hasLegacy,
                'html' => self::legacyToHtml($raw, $defaultStyle),
            ];
        }

        if (is_array($raw)) {
            $hasLegacy = false;
            $plainText = self::componentToPlainText($raw, $hasLegacy);
            return [
                'raw' => $raw,
                'plain_text' => $plainText,
                'has_legacy_codes' => $hasLegacy,
                'html' => self::componentToHtml($raw, $defaultStyle),
            ];
        }

        if (is_bool($raw)) {
            return [
                'raw' => $raw,
                'plain_text' => $raw ? 'true' : 'false',
                'has_legacy_codes' => false,
                'html' => $raw ? 'true' : 'false',
            ];
        }

        if (is_int($raw) || is_float($raw)) {
            $text = (string)$raw;
            return ['raw' => $raw, 'plain_text' => $text, 'has_legacy_codes' => false, 'html' => $text];
        }

        return ['raw' => null, 'plain_text' => null, 'has_legacy_codes' => false, 'html' => null];
    }

    /**
     * 递归提取 Chat Component 的纯文本。
     *
     * @param mixed $node      组件节点（string / array / 标量 / null）
     * @param bool  $hasLegacy 引用传递，发现 § 控制码时置为 true
     * @return string 拼接后的纯文本
     */
    public static function componentToPlainText(mixed $node, bool &$hasLegacy = false): string
    {
        if (is_string($node)) {
            if (self::containsLegacyCode($node)) {
                $hasLegacy = true;
            }
            return self::stripLegacyCodes($node);
        }

        if (is_bool($node)) {
            return $node ? 'true' : 'false';
        }

        if (is_int($node) || is_float($node)) {
            return (string)$node;
        }

        if ($node === null) {
            return '';
        }

        if (!is_array($node)) {
            return '';
        }

        // 列表形式：如 ["第一行", {"text":"第二行"}]，逐个拼接
        if (array_is_list($node)) {
            $parts = [];
            foreach ($node as $item) {
                $parts[] = self::componentToPlainText($item, $hasLegacy);
            }
            return implode('', $parts);
        }

        $parts = [];

        // text 字段：可能是字符串、数字、布尔，均容错转字符串
        if (array_key_exists('text', $node)) {
            $parts[] = self::componentToPlainText($node['text'], $hasLegacy);
        }

        // translate 字段：取 with 参数进行最佳努力翻译/拼接
        if (array_key_exists('translate', $node)) {
            $key = (string)$node['translate'];
            $args = [];
            if (isset($node['with']) && is_array($node['with'])) {
                foreach ($node['with'] as $arg) {
                    $args[] = self::componentToPlainText($arg, $hasLegacy);
                }
            }
            $parts[] = self::translate($key, $args);
        }

        // extra 字段：可嵌套的子组件数组
        if (array_key_exists('extra', $node) && is_array($node['extra'])) {
            foreach ($node['extra'] as $child) {
                $parts[] = self::componentToPlainText($child, $hasLegacy);
            }
        }

        // keybind 字段：按键绑定名
        if (array_key_exists('keybind', $node)) {
            $parts[] = (string)$node['keybind'];
        }

        // score 字段：计分板目标，最佳努力输出 name:objective
        if (array_key_exists('score', $node) && is_array($node['score'])) {
            $score = $node['score'];
            $name = isset($score['name']) ? (string)$score['name'] : '';
            $objective = isset($score['objective']) ? (string)$score['objective'] : '';
            if ($name !== '' && $objective !== '') {
                $parts[] = $name . ':' . $objective;
            }
        }

        return implode('', $parts);
    }

    /**
     * 去除字符串中的所有 § 传统控制码（含 RGB 渐变 §x§R§G§B§A§C 形式）。
     *
     * § 在 UTF-8 下为两个字节 0xC2 0xA7；同时兼容单字节 0xA7 的输入。
     * 控制码规则：
     *   - 普通格式码：§ + 1 个字符（如 §a、§l、§r）；
     *   - RGB 渐变码：§x 后跟 6 组 § + 十六进制数字（§x§F§F§A§A§0§0）。
     *
     * @param string $text 可能包含控制码的文本
     * @return string 去除控制码后的纯文本
     */
    public static function stripLegacyCodes(string $text): string
    {
        $out = '';
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            $byte = ord($text[$i]);

            // 标准 UTF-8 形式的 §（0xC2 0xA7）
            if ($byte === 0xC2 && $i + 1 < $length && ord($text[$i + 1]) === 0xA7) {
                $i += 2;
                if ($i >= $length) {
                    break;
                }
                $code = $text[$i];
                $i++;
                if ($code === 'x' || $code === 'X') {
                    // 跳过 6 组 § + 十六进制数字（RGB 渐变）。
                    // 仅当"当前字符是 § 且紧跟 1 位十六进制数字"时才计数并跳过；
                    // 一旦不满足立即停止跳过，把后续字节按普通文本正常输出，
                    // 防止截断的 §x 渐变吞掉后续文本或损坏 UTF-8 多字节序列。
                    $skipped = 0;
                    while ($skipped < 6 && $i < $length) {
                        if ($i + 1 >= $length || ord($text[$i]) !== 0xC2 || ord($text[$i + 1]) !== 0xA7) {
                            break;
                        }
                        if ($i + 2 >= $length || !self::isHexDigit($text[$i + 2])) {
                            break;
                        }
                        $i += 3; // 跳过 § + 十六进制数字（共 3 字节）
                        $skipped++;
                    }
                }
                continue;
            }

            // 单字节 §（非 UTF-8 输入兼容）
            if ($byte === 0xA7) {
                $i++;
                if ($i >= $length) {
                    break;
                }
                $code = $text[$i];
                $i++;
                if ($code === 'x' || $code === 'X') {
                    // 跳过 6 组 § + 十六进制数字（单字节 § 兼容分支）。
                    // 规则同 UTF-8 分支：仅当"§ + 十六进制数字"成组时计数跳过，
                    // 否则立即停止并把后续字节按普通文本输出。
                    $skipped = 0;
                    while ($skipped < 6 && $i < $length) {
                        if (ord($text[$i]) !== 0xA7) {
                            break;
                        }
                        if ($i + 1 >= $length || !self::isHexDigit($text[$i + 1])) {
                            break;
                        }
                        $i += 2; // 跳过 § + 十六进制数字（共 2 字节）
                        $skipped++;
                    }
                }
                continue;
            }

            $out .= $text[$i];
            $i++;
        }

        return $out;
    }

    /**
     * 判断字符串中是否包含 § 传统控制码。
     *
     * @param string $text 待检测文本
     * @return bool 是否包含控制码
     */
    public static function containsLegacyCode(string $text): bool
    {
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($text[$i]);
            if ($byte === 0xC2 && $i + 1 < $length && ord($text[$i + 1]) === 0xA7) {
                return true;
            }
            if ($byte === 0xA7) {
                // 单字节 §：仅当前一字节不是多字节字符的续字节时判定为控制码，
                // 避免把多字节字符中恰好出现的 0xA7 误判
                if ($i === 0 || (ord($text[$i - 1]) & 0xC0) !== 0x80) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 判断单个字符是否为十六进制数字（0-9 / A-F / a-f）。
     *
     * 用于 RGB 渐变格式（§x§R§G§B§A§C）中校验每组是否为 § + 十六进制数字。
     *
     * @param string $char 单字节字符
     * @return bool 是否为十六进制数字
     */
    private static function isHexDigit(string $char): bool
    {
        $byte = ord($char);
        return ($byte >= 0x30 && $byte <= 0x39)   // 0-9
            || ($byte >= 0x41 && $byte <= 0x46)   // A-F
            || ($byte >= 0x61 && $byte <= 0x66);  // a-f
    }

    /**
     * 最佳努力翻译 translate 组件。
     *
     * 项目不含 Minecraft 语言文件，这里内置少量常见翻译键；
     * 未命中的键直接拼接 with 参数（空格分隔），保证文本不丢失。
     *
     * @param string   $key  翻译键（如 chat.type.text）
     * @param string[] $args 已提取的 with 参数（纯文本）
     * @return string 翻译/拼接后的文本
     */
    private static function translate(string $key, array $args): string
    {
        $common = [
            'chat.type.text' => '<%s> %s',
            'chat.type.announcement' => '[%s] %s',
            'multiplayer.player.joined' => '%s joined the game',
            'multiplayer.player.left' => '%s left the game',
            'death.attack.generic' => '%s died',
            'commands.message.display.incoming' => '%s whispers to you: %s',
            'commands.message.display.outgoing' => 'You whisper to %s: %s',
        ];

        if (isset($common[$key])) {
            $format = $common[$key];
            $position = 0;
            $result = preg_replace_callback(
                '/%(?:(\d+)\$)?s/',
                static function (array $match) use ($args, &$position): string {
                    $index = isset($match[1]) ? ((int)$match[1]) - 1 : $position++;
                    return $args[$index] ?? '';
                },
                $format
            );
            return $result === null ? $key : $result;
        }

        if ($args === []) {
            return $key;
        }
        return implode(' ', $args);
    }

    // ==================== P1-1：彩色 HTML 渲染 ====================

    /**
     * 默认样式（无颜色、无任何格式）。
     *
     * @return array{color: ?string, bold: bool, italic: bool, underline: bool, strikethrough: bool, obfuscated: bool}
     */
    private static function defaultStyle(): array
    {
        return [
            'color' => null,
            'bold' => false,
            'italic' => false,
            'underline' => false,
            'strikethrough' => false,
            'obfuscated' => false,
        ];
    }

    /**
     * 递归渲染 Chat Component 为彩色 HTML。
     *
     * 支持：text / translate（含 with 参数展开）/ extra（可嵌套）/
     * keybind / score；样式字段 color（颜色名或 #hex）、bold、italic、
     * underlined、strikethrough、obfuscated；子组件继承父组件样式。
     * 所有文本经 htmlspecialchars(ENT_QUOTES, 'UTF-8') 转义，防 XSS。
     *
     * @param mixed $node        组件节点（string / array / 标量 / null）
     * @param array<string, mixed> $parentStyle 父级继承样式
     * @return string 拼接后的 HTML 片段
     */
    private static function componentToHtml(mixed $node, array $parentStyle): string
    {
        if (is_string($node)) {
            // 组件 text 内可能混有 § 传统控制码，按 legacy 渲染并继承父级样式
            return self::legacyToHtml($node, $parentStyle);
        }

        if (is_bool($node)) {
            return self::renderStyledText($node ? 'true' : 'false', $parentStyle);
        }

        if (is_int($node) || is_float($node)) {
            return self::renderStyledText((string)$node, $parentStyle);
        }

        if ($node === null) {
            return '';
        }

        if (!is_array($node)) {
            return '';
        }

        // 列表形式：如 ["第一行", {"text":"第二行"}]，逐个渲染
        if (array_is_list($node)) {
            $parts = [];
            foreach ($node as $item) {
                $parts[] = self::componentToHtml($item, $parentStyle);
            }
            return implode('', $parts);
        }

        // 合并当前组件的样式（子组件继承父级）
        $style = $parentStyle;
        if (isset($node['color'])) {
            $normalized = self::normalizeColor($node['color']);
            if ($normalized !== null) {
                $style['color'] = $normalized;
            }
        }
        if (isset($node['bold'])) {
            $style['bold'] = self::truthy($node['bold']);
        }
        if (isset($node['italic'])) {
            $style['italic'] = self::truthy($node['italic']);
        }
        if (isset($node['underlined'])) {
            $style['underline'] = self::truthy($node['underlined']);
        }
        if (isset($node['strikethrough'])) {
            $style['strikethrough'] = self::truthy($node['strikethrough']);
        }
        if (isset($node['obfuscated'])) {
            $style['obfuscated'] = self::truthy($node['obfuscated']);
        }

        $parts = [];

        // text 字段：可能是字符串、数字、布尔，均容错渲染
        if (array_key_exists('text', $node)) {
            $parts[] = self::componentToHtml($node['text'], $style);
        }

        // translate 字段：取 with 参数渲染（参数继承当前样式）
        if (array_key_exists('translate', $node)) {
            $key = (string)$node['translate'];
            $args = [];
            if (isset($node['with']) && is_array($node['with'])) {
                foreach ($node['with'] as $arg) {
                    $args[] = self::componentToHtml($arg, $style);
                }
            }
            $parts[] = self::translateHtml($key, $args);
        }

        // extra 字段：可嵌套的子组件数组
        if (array_key_exists('extra', $node) && is_array($node['extra'])) {
            foreach ($node['extra'] as $child) {
                $parts[] = self::componentToHtml($child, $style);
            }
        }

        // keybind 字段：按键绑定名
        if (array_key_exists('keybind', $node)) {
            $parts[] = self::renderStyledText((string)$node['keybind'], $style);
        }

        // score 字段：计分板目标，最佳努力输出 name:objective
        if (array_key_exists('score', $node) && is_array($node['score'])) {
            $score = $node['score'];
            $name = isset($score['name']) ? (string)$score['name'] : '';
            $objective = isset($score['objective']) ? (string)$score['objective'] : '';
            if ($name !== '' && $objective !== '') {
                $parts[] = self::renderStyledText($name . ':' . $objective, $style);
            }
        }

        return implode('', $parts);
    }

    /**
     * 将含 § 传统控制码的字符串渲染为彩色 HTML。
     *
     * 逐字节扫描（兼容 UTF-8 两字节 § 与单字节 0xA7），维护当前样式状态；
     * 遇到控制码更新样式，普通文本按当前样式转义输出。
     * RGB 渐变（§x§R§R§G§G§B§B）解析为 #RRGGBB；§r 复位；§k 乱码忽略。
     *
     * @param string               $text 含控制码的文本
     * @param array<string, mixed> $baseStyle 初始样式（继承自父级）
     * @return string HTML 片段
     */
    private static function legacyToHtml(string $text, array $baseStyle): string
    {
        $style = $baseStyle;
        $length = strlen($text);
        $out = '';
        $buffer = '';
        $i = 0;

        $flush = static function () use (&$out, &$buffer, &$style): void {
            if ($buffer === '') {
                return;
            }
            $out .= self::renderStyledText($buffer, $style);
            $buffer = '';
        };

        while ($i < $length) {
            $byte = ord($text[$i]);

            $isUtf8Section = $byte === 0xC2 && $i + 1 < $length && ord($text[$i + 1]) === 0xA7;
            $isSingleSection = $byte === 0xA7;

            if ($isUtf8Section || $isSingleSection) {
                $flush();
                // 消费 §（两字节或单字节）
                $i += $isUtf8Section ? 2 : 1;
                if ($i >= $length) {
                    break;
                }
                $code = $text[$i];
                $i++;
                $lower = strtolower($code);

                if ($lower === 'x') {
                    // RGB 渐变：§x 后跟 6 组 §+十六进制数字
                    $hex = '';
                    $skipped = 0;
                    while ($skipped < 6 && $i < $length) {
                        $b1 = ord($text[$i]);
                        $pairUtf8 = $b1 === 0xC2 && $i + 1 < $length && ord($text[$i + 1]) === 0xA7;
                        $pairSingle = $b1 === 0xA7;
                        if (!$pairUtf8 && !$pairSingle) {
                            break;
                        }
                        $digitPos = $pairUtf8 ? $i + 2 : $i + 1;
                        if ($digitPos >= $length) {
                            break;
                        }
                        $digit = strtolower($text[$digitPos]);
                        if (!self::isHexDigit($digit)) {
                            break;
                        }
                        $hex .= $digit;
                        $i = $digitPos + 1;
                        $skipped++;
                    }
                    // 凑满 6 位（R R G G B B）才设置颜色，否则忽略该渐变
                    if (strlen($hex) >= 6) {
                        // 统一大写（与 16 色映射表 COLOR_MAP 保持一致）
                        $style['color'] = '#' . strtoupper($hex[0] . $hex[1] . $hex[2] . $hex[3] . $hex[4] . $hex[5]);
                    }
                    continue;
                }

                switch ($lower) {
                    case '0':
                    case '1':
                    case '2':
                    case '3':
                    case '4':
                    case '5':
                    case '6':
                    case '7':
                    case '8':
                    case '9':
                    case 'a':
                    case 'b':
                    case 'c':
                    case 'd':
                    case 'e':
                    case 'f':
                        $style['color'] = self::COLOR_MAP[$lower];
                        break;
                    case 'l':
                        $style['bold'] = true;
                        break;
                    case 'o':
                        $style['italic'] = true;
                        break;
                    case 'n':
                        $style['underline'] = true;
                        break;
                    case 'm':
                        $style['strikethrough'] = true;
                        break;
                    case 'k':
                        // 乱码：保留标记但按普通文本渲染（不额外输出）
                        $style['obfuscated'] = true;
                        break;
                    case 'r':
                        $style = self::defaultStyle();
                        break;
                    default:
                        // 未知控制码忽略
                        break;
                }
                continue;
            }

            $buffer .= $text[$i];
            $i++;
        }

        $flush();
        return $out;
    }

    /**
     * 将一段文本按当前样式转义并输出（样式为空时不包裹 span）。
     *
     * @param string               $text  待转义文本
     * @param array<string, mixed> $style 当前样式
     * @return string HTML 片段
     */
    private static function renderStyledText(string $text, array $style): string
    {
        $css = self::styleToCss($style);
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        // 还原 MOTD 多行样式：换行符在 HTML 中会被折叠为空白，
        // 故显式转为 <br>（覆盖 legacy § 字符串与 Chat Component 的 \n /
        // 独立 \n 组件等所有换行来源）。<br> 为受控注入，文本已先转义，无 XSS 风险。
        $escaped = preg_replace('/\r\n|\r|\n/', '<br>', $escaped);
        if ($css === '') {
            return $escaped;
        }
        return '<span style="' . $css . '">' . $escaped . '</span>';
    }

    /**
     * 将样式数组转为内联 CSS 字符串。
     *
     * @param array<string, mixed> $style 样式数组
     * @return string CSS 文本（空样式返回空串）
     */
    private static function styleToCss(array $style): string
    {
        $rules = [];
        if (!empty($style['color'])) {
            $rules[] = 'color:' . $style['color'];
        }
        if (!empty($style['bold'])) {
            $rules[] = 'font-weight:bold';
        }
        if (!empty($style['italic'])) {
            $rules[] = 'font-style:italic';
        }
        if (!empty($style['underline'])) {
            $rules[] = 'text-decoration:underline';
        }
        if (!empty($style['strikethrough'])) {
            $rules[] = 'text-decoration:line-through';
        }
        return implode(';', $rules);
    }

    /**
     * 规范化 Chat Component 的 color 字段为 CSS 颜色值。
     *
     * 支持：16 色名称（含 grey/dark_grey 别名）与 #RRGGBB 十六进制。
     * 未知名称返回 null（调用方忽略，不中断渲染）。
     *
     * @param mixed $color 原始 color 值
     * @return string|null CSS 颜色值；无法识别返回 null
     */
    private static function normalizeColor(mixed $color): ?string
    {
        if (!is_string($color) || $color === '') {
            return null;
        }
        $lower = strtolower($color);
        if (isset(self::COLOR_NAME_MAP[$lower])) {
            return self::COLOR_NAME_MAP[$lower];
        }
        if (preg_match('/^#[0-9a-f]{6}$/i', $color) === 1) {
            return strtolower($color);
        }
        return null;
    }

    /**
     * 宽松布尔转换（兼容 JSON 布尔与 "true"/"false" 字符串）。
     *
     * @param mixed $value 原始值
     */
    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            if ($lower === 'true' || $lower === '1') {
                return true;
            }
            if ($lower === 'false' || $lower === '0' || $lower === '') {
                return false;
            }
        }
        return (bool)$value;
    }

    /**
     * 渲染 translate 组件为 HTML（参数已为 HTML 片段）。
     *
     * 翻译模板中的 %s / %1$s 占位符替换为对应参数 HTML；
     * 模板自身的文本先做 htmlspecialchars 转义，保证输出安全。
     *
     * @param string   $key  翻译键
     * @param string[] $args 已渲染的 with 参数（HTML 片段）
     * @return string HTML 片段
     */
    private static function translateHtml(string $key, array $args): string
    {
        $common = [
            'chat.type.text' => '<%s> %s',
            'chat.type.announcement' => '[%s] %s',
            'multiplayer.player.joined' => '%s joined the game',
            'multiplayer.player.left' => '%s left the game',
            'death.attack.generic' => '%s died',
            'commands.message.display.incoming' => '%s whispers to you: %s',
            'commands.message.display.outgoing' => 'You whisper to %s: %s',
        ];

        if (isset($common[$key])) {
            $format = $common[$key];
            $escapedFormat = htmlspecialchars($format, ENT_QUOTES, 'UTF-8');
            $position = 0;
            $result = preg_replace_callback(
                '/%(?:(\d+)\$)?s/',
                static function (array $match) use ($args, &$position): string {
                    $index = isset($match[1]) ? ((int)$match[1]) - 1 : $position++;
                    return $args[$index] ?? '';
                },
                $escapedFormat
            );
            return $result === null ? htmlspecialchars($key, ENT_QUOTES, 'UTF-8') : $result;
        }

        if ($args === []) {
            return htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        }
        return implode(' ', $args);
    }
}
