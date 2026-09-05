<?php

declare(strict_types=1);

/**
 * 极简 PSR-4 风格自动加载器（无 Composer 依赖）。
 *
 * 命名空间前缀 McPing\ 映射到本文件所在目录（src/）下的同名 PHP 文件。
 * 例如：McPing\VarInt -> src/VarInt.php
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'McPing\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
