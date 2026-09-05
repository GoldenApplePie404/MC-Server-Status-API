<?php

declare(strict_types=1);

use McPing\MotdParser;

/**
 * MOTD 彩色 HTML（P1-1）单元测试。
 *
 * 覆盖：16 色映射、§x RGB 渐变、格式码 b/i/u/s、§r 复位、§k 乱码、
 * Chat Component 递归（color 名称与 #hex、bold/italic/underlined/strikethrough、
 * extra 嵌套、translate+with）、XSS 转义、parse() 返回值追加 html 键。
 */

$tests = [];

$tests['parse 返回值包含 html 键'] = function (): void {
    $result = MotdParser::parse('plain');
    assertTrue(array_key_exists('html', $result), 'parse 应返回 html 键');
    assertSame('plain', $result['html']);
};

$tests['parse null 的 html 为 null'] = function (): void {
    assertSame(null, MotdParser::parse(null)['html']);
};

$tests['16 色映射'] = function (): void {
    $expected = [
        '0' => '#000000', '1' => '#0000AA', '2' => '#00AA00', '3' => '#00AAAA',
        '4' => '#AA0000', '5' => '#AA00AA', '6' => '#FFAA00', '7' => '#AAAAAA',
        '8' => '#555555', '9' => '#5555FF', 'a' => '#55FF55', 'b' => '#55FFFF',
        'c' => '#FF5555', 'd' => '#FF55FF', 'e' => '#FFFF55', 'f' => '#FFFFFF',
    ];
    foreach ($expected as $code => $hex) {
        $html = MotdParser::parse('§' . $code . 'X')['html'];
        assertSame('<span style="color:' . $hex . '">X</span>', $html, "颜色码 §{$code} 映射 {$hex}");
    }
};

$tests['§x RGB 渐变'] = function (): void {
    $html = MotdParser::parse('§x§F§F§A§A§0§0Gradient')['html'];
    assertSame('<span style="color:#FFAA00">Gradient</span>', $html);
};

$tests['§x 大写 X 兼容'] = function (): void {
    $html = MotdParser::parse('§X§0§0§0§0§F§FHi')['html'];
    assertSame('<span style="color:#0000FF">Hi</span>', $html);
};

$tests['格式码加粗'] = function (): void {
    $html = MotdParser::parse('§lBold')['html'];
    assertSame('<span style="font-weight:bold">Bold</span>', $html);
};

$tests['格式码斜体'] = function (): void {
    $html = MotdParser::parse('§oItalic')['html'];
    assertSame('<span style="font-style:italic">Italic</span>', $html);
};

$tests['格式码下划线'] = function (): void {
    $html = MotdParser::parse('§nUnder')['html'];
    assertSame('<span style="text-decoration:underline">Under</span>', $html);
};

$tests['格式码删除线'] = function (): void {
    $html = MotdParser::parse('§mStrike')['html'];
    assertSame('<span style="text-decoration:line-through">Strike</span>', $html);
};

$tests['组合样式'] = function (): void {
    $html = MotdParser::parse('§c§lBoldRed')['html'];
    assertSame('<span style="color:#FF5555;font-weight:bold">BoldRed</span>', $html);
};

$tests['§r 复位'] = function (): void {
    $html = MotdParser::parse('§cRed§rNormal')['html'];
    assertSame('<span style="color:#FF5555">Red</span>Normal', $html);
};

$tests['§k 乱码按普通文本渲染'] = function (): void {
    $html = MotdParser::parse('§kObfuscated')['html'];
    assertSame('Obfuscated', $html);
};

$tests['混合文本与样式切换'] = function (): void {
    $html = MotdParser::parse('A §aB §rC')['html'];
    assertSame('A <span style="color:#55FF55">B </span>C', $html);
};

$tests['XSS 转义尖括号'] = function (): void {
    $html = MotdParser::parse('<script>alert(1)</script>')['html'];
    assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
};

$tests['XSS 转义引号与与号'] = function (): void {
    $html = MotdParser::parse('a&b"c\'d')['html'];
    assertSame('a&amp;b&quot;c&#039;d', $html);
};

$tests['XSS 带样式仍转义'] = function (): void {
    $html = MotdParser::parse('§a<script>&"')['html'];
    assertSame('<span style="color:#55FF55">&lt;script&gt;&amp;&quot;</span>', $html);
};

$tests['组件 color 名称'] = function (): void {
    $result = MotdParser::parse(['text' => 'Hi', 'color' => 'red']);
    assertSame('<span style="color:#FF5555">Hi</span>', $result['html']);
};

$tests['组件 color #hex'] = function (): void {
    $result = MotdParser::parse(['text' => 'Hi', 'color' => '#12ab34']);
    assertSame('<span style="color:#12ab34">Hi</span>', $result['html']);
};

$tests['组件未知颜色忽略'] = function (): void {
    $result = MotdParser::parse(['text' => 'Hi', 'color' => 'not-a-color']);
    assertSame('Hi', $result['html']);
};

$tests['组件 bold italic'] = function (): void {
    $result = MotdParser::parse(['text' => 'Hi', 'bold' => true, 'italic' => true]);
    assertSame('<span style="font-weight:bold;font-style:italic">Hi</span>', $result['html']);
};

