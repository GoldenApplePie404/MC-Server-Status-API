mcmon v0.4.0  -  Minecraft 服务器订阅监控客户端
================================================

【这是什么】
  一个本地运行的小工具，用来定时轮询 Minecraft 服务器状态、
  离线/延迟/人数骤降时告警、绘制在线人数趋势图。
  所有数据 100% 留在你自己的电脑上。

【快速开始（3 步）】
  1) 双击 mcmon.exe
     - Win10/11 会自动弹出原生窗口（WebView2）
     - 老版本 Windows 没装 WebView2 会用系统浏览器打开
  2) 进入「配置」页
     - 改「数据源地址」为你要查询的 API 主站（默认金苹果派主站）
     - 点「保存」
  3) 进入「订阅清单」
     - 添加你想盯的服务器（host:port + 别名）
     - 点「保存」
     - 后台轮询立即开始

【三种启动方式】
  mcmon                 默认：双击打开桌面原生窗口
  mcmon --web           强制用外部浏览器打开 UI
  mcmon --run           命令行后台采样（配合 --nopause 可脚本化）

【首次启动会在当前目录自动创建】
  config.json           你的配置（由 config.example.json 复制而来）
  subscriptions.json    订阅清单
  snapshots/            采样历史、趋势数据、事件日志、运行日志
  这些文件可以随时删除，mcmon 会自动重建。

【想直接编辑配置文件】
  复制 config.example.json 为 config.json，改完保存即可。
  修改无需重启，后台轮询自动感知。

【Webhook 推送】（可选）
  在「配置」页填 webhook_url + webhook_token，
  离线/恢复/延迟告警会自动 POST 到飞书/钉钉/企业微信/通用接口。

【事件列表为空？】
  说明你的服务器状态一直很稳，还没触发任何离线/延迟/骤降规则，
  属于正常现象。等到规则命中时会自动出现。

【启动闪退？】
  看 snapshots/log/mcmon.log，最后几行有原因。

【WebView2 无法打开？】
  安装 Microsoft Edge WebView2 Runtime（免费，几十 MB）。

【卸载】
  直接删除整个文件夹即可，没有注册表残留。
