<?php

declare(strict_types=1);

/**
 * 全局配置文件。
 *
 * 该文件返回一个关联数组，被 src/PingClient.php 与 public/index.php 加载。
 * 所有键均有默认值兜底（见 PingClient::defaultConfig 与各类的构造参数默认值）。
 *
 * 环境变量覆盖：支持以 MCAPI_* 前缀环境变量覆盖部分配置项（Docker 部署用），
 * 映射表见文件末尾 envMap。布尔值支持 1/0/true/false/yes/no/on/off。
 *
 * @return array<string, mixed>
 */

$config = [
    // 部署在域名子目录（子路径）时的基础路径前缀，例如 '/mcstatus' 表示
    // 站点通过 https://host/mcstatus/ 访问。空字符串表示部署在域名根目录。
    // 配置后，前端路由会剥除该前缀，页面链接 / 接口 URL 统一带上该前缀。
    'base_path' => '/mcstatus',

    // 连接与读取超时时间（秒）。现代协议失败后自动降级 legacy 协议时，
    // 最坏情况下总耗时约为该值的 2 倍。
    'timeout_seconds' => 5.0,

    // 单次响应的最大字节数（字节）。防止恶意服务器发送超大包拖垮进程。
    'max_packet_bytes' => 2097152, // 2 MB

    // 默认 Minecraft Java 版端口
    'default_port' => 25565,

    // host 参数最大长度（字符）
    'host_max_length' => 255,

    // 解码后的 favicon PNG 图片保存目录（代码会自动创建）
    'favicon_dir' => dirname(__DIR__) . '/favicons',

    // favicon 的 HTTP 访问前缀（public/index.php 中 /favicon/{token}.png 路由对应）
    'favicon_url_prefix' => '/favicon',

    // 是否启用 1.7+ 现代状态协议
    'modern_enabled' => true,

    // 是否启用 legacy 0xFE 协议（1.4~1.6 老版本服务器自动降级）
    'legacy_enabled' => true,

    // 握手首选协议版本：-1 表示"仅用于状态查询"（大多数服务器接受）
    'protocol_version' => -1,

    // 首选版本被服务器拒绝时依次尝试的真实协议版本（如 767=1.20.6, 769=1.21.1, 765=1.20.4）
    'protocol_fallback_versions' => [767, 769, 765, 771],

    // 是否自动解析 _minecraft._tcp.<host> 的 SRV 记录：
    // 默认端口（25565）或用户显式指定端口查询失败时，自动按 SRV 目标主机+端口重试一次，
    // 便于命中使用非 25565 端口的外置登录服务器（如 BungeeCord 后端、高防前置）。
    'srv_auto_resolve' => true,

    // SRV DNS 查询超时（秒）。dns_get_record 不提供超时参数，该值暂为预留项，
    // 供将来接入自定义 DNS 解析器或注入式查询时使用；当前保留 0 或默认 1 均可。
    'srv_timeout' => 1.0,

    // ============ P0-1 缓存层 ============

    // 是否启用进程内内存 TTL 缓存（仅缓存查询成功的服务器状态）
    'cache_enabled' => true,

    // 缓存有效期（秒）。窗口内重复查询同一服务器直接命中缓存，不再发起真实 TCP 连接
    'cache_ttl_seconds' => 30,

    // 缓存跨请求持久化文件路径（Windows 内置服务器每请求独立进程/线程，
    // 写盘 + 下次加载实现跨请求命中；目录自动创建）
    'cache_file_path' => dirname(__DIR__) . '/data/cache.json',

    // ============ P0-2 批量并发查询 ============

    // 单次批量查询的最大服务器数量（超过返回 1009）
    'batch_max_servers' => 20,

    // 批量查询总时间预算（秒）。所有服务器并发推进，超过该预算未完成的按超时失败
    'batch_timeout_seconds' => 10.0,

    // ============ P0-3 SQLite 可用性监控 ============

    // 是否启用 SQLite 可用性监控（pdo_sqlite 扩展缺失时自动禁用并记 error_log）
    'monitor_enabled' => true,

    // 监控数据库文件路径（目录自动创建）
    'monitor_db_path' => dirname(__DIR__) . '/data/monitor.sqlite',

    // 历史记录保留天数（每次写入时顺带清理过期行）
    'monitor_history_days' => 30,

    // ============ P1-2 玩家头像代理 ============

    // 上游头像 API 模板（{uuid} 会被替换为请求的 UUID）。
    // 外置登录服（authlib-injector 等）的 UUID 属于第三方 Yggdrasil 体系，
    // 官方 minotar/crafatar 无其皮肤，可将本模板指向支持外置登录的皮肤站 API。
    'avatar_api_template' => 'https://minotar.net/avatar/{uuid}.png',

    // 头像本地缓存目录（自动创建）
    'avatar_cache_dir' => dirname(__DIR__) . '/avatars',

    // 头像本地缓存有效期（小时）；0 表示不过期（仅按文件是否存在判断）
    'avatar_cache_ttl_hours' => 24,

    // 上游头像抓取超时（秒）
    'avatar_timeout_seconds' => 5.0,

    // ============ P1-3 限流 + API Key ============

    // 是否启用固定窗口限流（按客户端 IP + 路由分桶）
    'rate_limit_enabled' => true,

    // 每个 IP + 路由每分钟允许的请求数（超过返回 429 + 1007）
    'rate_limit_per_minute' => 60,

    // 限流桶跨请求持久化文件路径（同上，Windows 内置服务器需要写盘跨请求生效）
    'ratelimit_file_path' => dirname(__DIR__) . '/data/ratelimit.json',

    // API Key 列表（空数组 = 不启用鉴权）。
    // 非空时，请求须携带 X-API-Key 头或 ?api_key= 且值命中列表，否则 401 + 1008；
    // 携带合法 Key 的请求跳过限流。
    // 注意：/、/docs、/assets、/health 为公开展示资源，不参与鉴权。
    'api_keys' => [],

    // 是否信任 X-Forwarded-For 等代理头获取客户端 IP（默认 false，防伪造；
    // 仅当 API 部署在可信反向代理之后时才应开启）
    'trust_proxy_headers' => false,

    // ============ P1-4 指标持久化 ============

    // 状态统计跨请求持久化文件路径（Windows 内置服务器需要写盘跨请求累计）
    'metrics_file_path' => dirname(__DIR__) . '/data/metrics.json',

    // 进程启动时间持久化文件路径（用于 uptime_seconds 跨请求保持真实运行时长）
    'metrics_uptime_file' => dirname(__DIR__) . '/data/uptime.txt',

    // ============ P1-5 Webhook 状态变化通知 ============

    'webhook' => [
        // 是否启用服务器状态（在线/离线）变化通知
        'enabled' => false,

        // 目标 Webhook 地址：
        //   飞书自定义机器人 / 钉钉机器人 / 企业微信群机器人 / 任意支持 POST JSON 的地址
        'url' => '',

        // 消息平台：feishu（飞书） | dingtalk（钉钉） | wecom（企业微信） | generic（通用）
        'platform' => 'generic',

        // 钉钉加签密钥（留空则不签名；仅钉钉平台需要）
        'secret' => '',

        // 是否在服务器"上线"时也通知（false 时仅离线通知）
        'notify_online' => true,

        // 同一台服务器两次状态变化通知的最短间隔（秒），防止抖动刷屏
        'cooldown_seconds' => 300,

        // 状态快照持久化文件路径（跨请求生效，自动创建）
        'state_file' => dirname(__DIR__) . '/data/webhook_state.json',
    ],

    // ============ P1-6 MCP 远程端点（Streamable HTTP） ============

    'mcp' => [
        // 是否启用 Remote MCP 端点（public/index.php 的 "mcp" 路由，形如 /mcstatus/mcp）
        'enabled' => true,

        // 鉴权令牌：客户端须携带 Authorization: Bearer <token>。
        // 留空字符串 = 端点拒绝一切访问（安全护栏，防把查询能力裸奔公网）。
        // 生产环境务必填写随机长字符串，可用环境变量 MCAPI_MCP_BEARER_TOKEN 注入。
        'bearer_token' => '35c7efdfc1044918353e319dd7daf0c1f4b3d418da6b09b7fd63d54fed63458b',

        // GET 事件流（SSE 心跳）的最长存续秒数；0 表示不限时（不推荐）。
        // 说明：GET 会持续占用一个 PHP 工作进程，须跑在支持并发的 Web 服务器
        // （Nginx/Apache + PHP-FPM 或 mod_php，pm.max_children 建议 ≥ 3），
        // PHP 内置单进程服务器（php -S）不适合远程 MCP。
        'sse_max_seconds' => 600,
    ],

    // ============ UI 配置（主页在线工具 / 文档页） ============

    // 网页标题 / 描述 / 示例地址 / 版本徽章（纯展示用途，不影响 API 行为）
    'ui' => [
        'site_title' => 'Minecraft 服务器状态查询',
        'site_description' => '基于 Server List Ping 协议的 Minecraft Java 版服务器状态在线查询工具，支持单台与批量查询、彩色 MOTD、SRV 自动解析',
        'example_host' => 'mc.goldenapplepie.xyz',
        'version' => 'v1.9',
    ],
];

