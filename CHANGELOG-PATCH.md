# 6.0.11_patch · 2026-10-26

运行环境升级：Laravel 10.50.3 → **Laravel 13.38.0**，PHP 8.5.12（需 PHP >= 8.5.0）。
功能与 patch 定制行为全部保留，升级按 10 → 11 → 12 → 13 逐级推进并逐级验证。

- 升级 Laravel（10.50.3 → 11.57.0 → 12.70.1 → 13.38.0），移除 `doctrine/dbal`，迁移改用原生 `->change()`。
- CSRF 中间件改为 `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`。
- `config/cache.php` 新增 `serializable_classes` 显式白名单（保留纹理预览与头像 `Response` 缓存）；
  `config/session.php` 显式锁定 `serialization => php`，避免全体会话失效。
- `.env.example` 显式提供 `CACHE_PREFIX` / `REDIS_PREFIX` / `SESSION_COOKIE`，缓存键前缀与会话 Cookie 名保持不变。
- 排除 `Illuminate\Image\ImageServiceProvider`，`image` 绑定保持 `intervention/image` v2，纹理渲染不受影响。
- Passport 升级 v13.10.0：保持旧 `oauth_clients` 表结构，关闭 device code grant，
  新增幂等迁移对既有客户端密钥做一次哈希，前端一次性展示明文密钥。
- `lorisleiva/laravel-search-string` 按应用层移植到 `app/Support/SearchString/`（行为与 v1.3.0 逐字节一致）。
- `blessing/filter` / `blessing/rejection` 改用本地 path 包（1.5.0，仅放宽 `illuminate/*` 至 `^13`）。
- 调试栏升级 v4.4 并整体重发布 `config/debugbar.php`；PHPUnit 升级至 12；Tinker 升级至 3。
- 全站 104 个 Twig 模板、插件 API、ZIP 在线更新（备份 / SHA-256 / 安装确认）、通知与公告管理中心、
  组件式编辑器、源码模式、统一分页、更新源与插件仓库配置均无行为变化。

# 6.0.10_patch · 2026-10-25

- 两仓库将正式发布附件同步到各自 web-publish 分支，并自动部署 GitHub Pages。
- 主站更新源使用 skin.blessing.dreamcraft.eu.org，插件仓库使用 plugin.blessing.dreamcraft.eu.org；JSON 内下载地址使用同一 Pages 域名。
- 保留版本下载目录与原始 SHA-256，第三方修补源切换按钮采用新地址。

# 6.0.9_patch · 2026-10-25

- 更新源保存和切换同时同步 .env 的 UPDATE_SOURCE、PLUGINS_REGISTRY，刷新 Laravel 配置缓存。
- 第三方源按钮改为“切换到 DreamCraft-Tech 第三方修补源”。

# 6.0.8_patch · 2026-10-25

- 更新源配置在两个 URL 输入框上方显示红色加粗的第三方更新源安全提示。

- 增加官方源与 DreamCraft-Tech patch 源切换按钮，一次保存两项地址。

# 6.0.7_patch · 2026-10-25

- 站点配置在 reCAPTCHA 下增加更新与插件仓库卡片，可设置主站更新检测 URL 和插件仓库 URL。
- Release 自动提供主站 update.json 和中英文插件 registry JSON，通过公开附件地址检查并下载更新。
- 在线完整运行包校验 SHA-256 与版本后进入确认安装，沿用 ZIP 更新的备份与安装流程。

# 6.0.6_patch · 2026-10-24

- ZIP 上传区域使用系统统一的文件选择控件，显示已选文件名。
- 各版本更新日志按从新到旧叠放，默认全部折叠，并持续保留历史版本。

# 6.0.5_patch · 2026-10-24

- 通知与公告列表的编辑、撤回及返回按钮与用户管理统一。
- 列表使用系统分页样式，显示页码、当前页、总页数与记录数。
- 仪表盘公告编辑按钮直接打开当前公告，保存时原位覆盖；站内信独立保留发布记录。

# 6.0.4_patch · 2026-10-24

基于 Blessing Skin Server 6.0.2（52f6fef）的 PHP 8.5 定制版本，包含 6.0.3_patch 的管理员入口修复与 ZIP 更新。

- 上传区域读取现场 PHP 文件与请求上限，并与应用配置计算可接收大小。
- 管理菜单新增“通知与公告”，位于仪表盘与用户管理之间。
- 公告与站内信共用管理中心，支持草稿、预览、发布、编辑和撤回。
- 站内信提供历史页、详情页、已读状态和原收件内容更新。
- 可视化组件包含文本、标题、提示框、折叠卡片和分隔线，支持拖动排序、属性编辑及实时预览。
- 源码模式支持 Markdown / HTML，可将提示框和折叠卡片导入为组件。
- 编辑页面提供返回列表，撤回前显示确认框及影响范围。
- 升级时导入原公告和既有站内信，并保留原有收件时间与已读状态。
