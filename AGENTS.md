# AGENTS.md — Laravel 13 / PHP 8.5 升级约束

本文件约束所有 agent 在 `blessing-skin-server-Laravel13` 目录内的行为。
升级任务清单见 `UPGRADE-TASKS.md`，两者必须同步维护。

---

## 1. 目标与基线

- **目标**：Laravel 13.x + PHP 8.5.x。
- **基线**：`blessing-skin-server-patch`（Blessing Skin Server 6.0.10_patch，Laravel 10）。
- **当前版本**：6.0.11_patch（Laravel 13.38.0 + PHP 8.5）。
- **参考**：上游 `blessing-skin-server` 保留在 `D:\WORKspace\NeoSkin\blessing-skin-server`，
  补丁基线保留在 `D:\WORKspace\NeoSkin\blessing-skin-server-patch`。
  **这两个目录是只读参考，禁止修改。**

### 升级路径

必须按 10 → 11 → 12 → 13 逐级推进，不得跳级。

| 步骤 | 依据 |
| --- | --- |
| 10 → 11 | https://v11.x.laravel.wiki/upgrade |
| 11 → 12 | https://v12.x.laravel.wiki/upgrade |
| 12 → 13 | https://www.laravel.wiki/upgrade |

每一级完成后必须可运行（至少 `php artisan --version`、`php artisan route:list`
不报错），再进行下一级。禁止一次性大爆炸式修改。

---

## 2. 环境

| 工具 | 位置 / 命令 |
| --- | --- |
| PHP 8.5.12 | `D:\WORKspace\NeoSkin\php\php.exe` |
| Composer | `php D:\WORKspace\NeoSkin\php\composer.phar` |
| Node.js | `node`（v24） |

- 禁止在系统范围安装 PHP / Composer / 全局 composer 包。
- 禁止修改 `D:\WORKspace\NeoSkin\php\php.ini` 以外的全局配置。
- `composer.json` 中保留 `config.platform.php` 设置，保证构建可复现。

---

## 3. 禁止事项（硬约束）

1. **禁止跳级升级** Laravel。
2. **禁止删除或弱化 patch 分支的功能**。6.0.3 ~ 6.0.11_patch 的以下功能必须保留：
   - 管理员仪表盘入口
   - ZIP 运行包在线更新（含备份、SHA-256 校验、安装确认）
   - 通知与公告管理中心（公告 + 站内信、草稿、发布、编辑、撤回、已读状态）
   - 组件式编辑器（文本、标题、提示框、折叠卡片、分隔线；拖动排序、属性编辑、实时预览）
   - 源码模式（Markdown / HTML，可导入为可视化组件）
   - 统一分页与列表控件样式
   - 更新源 / 插件仓库地址配置与切换
   - PHP 8.5 适配层（上传上限读取、OpenResty / 1Panel 配置）
3. **禁止改变数据库表结构语义**。新增迁移必须是增量的，不得修改或删除既有迁移文件；
   不得回滚任何已发布迁移。
4. **禁止破坏插件 API**。`app/Services/Plugin.php`、`PluginManager.php`、`Hook.php`、
   `app/Events/*`、`config/plugins.php`、`config/menu.php`、`plugins/` 的对外契约必须保持兼容。
5. **禁止改动 `.env` 运行时行为**。新增配置项必须写入 `.env.example` 并提供默认值，
   老 `.env` 缺失时不得报错。
6. **禁止引入与 PHP 8.5 polyfill 冲突的全局函数**。`app/helpers.php` 不得定义
   `array_first` / `array_last` 等同名全局函数；改用 `Illuminate\Support\Arr`。
7. **禁止提交 `vendor/`、`node_modules/`、`.env`、`storage/framework/views/*`**。
8. **禁止在前端引入新构建工具链**（保持 webpack + yarn 1.x）。
9. **禁止使用未记录的 Laravel 内部 API**，禁止依赖命名参数调用框架方法
   （Laravel 不为命名参数提供向后兼容保证）。

---

## 4. 升级纪律

### 4.1 每级升级的标准流程