// ============ 环境变量覆盖（Docker 部署） ============
// 格式：MCAPI_<配置项大写>。布尔解析：1/true/yes/on 为 true；0/false/no/off 为 false。

/** @var array<string, array{key: string, type: string}> 环境变量映射 */
$envMap = [
    'MCAPI_TIMEOUT_SECONDS' => ['key' => 'timeout_seconds', 'type' => 'float'],
    'MCAPI_CACHE_ENABLED' => ['key' => 'cache_enabled', 'type' => 'bool'],
    'MCAPI_CACHE_TTL_SECONDS' => ['key' => 'cache_ttl_seconds', 'type' => 'int'],
    'MCAPI_BATCH_MAX_SERVERS' => ['key' => 'batch_max_servers', 'type' => 'int'],
    'MCAPI_BATCH_TIMEOUT_SECONDS' => ['key' => 'batch_timeout_seconds', 'type' => 'float'],
    'MCAPI_MONITOR_ENABLED' => ['key' => 'monitor_enabled', 'type' => 'bool'],
    'MCAPI_MONITOR_DB_PATH' => ['key' => 'monitor_db_path', 'type' => 'string'],
    'MCAPI_MONITOR_HISTORY_DAYS' => ['key' => 'monitor_history_days', 'type' => 'int'],
    'MCAPI_AVATAR_API_TEMPLATE' => ['key' => 'avatar_api_template', 'type' => 'string'],
    'MCAPI_AVATAR_CACHE_DIR' => ['key' => 'avatar_cache_dir', 'type' => 'string'],
    'MCAPI_AVATAR_CACHE_TTL_HOURS' => ['key' => 'avatar_cache_ttl_hours', 'type' => 'int'],
    'MCAPI_RATE_LIMIT_ENABLED' => ['key' => 'rate_limit_enabled', 'type' => 'bool'],
    'MCAPI_RATE_LIMIT_PER_MINUTE' => ['key' => 'rate_limit_per_minute', 'type' => 'int'],
    'MCAPI_API_KEYS' => ['key' => 'api_keys', 'type' => 'array'],
    'MCAPI_TRUST_PROXY_HEADERS' => ['key' => 'trust_proxy_headers', 'type' => 'bool'],
    'MCAPI_SRV_AUTO_RESOLVE' => ['key' => 'srv_auto_resolve', 'type' => 'bool'],
];

