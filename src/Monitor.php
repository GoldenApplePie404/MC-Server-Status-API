<?php

declare(strict_types=1);

namespace McPing;

/**
 * SQLite 可用性监控记录器（P0-3）。
 *
 * 在 PingClient 每次查询后（成功与失败都记录）写入 status_log 表，
 * 供 /api/monitor 按 host 查询历史在线/延迟/人数记录。
 *
 * 健壮性设计：
 *   - 启动时检测 pdo_sqlite 扩展；不可用时自动禁用（monitor_enabled 视为 false），
 *     并记一条 error_log，不抛出异常、不影响查询主流程；
 *   - 建表、写入、清理均以 CREATE TABLE IF NOT EXISTS / 幂等 SQL 实现；
 *   - 所有文件系统与数据库操作均包裹 try/catch，失败仅记 error_log；
 *   - 每次写入顺带清理超过 monitor_history_days 的过期行（简单实现）。
 */
final class Monitor
{
    /** @var \PDO|null 数据库连接；不可用时为 null */
    private static ?\PDO $pdo = null;

    /** @var bool 是否已尝试初始化 */
    private static bool $initialized = false;

    /** @var bool 是否启用（pdo_sqlite 可用且配置开启） */
    private static bool $enabled = false;

    /** @var int 历史保留天数 */
    private static int $historyDays = 30;

    /**
     * 初始化监控（惰性，首次调用时执行一次）。
     *
     * @param array<string, mixed> $config 全局配置
     */
    public static function init(array $config): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // 配置开关
        if (!(bool)($config['monitor_enabled'] ?? true)) {
            self::$enabled = false;
            return;
        }

        // pdo_sqlite 扩展检测
        if (!extension_loaded('pdo_sqlite')) {
            self::$enabled = false;
            error_log('[mc-server-api] pdo_sqlite 扩展不可用，SQLite 可用性监控已禁用');
            return;
        }

        $dbPath = (string)($config['monitor_db_path'] ?? '');
        if ($dbPath === '') {
            self::$enabled = false;
            return;
        }

        try {
            $dir = dirname($dbPath);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                error_log('[mc-server-api] 无法创建监控数据库目录：' . $dir);
                self::$enabled = false;
                return;
            }
            $pdo = new \PDO('sqlite:' . $dbPath, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('CREATE TABLE IF NOT EXISTS status_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                host TEXT,
                port INTEGER,
                online INTEGER,
                latency_ms INTEGER,
                online_players INTEGER,
                max_players INTEGER,
                protocol INTEGER,
                protocol_used TEXT,
                checked_at INTEGER
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_status_log_host_checked ON status_log (host, checked_at)');
            self::$pdo = $pdo;
            self::$enabled = true;
            self::$historyDays = max(1, (int)($config['monitor_history_days'] ?? 30));
        } catch (\Throwable $e) {
            error_log('[mc-server-api] SQLite 监控初始化失败：' . $e->getMessage());
            self::$enabled = false;
        }
    }

    /**
     * 记录一次查询结果。
     *
     * @param string               $host 请求方视角的主机（规范化后，含 IPv6 方括号）
     * @param int                  $port 端口
     * @param array<string, mixed>|null $data 查询成功时的统一 data 结构；失败传 null
     */
    public static function record(string $host, int $port, ?array $data = null): void
    {
        if (!self::$enabled || self::$pdo === null) {
            return;
        }

        try {
            if ($data === null || !($data['online'] ?? false)) {
                $online = 0;
                $latency = null;
                $onlinePlayers = null;
                $maxPlayers = null;
                $protocol = null;
                $protocolUsed = null;
            } else {
                $online = 1;
                $latency = isset($data['latency_ms']) ? (int)$data['latency_ms'] : null;
                $onlinePlayers = isset($data['players']['online']) ? (int)$data['players']['online'] : null;
                $maxPlayers = isset($data['players']['max']) ? (int)$data['players']['max'] : null;
                $protocol = isset($data['version']['protocol']) ? (int)$data['version']['protocol'] : null;
                $protocolUsed = isset($data['protocol_used']) ? (string)$data['protocol_used'] : null;
            }

            $stmt = self::$pdo->prepare(
                'INSERT INTO status_log (host, port, online, latency_ms, online_players, max_players, protocol, protocol_used, checked_at)
                 VALUES (:host, :port, :online, :latency_ms, :online_players, :max_players, :protocol, :protocol_used, :checked_at)'
            );
            $stmt->execute([
                ':host' => $host,
                ':port' => $port,
                ':online' => $online,
                ':latency_ms' => $latency,
                ':online_players' => $onlinePlayers,
                ':max_players' => $maxPlayers,
                ':protocol' => $protocol,
                ':protocol_used' => $protocolUsed,
                ':checked_at' => time(),
            ]);

            self::cleanupExpired();
        } catch (\Throwable $e) {
            error_log('[mc-server-api] SQLite 监控写入失败：' . $e->getMessage());
        }
    }

    /**
     * 查询某台服务器的历史记录（按时间倒序）。
     *
     * @param string $host  主机（大小写不敏感匹配）
     * @param int    $limit 返回条数（1-200，调用方已约束）
     * @return array<int, array<string, mixed>> 记录数组；未启用/异常时返回 []
     */
    public static function query(string $host, int $limit): array
    {
        if (!self::$enabled || self::$pdo === null) {
            return [];
        }

        try {
            $limit = max(1, min(200, $limit));
            $stmt = self::$pdo->prepare(
                'SELECT id, host, port, online, latency_ms, online_players, max_players, protocol, protocol_used, checked_at
                 FROM status_log
                 WHERE host = :host COLLATE NOCASE
                 ORDER BY checked_at DESC, id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':host', $host, \PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            error_log('[mc-server-api] SQLite 监控查询失败：' . $e->getMessage());
            return [];
        }
    }

    /**
     * 清理超过保留天数的历史记录（每次写入后顺带执行）。
     */
    private static function cleanupExpired(): void
    {
        if (self::$pdo === null) {
            return;
        }
        try {
            $cutoff = time() - self::$historyDays * 86400;
            $stmt = self::$pdo->prepare('DELETE FROM status_log WHERE checked_at < :cutoff');
            $stmt->execute([':cutoff' => $cutoff]);
        } catch (\Throwable $e) {
            error_log('[mc-server-api] SQLite 监控清理失败：' . $e->getMessage());
        }
    }

    /**
     * 当前是否启用（供测试与诊断）。
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * 重置内部状态（测试隔离用；再次使用前需重新 init）。
     */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$initialized = false;
        self::$enabled = false;
        self::$historyDays = 30;
    }
}
