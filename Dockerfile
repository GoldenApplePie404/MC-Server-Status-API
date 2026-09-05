# Minecraft Java 版服务器状态查询 API
# 基于 PHP 8.3 CLI + 内置服务器，无 Composer/框架依赖。
FROM php:8.3-cli-alpine

# 安装 pdo_sqlite 扩展（SQLite 可用性监控依赖；缺失时自动降级禁用）
RUN docker-php-ext-install pdo_sqlite

# 工作目录与项目复制
WORKDIR /app
COPY . .

# 运行期数据目录（favicon / 头像缓存 / SQLite 监控库），由卷挂载
RUN mkdir -p /app/data /app/favicons /app/avatars

# 对外端口
EXPOSE 8080

# 启动 PHP 内置服务器，public/ 作为文档根
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