// MCP 子配置（嵌套数组，单独应用环境变量覆盖）。
$envMcpEnabled = getenv('MCAPI_MCP_ENABLED');
if ($envMcpEnabled !== false && $envMcpEnabled !== '') {
    $config['mcp']['enabled'] = in_array(strtolower(trim((string)$envMcpEnabled)), ['1', 'true', 'yes', 'on'], true);
}
$envMcpToken = getenv('MCAPI_MCP_BEARER_TOKEN');
if ($envMcpToken !== false && $envMcpToken !== '') {
    $config['mcp']['bearer_token'] = (string)$envMcpToken;
}

foreach ($envMap as $envName => $mapping) {
    $raw = getenv($envName);
    if ($raw === false || $raw === '') {
        continue;
    }
    $type = $mapping['type'];
    $key = $mapping['key'];
    switch ($type) {
        case 'bool':
            $normalized = strtolower(trim((string)$raw));
            $config[$key] = in_array($normalized, ['1', 'true', 'yes', 'on'], true);
            break;
        case 'int':
            $config[$key] = (int)$raw;
            break;
        case 'float':
            $config[$key] = (float)$raw;
            break;
        case 'array':
            // 逗号分隔的 API Key 列表
            $config[$key] = array_values(array_filter(array_map('trim', explode(',', (string)$raw)), static fn (string $v): bool => $v !== ''));
            break;
        default:
            $config[$key] = (string)$raw;
            break;
    }
}

return $config;
