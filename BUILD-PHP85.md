# Blessing Skin Server Laravel 13 Patch

基于 `DreamCraft-Tech/blessing-skin-server-patch` 分支（Blessing Skin Server 6.0.10_patch，Laravel 10）升级到 PHP 8.5 / Laravel 13 的补丁分支，当前版本 **6.0.11_patch**。

> 运行环境已从 Laravel 10.50.3 逐级升级到 **Laravel 13.38.0**（10 → 11 → 12 → 13），
> PHP 最低要求同步提升为 **8.5.0**（不再支持 8.1–8.4）。

## 修改历史

保留上游完整历史。后续签名提交按 PHP 8.5 适配、6.0.3_patch、6.0.4_patch、6.0.5_patch、6.0.6_patch 顺序重建自已交付版本包，每次在原文件路径更新。打开文件 History 或查看 commit diff 可追踪具体改动。提交日期记录本次重建时间，版本日期见 CHANGELOG-PATCH.md。版本标签留待后续 Actions 发布流程生成。6.0.11_patch 起运行环境升级为 Laravel 13 / PHP 8.5，逐级升级记录见 UPGRADE-TASKS.md。

主要功能包含管理员仪表板入口、ZIP 运行包更新、通知与公告管理、组件式编辑器、站内信及统一分页。上游来源和历史说明见 docs/UPSTREAM.md。

## 开发

环境：PHP **8.5.x**、Composer 2.x、Node.js 24、Yarn 1.x。PHP 扩展见 composer.json，
其中 `gd`、`zip`、`pdo`、`openssl`、`mbstring`、`tokenizer`、`ctype`、`xml`、`json`、
`fileinfo` 为必需项，`imagick` 为启动检查项（`bootstrap/chkenv.php`）。

```sh
composer install
yarn install --frozen-lockfile
php artisan test
yarn build
```

`composer.json` 固定 `config.platform.php = 8.5.0`，并用 `^8.5` 约束 PHP；
`laravel/framework` 为 `^13.0`，`phpunit/phpunit` 为 `^12.0`。

测试配置使用仓库内 .env.testing 的 SQLite 内存数据库。生产环境使用 .env.example 生成 .env 并设置 MariaDB 连接，PHP 数据库扩展为 pdo_mysql。1Panel OpenResty 规则见 docs/1panel-openresty.conf，网站运行目录 public/。

### 升级相关注意事项

- **缓存序列化白名单**：`config/cache.php` 的 `serializable_classes` 使用显式白名单
  （`Illuminate\Http\Response`、`Symfony\Component\HttpFoundation\ResponseHeaderBag`），
  用于纹理预览与头像的 `Cache::remember` 缓存。**不可改为 `false`**，否则缓存命中后
  会返回 `__PHP_Incomplete_Class` 并导致 500。插件若在缓存中保存自定义对象，
  需自行把对应类加入该白名单。
- **会话序列化**：`config/session.php` 显式锁定 `serialization => php`。改为 `json`
  会使全部现存用户会话失效。
- **缓存键前缀 / 会话 Cookie**：`.env.example` 显式提供
  `CACHE_PREFIX=blessing_skin_cache_`、`REDIS_PREFIX=blessing_skin_database_`、
  `SESSION_COOKIE=BS_SESSION`，与升级前默认值一致；老 `.env` 不含这些键时行为不变。
- **不支持 `Illuminate\Support\Facades\Image`**：为避免 L13 内置 Image 服务提供者
  劫持 `image` 绑定（它要求 `intervention/image` v4），`config/app.php` 已排除
  `Illuminate\Image\ImageServiceProvider`。`Storage::image()`、`Request::image()`
  与 `Image` facade 不可用，图片处理请继续使用 `Intervention\Image\ImageManager`
  （`app('image')`）。
- **CSRF 中间件**：已改用 `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`，
  旧类名 `VerifyCsrfToken` / `ValidateCsrfToken` 不再可用。
- **Passport 13**：沿用旧 `oauth_clients` 表结构，已通过幂等迁移
  `2026_10_25_000001_hash_oauth_client_secrets.php` 对既有客户端密钥做一次性哈希。
  升级后首次进入 OAuth 客户端管理页会显示一次明文密钥，请及时保存。

## 构建运行包

```sh
python3 tools/build-php85.py --output /absolute/path/to/new-output
```

可通过 --php、--composer、--yarn 指定可执行文件路径。构建脚本安装锁定依赖、构建前端、复制通讯编辑器资源，并生成 PHP 8.5 ZIP 和 SHA256SUMS。使用已安装依赖时可加 --skip-install。

构建脚本的额外要求：

- 必须运行在 PHP 8.5（脚本首行断言 `PHP_VERSION_ID >= 80500 && < 80600`）。
- `package.json` 与 `config/app.php` 的 `version` 必须一致，否则脚本断言失败。
- `manifest.json` 的 `commit` 取自 `GITHUB_SHA`；本地无 `.git` 时需显式设置该环境变量，
  例如 `$env:GITHUB_SHA="local-build"`。
- 系统未全局安装 Yarn 1.x 时，用 `--yarn` 指向一个包装脚本
  （Windows：`@echo off` + `npx --yes yarn@1.22.22 %*` 的 `.cmd`）。

构建输出示例：

```
blessing-skin-server_6.0.11_patch_php85.zip
manifest.json
SHA256SUMS
```

前端构建可能出现两条 `autoprefixer: Replace color-adjust to print-color-adjust`
警告，来自 `admin-lte` 官方压缩 CSS，属既有第三方噪音，不影响构建与产物。

更新说明见 PATCH-INSTALL.md，各版本更新内容见 CHANGELOG-PATCH.md。原项目作者与 MIT 许可保留在下方和 LICENSE 中。