$tests['组件 underlined strikethrough'] = function (): void {
    $result = MotdParser::parse(['text' => 'Hi', 'underlined' => true, 'strikethrough' => true]);
    assertSame('<span style="text-decoration:underline;text-decoration:line-through">Hi</span>', $result['html']);
};

$tests['嵌套 extra 继承样式'] = function (): void {
    $result = MotdParser::parse([
        'text' => 'A',
        'color' => 'gold',
        'extra' => [
            ['text' => 'B'],
            ['text' => 'C', 'color' => 'dark_red'],
        ],
    ]);
    $expected = '<span style="color:#FFAA00">A</span>'
        . '<span style="color:#FFAA00">B</span>'
        . '<span style="color:#AA0000">C</span>';
    assertSame($expected, $result['html']);
};

$tests['深层嵌套 extra'] = function (): void {
    $result = MotdParser::parse([
        'text' => 'A',
        'extra' => [['text' => 'B', 'extra' => [['text' => 'C', 'bold' => true]]]],
    ]);
    $expected = 'A<span style="font-weight:bold">C</span>';
    // 说明：B 无样式直接输出文本，C 继承样式后加粗
    $actual = $result['html'];
    assertTrue(str_contains($actual, 'A'), '应包含 A');
    assertTrue(str_contains($actual, '<span style="font-weight:bold">C</span>'), 'C 应加粗：' . $actual);
};

$tests['translate with 参数渲染'] = function (): void {
    $result = MotdParser::parse([
        'translate' => 'chat.type.text',
        'with' => [['text' => 'Steve', 'color' => 'green'], 'Hello'],
    ]);
    // with 参数按组件渲染（各自带样式），模板字面量转义
    assertSame('&lt;<span style="color:#55FF55">Steve</span>&gt; Hello', $result['html']);
};

$tests['translate 未知键拼接'] = function (): void {
    $result = MotdParser::parse(['translate' => 'x.y.z', 'with' => ['A', 'B']]);
    assertSame('A B', $result['html']);
};

$tests['组件内嵌传统代码转 HTML'] = function (): void {
    $result = MotdParser::parse(['text' => '§cRed', 'extra' => [['text' => '!']]]);
    assertSame('<span style="color:#FF5555">Red</span>!', $result['html']);
};

$tests['组件列表渲染'] = function (): void {
    $result = MotdParser::parse([['text' => 'L1'], ['text' => 'L2', 'bold' => true]]);
    assertSame('L1<span style="font-weight:bold">L2</span>', $result['html']);
};

$tests['数字与布尔 text'] = function (): void {
    assertSame('123', MotdParser::parse(['text' => 123])['html']);
    assertSame('true', MotdParser::parse(['text' => true])['html']);
};

$tests['html 与 plain_text 文本一致'] = function (): void {
    $raw = '§6§lWelcome §rto §bthe §fServer';
    $result = MotdParser::parse($raw);
    $stripped = preg_replace('/<[^>]*>/', '', $result['html']);
    assertSame($result['plain_text'], $stripped);
};

// ==================== 换行还原（P1 补充） ====================

$tests['legacy 字符串换行转 <br>'] = function (): void {
    $html = MotdParser::parse("Line1\nLine2")['html'];
    assertSame('Line1<br>Line2', $html);
};

$tests['legacy 字符串 CRLF/CR 归一为 <br>'] = function (): void {
    assertSame('A<br>B', MotdParser::parse("A\r\nB")['html']);
    assertSame('A<br>B', MotdParser::parse("A\rB")['html']);
};

$tests['legacy 字符串带样式换行'] = function (): void {
    // \n 处于同一段文本中，换行后继承当前样式
    $html = MotdParser::parse("§aRed\nBlue")['html'];
    assertSame('<span style="color:#55FF55">Red<br>Blue</span>', $html);
};

$tests['组件 text 含换行转 <br>'] = function (): void {
    $result = MotdParser::parse(['text' => "A\nB", 'color' => 'gold']);
    assertSame('<span style="color:#FFAA00">A<br>B</span>', $result['html']);
};

$tests['独立 \n 组件（如 eqmemory 结构）转 <br>'] = function (): void {
    // 复现 mc.eqmemory.cn：extra 中夹一个 text="\n" 的独立组件
    $result = MotdParser::parse([
        'text' => '',
        'extra' => [
            ['text' => 'Line1'],
            ['text' => "\n"],
            ['text' => 'Line2', 'color' => 'green'],
        ],
    ]);
    assertSame('Line1<br><span style="color:#55FF55">Line2</span>', $result['html']);
    // 同时 plain_text 应保留换行，二者行数一致
    assertSame("Line1\nLine2", $result['plain_text']);
};

$tests['换行与 XSS 转义共存仍安全'] = function (): void {
    $html = MotdParser::parse("<script>\n</script>")['html'];
    assertSame('&lt;script&gt;<br>&lt;/script&gt;', $html);
};

$tests['无换行时输出不变'] = function (): void {
    // 回归：不含换行的既有用例不受影响
    assertSame('Plain', MotdParser::parse('Plain')['html']);
    assertSame('<span style="color:#FF5555">Red</span>Normal', MotdParser::parse('§cRed§rNormal')['html']);
};

return $tests;
