<?php

declare(strict_types=1);

namespace McPing;

/**
 * 轻量级 Markdown -> HTML 渲染器（纯 PHP 标准库，无任何依赖）。
 *
 * 支持语法（与 README.md 实际使用的子集对齐，容错优先）：
 *   - 标题：# / ## / ###（自动生成锚点 id，供目录跳转）
 *   - 无序列表：- / * / + 开头
 *   - 有序列表：数字. 开头
 *   - 代码块：``` 围栏（可选语言标注）
 *   - 行内代码：`code`
 *   - 加粗：**text**（可嵌套行内元素）
 *   - 链接：[text](url)
 *   - 表格：GitHub 风格（| 分隔 + 分隔行）
 *   - 引用块：> 开头
 *   - 分隔线：--- / *** / ___
 *   - 普通段落（多行合并为一段）
 *
 * 安全约定：
 *   - 所有文本一律经 htmlspecialchars 转义后再输出，杜绝 XSS；
 *   - 不支持的语法按原样文本输出（先转义再保留），绝不抛出异常；
 *   - 链接 href 同样先转义，且统一加 rel="noopener" target="_blank"。
 *
 * 使用示例：
 *   $renderer = new MarkdownRenderer();
 *   $html = $renderer->render($markdown);      // 正文 HTML
 *   $toc  = $renderer->renderToc($markdown);   // 目录 HTML（##/### 标题）
 */
