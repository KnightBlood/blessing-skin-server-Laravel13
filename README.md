# DreamCraft Blessing Skin Server Patch

基于 Blessing Skin Server 6.0.2 开发快照 `52f6fef` 的 PHP 8.5 / Laravel 13 补丁分支，当前版本 **6.0.11_patch**。

> 6.0.11_patch 起运行环境由 Laravel 10.50.3 逐级升级至 **Laravel 13.38.0**（10 → 11 → 12 → 13），
> PHP 最低要求同步提升为 **8.5.0**。逐级升级与决策记录见 `UPGRADE-TASKS.md`。

## 自动检查与发布

工作流和发布产物说明见 [docs/CI-RELEASES.md](docs/CI-RELEASES.md)。

## 修改历史

保留上游完整历史。后续签名提交按 PHP 8.5 适配、6.0.3_patch、6.0.4_patch、6.0.5_patch、6.0.6_patch 顺序重建自已交付版本包，每次在原文件路径更新。打开文件 History 或查看 commit diff 可追踪具体改动。提交日期记录本次重建时间，版本日期见 CHANGELOG-PATCH.md。版本标签留待后续 Actions 发布流程生成。

主要功能包含管理员仪表板入口、ZIP 运行包更新、通知与公告管理、组件式编辑器、站内信及统一分页。上游来源和历史说明见 docs/UPSTREAM.md。

## 开发

环境：PHP 8.5、Composer、Node.js 24、Yarn 1.x。PHP 扩展见 composer.json。
框架为 Laravel 13.38（`config.platform.php` 固定 8.5.0），测试使用 PHPUnit 12。

```sh
composer install
yarn install --frozen-lockfile
php artisan test
yarn build
```

测试配置使用仓库内 .env.testing 的 SQLite 内存数据库。生产环境使用 .env.example 生成 .env 并设置 MariaDB 连接，PHP 数据库扩展为 pdo_mysql。1Panel OpenResty 规则见 docs/1panel-openresty.conf，网站运行目录 public/。

## 构建运行包

```sh
python3 tools/build-php85.py --output /absolute/path/to/new-output
```

可通过 --php、--composer、--yarn 指定可执行文件路径。构建脚本安装锁定依赖、构建前端、复制通讯编辑器资源，并生成 PHP 8.5 ZIP 和 SHA256SUMS。使用已安装依赖时可加 --skip-install。

更新说明见 PATCH-INSTALL.md，各版本更新内容见 CHANGELOG-PATCH.md。原项目作者与 MIT 许可保留在下方和 LICENSE 中。

---

- [简体中文](./README-zh.md)
- **English**

<p align="center"><img src="https://media.githubusercontent.com/media/bs-community/logo/main/logo.png"></p>

<p align="center">
<a href="https://github.com/bs-community/blessing-skin-server/actions"><img alt="GitHub Workflow Status" src="https://img.shields.io/github/actions/workflow/status/bs-community/blessing-skin-server/CI.yml?branch=dev&style=flat-square"></a>
<a href="https://codecov.io/gh/bs-community/blessing-skin-server"><img alt="Codecov" src="https://img.shields.io/codecov/c/github/bs-community/blessing-skin-server?style=flat-square"></a>
<a href="https://github.com/bs-community/blessing-skin-server/releases"><img alt="GitHub release (latest SemVer including pre-releases)" src="https://img.shields.io/github/v/release/bs-community/blessing-skin-server?include_prereleases&style=flat-square"></a>
<a href="https://github.com/bs-community/blessing-skin-server/blob/master/LICENSE"><img alt="GitHub" src="https://img.shields.io/github/license/bs-community/blessing-skin-server?style=flat-square"></a>
<a href="https://discord.com/invite/QAsyEyt"><img alt="Discord" src="https://discord.com/api/guilds/761226550921658380/widget.png"></a>
</p>

Puzzled by losing your custom skins in Minecraft servers runing in offline mode? Now you can easily get them back with the help of Blessing Skin!

Blessing Skin is a web application where you can upload, manage and share your custom skins & capes! Unlike modifying a resource pack, everyone in the game will see the different skins of each other (of course they should register at the same website too).

Blessing Skin is an open-source project written in PHP, which means you can deploy it freely on your own web server!

## Features

- A fully functional skin hosting service
- Multiple player names can be owned by one user on the website
- Share your skins and capes online with skin library!
- Easy-to-use
  - Visual page for user/player/texture management
  - Detailed option pages
  - Many tweaks for a better UI/UX
- Security
  - Support many secure password hash algorithms
  - Email verification for registration
  - Score system for preventing evil requests
- Incredibly extensible
  - Plenty of plugins available
  - Integration with Authme/Discuz (available as plugin)
  - Support custom Yggdrasil API authentication (available as plugin)

## Requirements

Blessing Skin has only a few system requirements. In most cases, these PHP extensions are already enabled.

- Web server with URL rewriting enabled (Nginx or Apache)
- PHP >= 8.5.0 (Laravel 13.38)
- PHP Extensions
  - OpenSSL >= 1.1.1 (TLS 1.3)
  - PDO
  - Mbstring
  - Tokenizer
  - GD
  - XML
  - Ctype
  - JSON
  - fileinfo
  - zip
  - Imagick

## Quick Install

Please read [Installation Guide](https://blessing.netlify.app/en/setup.html).

## Plugin System

Blessing Skin provides an elegant and powerful plugin system, and you can attach plenty of functions and customization to your site via installing plugins.

## Build From Source

Please refer to [Manual Build](https://blessing.netlify.app/build.html).

## Internationalization

Blessing Skin supports multiple languages, while currently supporting English, Simplified Chinese and Spanish.

If you are willing to contribute your translation, welcome to join [our Crowdin project](https://crowdin.com/project/blessing-skin).

## Report Bugs

Read [FAQ](https://blessing.netlify.app/faq.html) and double check if your situation doesn't suit any case mentioned there before reporting.

When reporting a problem, please attach your log file (located at `storage/logs/laravel.log`) and the information of your server where the error occured on. You should also read this [guide](https://blessing.netlify.app/report.html) before reporting a problem.

## Related Links

- [User Manual](https://blessing.netlify.app/en/)
- [Plugins Development Documentation](https://bs-plugin.netlify.app/)

## Copyright & License

MIT License

Copyright (c) 2016-present The Blessing Skin Team
