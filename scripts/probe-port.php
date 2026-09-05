<?php
/**
 * 端口可用性探针（供 start-local.bat 调用）。
 * 用法：php probe-port.php <host> <port>
 * 输出：0 = 可绑定，1 = 已被占用（TIME_WAIT 等被动连接不计入占用）
 */

if ($argc < 3) {
    fwrite(STDERR, "usage: php probe-port.php <host> <port>\n");
    exit(2);
}

$host = (string)$argv[1];
$port = (int)$argv[2];

if ($port < 1 || $port > 65535) {
    echo '1';
    exit;
}

// 尝试以 TCP 客户端方式连接：能连上说明本地已有进程监听该端口。
$errno = 0;
$errstr = '';
$conn = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 0.3);
if ($conn !== false) {
    fclose($conn);
    echo '1';
    exit;
}

// 若连接被拒绝（RST），说明端口当前无人监听，可安全绑定。
echo '0';