final class MarkdownRenderer
{
    /**
     * 渲染整篇 Markdown 为 HTML。
     *
     * @param string $markdown 原始 Markdown 文本
     * @return string HTML 片段（不含 <html>/<body> 包裹）
     */
    public function render(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);
        $count = count($lines);
        $html = '';
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];
            $trimmed = trim($line);

            // 空行：跳过
            if ($trimmed === '') {
                $i++;
                continue;
            }

            // 围栏代码块：```lang ... ```
            if (preg_match('/^```([A-Za-z0-9_+.\-]*)\s*$/', $trimmed, $match) === 1) {
                $lang = $match[1];
                $codeLines = [];
                $i++;
                while ($i < $count && preg_match('/^```\s*$/', trim($lines[$i])) !== 1) {
                    $codeLines[] = $lines[$i];
                    $i++;
                }
                $i++; // 跳过闭合围栏（若已到末尾则 i 越界，循环自然结束）
                $html .= $this->renderCodeBlock(implode("\n", $codeLines), $lang);
                continue;
            }

            // 表格：当前行含 | 且下一行是分隔行
            if (str_contains($trimmed, '|') && $i + 1 < $count && $this->isTableSeparator($lines[$i + 1])) {
                $tableLines = [$trimmed];
                $i++;
                while ($i < $count && trim($lines[$i]) !== '') {
                    $tableLines[] = trim($lines[$i]);
                    $i++;
                }
                $html .= $this->renderTable($tableLines);
                continue;
            }

            // 标题
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $match) === 1) {
                $level = strlen($match[1]);
                $html .= $this->renderHeading($level, $match[2]);
                $i++;
                continue;
            }

            // 分隔线
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed) === 1) {
                $html .= "<hr>\n";
                $i++;
                continue;
            }

            // 引用块：连续 > 行合并
            if (str_starts_with($trimmed, '>')) {
                $quoteLines = [];
                while ($i < $count && str_starts_with(trim($lines[$i]), '>')) {
                    $quoteLines[] = preg_replace('/^>\s?/', '', $lines[$i]) ?? '';
                    $i++;
                }
                $html .= '<blockquote>' . $this->renderInline(trim(implode(' ', $quoteLines))) . "</blockquote>\n";
                continue;
            }

            // 无序列表
            if (preg_match('/^[-*+]\s+(.*)$/', $trimmed, $match) === 1) {
                $items = [$match[1]];
                $i++;
                while ($i < $count && preg_match('/^[-*+]\s+(.*)$/', trim($lines[$i]), $itemMatch) === 1) {
                    $items[] = $itemMatch[1];
                    $i++;
                }
                $html .= $this->renderList($items, 'ul');
                continue;
            }

            // 有序列表
            if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $match) === 1) {
                $items = [$match[1]];
                $i++;
                while ($i < $count && preg_match('/^\d+\.\s+(.*)$/', trim($lines[$i]), $itemMatch) === 1) {
                    $items[] = $itemMatch[1];
                    $i++;
                }
                $html .= $this->renderList($items, 'ol');
                continue;
            }

            // 普通段落：收集直到空行或新的块级结构
            $paragraph = [$trimmed];
            $i++;
            while ($i < $count && trim($lines[$i]) !== '') {
                $next = trim($lines[$i]);
                if (
                    preg_match('/^(```|#{1,6}\s|[-*+]\s|\d+\.\s|>)/', $next) === 1
                    || $this->isTableSeparator($lines[$i])
                ) {
                    break;
                }
                $paragraph[] = $next;
                $i++;
            }
            $html .= '<p>' . $this->renderInline(implode(' ', $paragraph)) . "</p>\n";
        }

        return $html;
    }

    /**
     * 渲染目录（TOC）：收集 ## 与 ### 标题，输出锚点导航。
     *
     * @param string $markdown 原始 Markdown 文本
     * @return string 目录 HTML；无 ##/### 标题时返回空字符串
     */
    public function renderToc(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);
        $items = [];
        $inFence = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^```/', $trimmed) === 1) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }
            if (preg_match('/^(#{2,3})\s+(.*)$/', $trimmed, $match) === 1) {
                $level = strlen($match[1]);
                $text = trim($match[2]);
                $items[] = [
                    'level' => $level,
                    'text' => $this->renderInline($text),
                    'id' => $this->slugify($text),
                ];
            }
        }

        if ($items === []) {
            return '';
        }

        $html = '<nav class="docs-toc" aria-label="目录">' . "\n";
        $html .= '<div class="toc-title">目录</div>' . "\n";
        $html .= "<ul>\n";
        foreach ($items as $item) {
            $class = $item['level'] === 2 ? 'toc-h2' : 'toc-h3';
            $html .= '<li class="' . $class . '"><a href="#' . $item['id'] . '">' . $item['text'] . "</a></li>\n";
        }
        $html .= "</ul>\n";
        $html .= "</nav>\n";
        return $html;
    }

    /**
     * 由标题文本生成稳定的锚点 id（保留中英文与数字，其余转连字符）。
     */
    public function slugify(string $text): string
    {
        $plain = strip_tags($this->renderInline($text));
        $slug = preg_replace('/[^\p{L}\p{N}_\-\s]+/u', '', $plain);
        $slug = preg_replace('/[\s_]+/u', '-', $slug ?? '');
        $slug = trim($slug ?? '', '-');
        $slug = strtolower($slug); // 仅影响 ASCII 字母，中文保持不变
        return $slug !== '' ? $slug : 'section';
    }

    /**
     * 渲染标题。
     */
    private function renderHeading(int $level, string $text): string
    {
        $id = $this->slugify($text);
        $inline = $this->renderInline(trim($text));
        return "<h{$level} id=\"{$id}\">" . $inline . "</h{$level}>\n";
    }

    /**
     * 渲染列表。
     *
     * @param array<int, string> $items 列表项（原始文本，未转义）
     * @param string             $tag   ul 或 ol
     */
    private function renderList(array $items, string $tag): string
    {
        $html = "<{$tag}>\n";
        foreach ($items as $item) {
            $html .= '<li>' . $this->renderInline(trim($item)) . "</li>\n";
        }
        $html .= "</{$tag}>\n";
        return $html;
    }

    /**
     * 渲染围栏代码块（含复制按钮容器与语言标注）。
     */
    private function renderCodeBlock(string $code, string $lang): string
    {
        $escapedLang = htmlspecialchars($lang, ENT_QUOTES, 'UTF-8');
        $langClass = $lang !== '' ? ' class="language-' . $escapedLang . '"' : '';
        $label = $lang !== '' ? $escapedLang : 'text';
        $escapedCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        return '<div class="code-block">'
            . '<div class="code-head"><span class="code-lang">' . $label . '</span>'
            . '<button type="button" class="copy-btn" title="复制代码">复制</button></div>'
            . '<pre><code' . $langClass . '>' . $escapedCode . "</code></pre></div>\n";
    }

    /**
     * 渲染表格。
     *
     * @param array<int, string> $lines 表格行（首行为表头，次行为分隔行，其余为数据行）
     */
    private function renderTable(array $lines): string
    {
        $headerLine = array_shift($lines) ?? '';
        $headerCells = $this->splitTableRow($headerLine);
        // 第二行为分隔行（--- 与 :---），仅用于语法识别，内容忽略
        array_shift($lines);

        $html = '<div class="table-wrap"><table>' . "\n<thead><tr>";
        foreach ($headerCells as $cell) {
            $html .= '<th>' . $this->renderInline(trim($cell)) . '</th>';
        }
        $html .= "</tr></thead>\n<tbody>\n";

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = $this->splitTableRow($line);
            $html .= '<tr>';
            foreach ($cells as $cell) {
                $html .= '<td>' . $this->renderInline(trim($cell)) . '</td>';
            }
            $html .= "</tr>\n";
        }

        $html .= "</tbody>\n</table></div>\n";
        return $html;
    }

    /**
     * 按 | 拆分表格行（支持 \| 转义管道符）。
     *
     * @return array<int, string>
     */
    private function splitTableRow(string $line): array
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) {
            $line = substr($line, 1);
        }
        if (str_ends_with($line, '|')) {
            $line = substr($line, 0, -1);
        }

        $parts = [];
        $buffer = '';
        $length = strlen($line);
        for ($j = 0; $j < $length; $j++) {
            $ch = $line[$j];
            if ($ch === '\\' && $j + 1 < $length && $line[$j + 1] === '|') {
                $buffer .= '|';
                $j++;
            } elseif ($ch === '|') {
                $parts[] = $buffer;
                $buffer = '';
            } else {
                $buffer .= $ch;
            }
        }
        $parts[] = $buffer;
        return $parts;
    }

    /**
     * 判断是否为表格分隔行（仅含 |、-、:、空白，且至少含一个 -）。
     */
    private function isTableSeparator(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '' || !str_contains($trimmed, '|')) {
            return false;
        }
        if (preg_match('/^[\s|:\-]+$/', $trimmed) !== 1) {
            return false;
        }
        return str_contains($trimmed, '-');
    }

    /**
     * 渲染行内元素（行内代码 / 加粗 / 链接），其余按转义后的原文输出。
     *
     * 注意：文本先整体转义再做标记扫描，保证输出安全；
     * 递归处理加粗/链接内部时传入 $escapedInput=true 避免二次转义。
     *
     * @param string $text         原始文本
     * @param bool   $escapedInput 是否已是转义后的文本（递归内部使用）
     */
    private function renderInline(string $text, bool $escapedInput = false): string
    {
        $escaped = $escapedInput ? $text : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $out = '';
        $length = strlen($escaped);
        $i = 0;

        while ($i < $length) {
            // 行内代码：`code`
            if ($escaped[$i] === '`') {
                $end = strpos($escaped, '`', $i + 1);
                if ($end !== false) {
                    $code = substr($escaped, $i + 1, $end - $i - 1);
                    $out .= '<code>' . $code . '</code>';
                    $i = $end + 1;
                    continue;
                }
            }

            // 加粗：**text**
            if (substr($escaped, $i, 2) === '**') {
                $end = strpos($escaped, '**', $i + 2);
                if ($end !== false) {
                    $inner = substr($escaped, $i + 2, $end - $i - 2);
                    $out .= '<strong>' . $this->renderInline($inner, true) . '</strong>';
                    $i = $end + 2;
                    continue;
                }
            }

            // 链接：[text](url)
            if ($escaped[$i] === '[') {
                $close = strpos($escaped, '](', $i + 1);
                if ($close !== false) {
                    $paren = $this->findClosingParen($escaped, $close + 2);
                    if ($paren !== false) {
                        $label = substr($escaped, $i + 1, $close - $i - 1);
                        $url = $this->normalizeLinkUrl(substr($escaped, $close + 2, $paren - $close - 2));
                        if ($url !== null) {
                            $out .= '<a href="' . $url . '" rel="noopener" target="_blank">'
                                . $this->renderInline($label, true) . '</a>';
                            $i = $paren + 1;
                            continue;
                        }
                        // URL 非法（危险 scheme / 为空 / 相对路径）：按原样文本输出，绝不生成链接
                        $out .= substr($escaped, $i, $paren - $i + 1);
                        $i = $paren + 1;
                        continue;
                    }
                }
            }

            $out .= $escaped[$i];
            $i++;
        }

        return $out;
    }

    /**
     * 查找与起始括号配对的闭合括号（支持 URL 内含括号，如 https://a.com/b(c)）。
     *
     * 从 $start 起扫描：遇到 '(' 深度 +1，遇到 ')' 深度 -1；
     * 深度回到 0 时的 ')' 即为配对闭合。整个 URL 无配对括号时返回 false（调用方按纯文本处理）。
     *
     * @param string $escaped 已转义文本
     * @param int    $start   起始下标（指向 '](' 之后、URL 内容的第一个字符）
     * @return int|false 闭合 ')' 的下标；未配对时返回 false
     */
    private function findClosingParen(string $escaped, int $start): int|false
    {
        $length = strlen($escaped);
        $depth = 0;
        for ($j = $start; $j < $length; $j++) {
            $ch = $escaped[$j];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                if ($depth === 0) {
                    return $j;
                }
                $depth--;
            }
        }
        return false;
    }

    /**
     * 校验并规范化链接 URL（防 scheme 注入，P1 修复）。
     *
     * 安全约定：
     *   - 仅允许 http / https / mailto 与页面内锚点（#...）；
     *   - javascript: / data: / vbscript: / file: 等危险 scheme 一律返回 null，
     *     由调用方降级为纯文本输出（不生成 <a>，浏览器无法执行）；
     *   - scheme 大小写不敏感（JaVaScRiPt: 同样拦截）；
     *   - 去除首尾 C0 控制字符与空白（\x00-\x20），与浏览器 URL 解析行为一致，
     *     防 " javascript:alert(1)" 这类带前缀空白的绕过；
     *   - 输入为已 htmlspecialchars 转义的 URL；HTML 实体在属性解析时仅解码一次，
     *     &#x73; 这类实体混淆无法在 URL 解析阶段还原为可执行 scheme，天然安全。
     *
     * @param string $escapedUrl 已转义 URL
     * @return string|null 合法 URL（可直接放入 href 属性）；非法返回 null
     */
    private function normalizeLinkUrl(string $escapedUrl): ?string
    {
        // 去除首尾 C0 控制字符与空白，防空格/换行绕过 scheme 检测
        $url = preg_replace('/^[\x00-\x20]+|[\x00-\x20]+$/u', '', $escapedUrl) ?? $escapedUrl;
        if ($url === '') {
            return null;
        }
        // 页面内锚点：#fragment（无 scheme）
        if (str_starts_with($url, '#')) {
            return $url;
        }
        // 提取 scheme（第一个 : 之前的部分），仅匹配合法 scheme 字符集
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $url, $match) === 1) {
            $scheme = strtolower($match[1]);
            if (in_array($scheme, ['http', 'https', 'mailto'], true)) {
                return $url;
            }
            return null; // javascript / data / vbscript / file 及其余 scheme 一律拒绝
        }
        // 无 scheme（相对路径等）：本项目 README 无此用法，保守按纯文本输出
        return null;
    }
}
