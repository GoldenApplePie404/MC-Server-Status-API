# 子目录部署指南（mcpc.goldenapplepie.xyz/mcstatus/）

> 目标：将 mc-server-api 部署到云主机面板（类似宝塔）的**共享域名子目录**下，
> 通过 `https://mcpc.goldenapplepie.xyz/mcstatus/` 访问主页、`/docs`、`/metrics`、
> `/health` 及各 API 接口，不影响同一站点根目录下的其他项目。

---

## 一、总体思路

- 站点根目录为 `/PC_Web`（指向 `mcpc.goldenapplepie.xyz`），本项目的物理路径为
  `/PC_Web/mcstatus`。
- 项目真正的 Web 入口在 **`public/index.php`**，`src/`、`config/`、`data/` 等
  **不应**被直接访问。因此需要让 Web 服务器把所有 `mcstatus/*` 请求交给
  `public/index.php`，并把 `/mcstatus/assets/*` 映射到 `public/assets/*`。
- 新增配置项 `base_path`：配置为 `/mcstatus` 后，前端路由会剥除该前缀，
  所有页面链接、静态资源、API / favicon / avatar 的 URL 自动带上该前缀。

---

## 二、必须的核心配置

### 1. 修改 config/config.php（关键！）

```php
'base_path' => '/mcstatus',
```

保存后，全部页面链接会自动变成 `/mcstatus/docs`、`/mcstatus/assets/app.css` 等。

> 注意：`base_path` 默认是空字符串（域名根目录部署，保持原行为不回归）。
> 子目录部署务必把它改成实际前缀。

### 2. 目录写权限

面板中使用系统用户运行 PHP（如 `www`），需保证以下目录可写（`755` 或按面板
一键设置写入权限）：

- `/PC_Web/mcstatus/data`（缓存 / 限流 / 指标 / 监控 SQLite）
- `/PC_Web/mcstatus/favicons`（favicon 落盘）
- `/PC_Web/mcstatus/avatars`（头像缓存）

---

## 三、按 Web 引擎操作

### 情形 A：站点使用 Apache（面板默认不会自动读 php 伪静态除外）

1. 上传整个项目到 `/PC_Web/mcstatus`。
2. 将 `deploy/htaccess.mcstatus.txt` 复制为 `/PC_Web/mcstatus/.htaccess`。
3. 确认 `/PC_Web/mcstatus/.htaccess` 里 `RewriteBase /mcstatus/` 与你的前缀一致。
4. 确认站点 PHP 版本 ≥ 8.1。
5. 访问 `https://mcpc.goldenapplepie.xyz/mcstatus/` 应显示主页。

### 情形 B：站点使用 Nginx（面板多为 Nginx）

1. 上传整个项目到 `/PC_Web/mcstatus`。
2. 在 Nginx 的 `server { }` 块中加入 `deploy/nginx-mcstatus.conf.txt` 里的
   `location /mcstatus/` 片段（注意把路径 `/PC_Web/mcstatus` 和 PHP-FPM
   的 `fastcgi_pass` 改成你服务器的真实值）。
3. 重载 Nginx。
4. 访问 `https://mcpc.goldenapplepie.xyz/mcstatus/` 应显示主页。

---

## 四、效果验证

```bash
# 主页
curl -I "https://mcpc.goldenapplepie.xyz/mcstatus/"

# 文档 / 指标 / 健康
curl -I "https://mcpc.goldenapplepie.xyz/mcstatus/docs"
curl -I "https://mcpc.goldenapplepie.xyz/mcstatus/metrics?format=html"
curl -I "https://mcpc.goldenapplepie.xyz/mcstatus/health"

# 静态资源（应返回 200 且带 /mcstatus/ 前缀）
curl -I "https://mcpc.goldenapplepie.xyz/mcstatus/assets/app.css"

# API（应返回 JSON / 200）
curl "https://mcpc.goldenapplepie.xyz/mcstatus/api/ping?host=mc.hypixel.net"
```

均返回 **200** 即部署成功。

---

## 五、常见问题

| 现象 | 原因 / 解法 |
|------|-------------|
| 主页能开，但 `/docs`、`/metrics`、API 404 | 未配置 rewrite / 伪静态，请求没交给 `public/index.php`。核对情形 A/B 的第 2 步 |
| 页面能开但没有样式/脚本 | `/assets/*` 未映射到 `public/assets`，或 `base_path` 未设置导致链接仍是 `/assets/.` |
| 其他项目正常，仅本项目异常 | 确认只在本项目目录加了 `.htaccess`，并检查 `RewriteBase` 前缀 |
| 上传后连主页都不显示 | 检查 `base_path`、目录写权限、站点 PHP 版本 |