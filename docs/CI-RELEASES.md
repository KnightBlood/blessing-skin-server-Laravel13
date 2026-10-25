# 自动检查与发布

推送 main、向 main 提交 Pull Request，以及手动运行 Actions 时执行 PHP 8.5 语法检查、发布工具测试、后端测试、前端类型检查和测试，并构建完整生产运行包。生产依赖通过 composer.lock 安装，前端资源通过 Yarn 构建。

检查通过后，main 的运行自动发布当前版本。版本号同时维护在 package.json 与 config/app.php，构建时校验两者一致。Release 标题为 Release <版本号>，首次发布创建 v<版本号> 标签；同版本的正式 Release 已存在时保持原发布内容。后续升级时提高这两个文件中的版本号，并在 CHANGELOG-PATCH.md 增加更新内容。

每次发布包含一个 blessing-skin-server\_<版本>\_php85.zip，以及 manifest.json、SHA256SUMS。ZIP 含生产 vendor 与前端资源，可在管理后台“检查更新”上传。流水线使用站点实际的 UpdatePackage::prepare 对 ZIP 进行暂存验证。

发布先创建草稿，上传全部附件并核对附件名称和大小后转为正式 Release；中断后可重新运行相同提交的失败任务，补齐该草稿。正式 Release 保持不变。PR 运行仅检查和生成 Actions 构建产物，发布任务只对 main 开放。

工作流使用 GitHub 自动提供的 GITHUB_TOKEN；检查任务仅有 contents:read，发布任务有 contents:write。无需配置个人访问令牌或上传本机 SSH 私钥。第三方 Actions 固定到提交 SHA。

## 客户端更新源

主站 GitHub Release 备用地址：`https://github.com/DreamCraft-Tech/blessing-skin-server-patch/releases/latest/download/update.json`。使用原版 spec 2 协议，包含最低 PHP 版本、最新主站版本、完整运行包 URL 和 SHA-256。客户端校验后进入确认安装。

插件 GitHub Release 备用地址：`https://github.com/DreamCraft-Tech/blessing-skin-plugins-patch/releases/latest/download/registry_{lang}.json`。使用原版 version 1 / packages 协议，提供 en 与 zh_CN 的插件名称、描述、依赖与各自 ZIP 下载地址。索引和安装包都是公开 Release 附件，可直接通过 HTTPS 获取。

在“站点配置”的“更新与插件仓库”卡片保存地址后生效；留空时使用 config/app.php 与 config/plugins.php 的默认地址，UPDATE_SOURCE 和 PLUGINS_REGISTRY 环境变量可覆盖默认值。发布任务在全部 ZIP 校验后生成 JSON，并与所有安装包一起上传后公开。

卡片中的“切换到 bs-community 官方源”和“切换到 DreamCraft-Tech 第三方修补源”同时保存主站与插件地址。官方预设沿用上游 dev 分支的 [主站配置](https://github.com/bs-community/blessing-skin-server/blob/dev/config/app.php) 与 [插件仓库配置](https://github.com/bs-community/blessing-skin-server/blob/dev/config/plugins.php)。

保存或切换预设时同步 .env 中的 UPDATE_SOURCE 和 PLUGINS_REGISTRY，只修改这两个字段并保持其他配置与文件权限，清除 Laravel 配置缓存。清空地址会移除对应环境变量并采用默认第三方修补源。写入失败时显示表单错误，不保存新的面板配置。

## web-publish 与 GitHub Pages

Release 完成后下载已正式发布的附件并校验，再写入同仓库的 web-publish 分支。根目录提供最新 JSON、manifest.json、SHA256SUMS 与下载索引；releases/<标签>/ 保留各版本 ZIP 和对应 JSON。现有版本 ZIP 保持原字节及 SHA-256，历史下载路径继续保留。相同版本重试不创建重复分支提交。

主站 Pages：`https://skin.blessing.dreamcraft.eu.org/update.json`。
插件 Pages：`https://plugin.blessing.dreamcraft.eu.org/registry_{lang}.json`。
Pages JSON 中所有 ZIP 链接均指向对应 Pages 域名。GitHub Release 仍保留原有附件与 GitHub 下载索引。

仓库 Pages 设置使用 GitHub Actions：发布任务将 web-publish 的实际文件树打成 Pages artifact，再由独立 pages 任务部署。这样不依赖 GITHUB_TOKEN 推送触发分支构建。仅 main 正式发布路径可部署；PR 仅检查。发布任务使用同仓库 GITHUB_TOKEN 的 contents:write；部署任务仅有 pages:write、id-token:write，无需新增个人令牌。PAGES_BASE_URL 仓库变量可覆盖默认域名。

GitHub Pages 站点总大小上限为 1 GB，版本目录应按站点容量定期归档；完整历史安装包始终保留于 Releases。
