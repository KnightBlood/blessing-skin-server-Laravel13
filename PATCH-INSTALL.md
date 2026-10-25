# 6.0.11_patch 安装与更新

运行环境：PHP **8.5.x**（最低 8.5.0，不再支持 8.1–8.4）、MariaDB、1Panel OpenResty。
框架为 **Laravel 13.38.0**。完整运行包包含 Composer 依赖和已构建的前端资源，网站运行目录为 public/。

## 更新现有网站

已安装 6.0.3_patch 至 6.0.10_patch 的站点：在“检查更新”的 ZIP 更新区域上传
`blessing-skin-server_6.0.11_patch_php85.zip`，核对版本后确认安装。

**升级前必须确认 PHP 版本**：6.0.11_patch 要求 PHP >= 8.5.0。PHP 低于 8.5 时
`bootstrap/chkenv.php` 会直接拦截并显示错误，请先在主机面板切换 PHP 版本
（1Panel 中为网站 → 运行环境），并确认 `imagick`、`gd`、`zip`、`pdo`、`openssl`、
`mbstring`、`tokenizer`、`ctype`、`xml`、`json`、`fileinfo` 均已安装。

手动更新：将 overlay.zip 解压到网站项目根目录覆盖程序文件，在网站 PHP 运行环境、项目根目录执行：

```sh
php artisan config:clear
php artisan route:clear
php artisan migrate --force
php artisan view:clear
php artisan twig:clean
```

> `php artisan twig:clean` 会清空 Twig 编译缓存，升级后必须执行一次，否则旧缓存模板
> 可能引用已不存在的类。

随后重启 PHP 运行环境以刷新 OPcache。ZIP 更新沿用站点的 .env、storage、plugins 和上传资源；安装备份位于 storage/app/system-updates/。

### 升级后请在 .env 中确认的三项

6.0.11_patch 起为缓存键前缀与会话 Cookie 名称提供显式配置项。`.env.example` 中已写入
与升级前默认值一致的取值，**老 `.env` 不含这些键时行为不变**；如需固定行为，可显式补上：

```dotenv
CACHE_PREFIX=blessing_skin_cache_
REDIS_PREFIX=blessing_skin_database_
SESSION_COOKIE=BS_SESSION
```

- 修改 `SESSION_COOKIE` 会使所有已登录用户掉线（旧 Cookie 名不再被读取）。
- 修改 `CACHE_PREFIX` 会使旧缓存全部失效（安全，但会重新计算一次）。

### 已知运行环境差异

- **缓存序列化白名单**：`config/cache.php` 的 `serializable_classes` 为显式白名单，
  纹理预览与头像缓存依赖它。**不要改为 `false`**，否则缓存命中会返回
  `__PHP_Incomplete_Class` 并 500。插件若在缓存中保存自定义对象，
  需把对应类加入白名单。执行 `php artisan config:cache` 前请确认该列表未被改动。
- **会话序列化**：`config/session.php` 锁定 `serialization => php`。改成 `json`
  会让全部现存会话失效。
- **不支持 `Illuminate\Support\Facades\Image`**：`config/app.php` 已排除 L13 内置的
  `Illuminate\Image\ImageServiceProvider`（它要求 `intervention/image` v4，
  会劫持 `image` 绑定）。图片处理继续使用 `app('image')`
  （`Intervention\Image\ImageManager`）。
- **OAuth 客户端密钥**：Passport 已升级至 v13，既有客户端密钥通过幂等迁移
  `2026_10_25_000001_hash_oauth_client_secrets.php` 一次性哈希。迁移后首次进入
  OAuth 客户端管理页会显示一次明文密钥，请及时保存；此后不再可查。

## 通知与公告

管理菜单位于“仪表板”和“用户管理”之间。发布位置可选站点公告、站内信或两者。编辑器提供标题、文本、提示框、折叠卡片和分隔线，可拖动排序、调整属性并实时预览；源码模式支持 Markdown 和 HTML，并可导入为可视化组件。

公告显示在用户仪表盘，站内信进入用户消息列表。每种语言的公告保存时覆盖当前内容；站内信独立保留发布记录。管理员从仪表盘公告旁的小铅笔直接进入公告编辑器，单独修改公告保留已发送站内信的内容。站内信支持草稿、编辑、已读统计、撤回与重新发布；撤回前显示确认弹窗。编辑已有站内信保留原收件人和已读状态，重新发布生成新一轮投递。编辑页面顶部和底部均提供返回列表。列表按钮沿用用户管理的标准尺寸，分页提供页码、上一页、下一页与记录数。各版本更新日志从新到旧叠放，每个版本默认折叠，持续保留历史。ZIP 上传采用系统统一的文件选择控件。

升级迁移将原站点公告及历史站内信导入管理中心，保留历史消息的已读状态和时间。数据表使用 InnoDB。

## 上传上限

ZIP 更新页面读取当前 PHP 的 upload_max_filesize、post_max_size，并结合应用上限显示可上传大小。应用上限由 .env 的 SYSTEM_UPDATE_MAX_MB 指定，默认 256 MiB。OpenResty 的 client_max_body_size 在 1Panel 网站配置中设置。

## 文件

php85.zip 为完整运行包，overlay.zip 为程序覆盖包。verification.zip 包含测试、语法检查和版本信息，SHA256SUMS 提供文件校验值。版本更新内容见运行包内 CHANGELOG-PATCH.md。