1. 先更新 `composer.json` 版本约束，运行 `composer update --with-all-dependencies`。
2. 解决依赖冲突（记录在 `UPGRADE-TASKS.md` 对应条目）。
3. 按官方升级指南逐项应用破坏性变更。
4. 运行 `php -l` 对所有改动的 PHP 文件做语法检查。
5. 运行 `php artisan package:discover`、`php artisan route:list`。
6. 运行测试套件（SQLite 内存库）。
7. 更新 `UPGRADE-TASKS.md` 状态后才进入下一级。

### 4.2 第三方包策略

- 优先升级包版本以适配新 Laravel。
- 若包已停止维护且不兼容，按以下优先级处理：
  1. 寻找社区 fork 或替代包；
  2. 在应用层做适配 shim（放在 `app/Support/` 或 `app/Services/`）；
  3. 记录到 `UPGRADE-TASKS.md` 的「阻塞」条目并向用户报告。
- **禁止**在未记录的情况下直接删除一个包并重写其功能。

### 4.3 代码风格

- 遵循仓库既有风格：Laravel 标准 PSR-12 + `.php-cs-fixer.dist.php`。
- 使用 `friendsofphp/php-cs-fixer` 检查，不引入 Pint（除非替换既有工具并记录）。
- 注释与文档使用中文（与 patch 分支一致），代码标识符使用英文。
- 前端资源与翻译文件不得因升级而丢失。

---

## 5. 必须保留的兼容性要点

### 5.1 配置

- `config/app.php` 中所有自定义项（providers、aliases、`locale`、`timezone`）保留。
- `config/patch.php`、`config/updates.php`、`config/options.php`、`config/menu.php`
  为 patch 分支自定义配置，**必须完整保留**。
- 新增的 Laravel 13 配置项（`cache.serializable_classes`、`session.serialization`）
  需评估对生产站点的影响，默认值选择必须记录理由。

### 5.2 缓存与会话

- 升级会导致缓存键前缀与会话 Cookie 名称变化，必须在 `.env.example` 中显式提供
  `CACHE_PREFIX`、`REDIS_PREFIX`、`SESSION_COOKIE`。
- `session.serialization` 若改为 `json` 会使全部用户会话失效，默认保持 `php`，
  除非用户明确接受。

### 5.3 数据库

- 生产环境为 MariaDB。保留 `mysql` 驱动的可用性，同时确认 `mariadb` 驱动可用。
- MariaDB < 11.8.1 不支持 `DELETE ... JOIN` + `ORDER BY`/`LIMIT`，
  相关代码路径必须检查。
- `upsert` 调用必须显式传 `uniqueBy`。

### 5.4 安全

- CSRF 中间件改用 `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`。
  所有测试中的 `withoutMiddleware` 引用同步更新。
- 不得放宽任何既有权限校验（`CheckRole`、`RejectBannedUser`、`EnsureEmailFilled` 等）。

---

## 6. 文档同步要求

每完成一个阶段，必须同步更新：

- `UPGRADE-TASKS.md` — 勾选条目，补充阻塞与决策记录
- `CHANGELOG-PATCH.md` — 追加新版本条目（格式与既有条目一致）
- `BUILD-PHP85.md` — 更新环境与依赖说明
- `PATCH-INSTALL.md` — 更新运行环境与手动更新命令
- `README.md` / `README-zh.md` — 如涉及环境要求变化

---

## 7. 验证清单（合并前必须全部通过）

```sh
php D:\WORKspace\NeoSkin\php\composer.phar validate
php artisan --version
php artisan route:list
php artisan config:cache
php artisan view:clear
php artisan test
yarn install --frozen-lockfile
yarn build
```

任何一项失败都必须记录原因，禁止在失败状态下声明「升级完成」。

---

## 8. 进度报告格式

agent 在完成一个阶段后，必须报告：

1. 已勾选的 `UPGRADE-TASKS.md` 条目编号。
2. 修改的文件清单（按阶段分组）。
3. 依赖版本变化（升级前 → 升级后）。
4. 失败/阻塞项与原因。
5. 下一阶段的待办。
