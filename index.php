<?php
/**
 * 项目根目录入口（子目录部署用）。
 *
 * 背景：本项目的真实入口在 public/index.php。当站点通过「文件管理」直接把
 * 整个项目放到一个子目录（如 /PC_Web/mcstatus）下、且面板未将运行目录指向
 * public 时，Web 服务器访问 /mcstatus/ 会在该目录下找不到 index 文件而返回 403。
 *
 * 本文件作为根目录入口，直接引入 public/index.php，使 /mcstatus/ 也能正常
 * 命中前端控制器。路由剥除 base_path、静态资源定位等逻辑全部复用
 * public/index.php（其内部均基于 __DIR__ 定位，不受本入口影响）。
 *
 * 注意：config/config.php 的 base_path 需设为 '/mcstatus'。
 */

require __DIR__ . '/public/index.php';
