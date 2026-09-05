<?php

declare(strict_types=1);

/**
 * MarkdownRenderer 链接 scheme 注入回归测试（P1 修复验证）。
 *
 * 覆盖：
 *   - 危险 scheme（javascript: / data: / vbscript: / file:，含大小写混合）→
 *     降级为纯文本，不生成 <a>，浏览器无法执行；
 *   - 合法 scheme（http / https / mailto / 页面内锚点 #）→ 正常 <a>；
 *   - 带括号 URL（https://a.com/b(c)）括号配对解析正确，不再被第一个 ) 截断；
 *   - 带 & 参数 URL 正确保留 &amp;；
 *   - scheme 前带空白/控制字符的绕过尝试被拦截；
 *   - 链接文本 XSS 转义、rel="noopener" target="_blank" 存在；
 *   - 空 URL 按纯文本输出。
 *
 * 用法：php tests/test_runner.php（本文件被自动扫描加载）
 *
 * @return array<string, callable>
 */
return [
    'javascript 链接降级为纯文本' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render('[x](javascript:alert(1))');
        assertFalse(str_contains($html, '<a'), 'javascript: 不应生成 <a>');
        assertTrue(str_contains($html, '[x](javascript:alert(1))'), 'javascript: 应原样输出纯文本');
    },

    'javascript 大小写混合拦截' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render('[x](JaVaScRiPt:alert(1))');
        assertFalse(str_contains($html, '<a'), 'JaVaScRiPt: 大小写混合不应生成 <a>');
        assertTrue(str_contains($html, '[x](JaVaScRiPt:alert(1))'), '大小写混合应原样输出纯文本');
    },

    'data scheme 链接降级为纯文本' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render('[x](data:text/html,<svg onload=alert(1)>)');
        assertFalse(str_contains($html, '<a'), 'data: 不应生成 <a>');
        assertTrue(str_contains($html, '&lt;svg'), 'data: 内 HTML 应被转义');
    },

    'vbscript 与 file scheme 拦截' => function (): void {
        $r = new McPing\MarkdownRenderer();
        assertFalse(str_contains($r->render('[x](vbscript:msgbox(1))'), '<a'), 'vbscript: 不应生成 <a>');
        assertFalse(str_contains($r->render('[x](file:///etc/passwd)'), '<a'), 'file: 不应生成 <a>');
    },

    'scheme 前带空白绕过拦截' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render("[x]( javascript:alert(1))");
        assertFalse(str_contains($html, '<a'), '前导空格的 javascript: 不应生成 <a>');
        $html2 = $r->render("[x](\njavascript:alert(1))");
        assertFalse(str_contains($html2, '<a'), '前导换行的 javascript: 不应生成 <a>');
    },

    'http 链接正常生成' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render('[x](http://example.com)');
        assertTrue(str_contains($html, '<a href="http://example.com"'), 'http: 应生成 <a>');
    },

    'https 链接正常生成' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render('[x](https://example.com/path)');
        assertTrue(str_contains($html, '<a href="https://example.com/path"'), 'https: 应生成 <a>');
    },

    'mailto 链接正常生成' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render('[x](mailto:test@example.com)');
        assertTrue(str_contains($html, '<a href="mailto:test@example.com"'), 'mailto: 应生成 <a>');
    },

    '页面内锚点链接正常生成' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render('[x](#配置说明)');
        assertTrue(str_contains($html, '<a href="#配置说明"'), '#锚点 应生成 <a>');
    },

    '带括号 URL 解析正确' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render('[x](https://a.com/b(c))');
        assertTrue(str_contains($html, 'href="https://a.com/b(c)"'), '括号应配对，URL 完整保留');
        // 旧实现取第一个 ) 会截断为 href="https://a.com/b(c"（引号前缺括号），此形态不应出现
        assertFalse(str_contains($html, 'href="https://a.com/b(c"'), '不应出现被截断的 href');
        assertFalse(str_contains($html, '>(c))'), '多余括号不应残留为文本');
    },

    '带 & 参数的 URL 保留实体' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render('[x](https://a.com/?a=1&b=2)');
        assertTrue(str_contains($html, 'href="https://a.com/?a=1&amp;b=2"'), '& 应转义为 &amp;');
    },

    '链接文本 XSS 转义' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render('[<b>x</b>](https://a.com)');
        assertTrue(str_contains($html, '&lt;b&gt;x&lt;/b&gt;'), '链接文本应转义');
        assertTrue(str_contains($html, '<a href="https://a.com"'), '仍应生成 <a>');
    },

    '链接含 rel 与 target' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render('[x](https://a.com)');
        assertTrue(str_contains($html, 'rel="noopener"'), '应含 rel="noopener"');
        assertTrue(str_contains($html, 'target="_blank"'), '应含 target="_blank"');
    },

    '空 URL 按纯文本输出' => function (): void {
        $r = new McPing\MarkdownRenderer();
        $html = $r->render('[x]()');
        assertFalse(str_contains($html, '<a'), '空 URL 不应生成 <a>');
        assertTrue(str_contains($html, '[x]()'), '空 URL 应原样输出');
    },
];
