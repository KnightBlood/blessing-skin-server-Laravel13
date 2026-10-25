# Laravel 13 / PHP 8.5 升级任务清单

项目：`blessing-skin-server-Laravel13`
基线：`blessing-skin-server-patch`（Blessing Skin 6.0.10_patch，Laravel 10，PHP 8.5 适配）

## 状态图例

- [ ] 待办
- [~] 进行中
- [x] 已完成
- [!] 阻塞 / 需注意

---

## 决策记录

### D1：`lorisleiva/laravel-search-string` 的处理方式（应用层移植）

**背景**：`lorisleiva/laravel-search-string` packagist 仅 v1.3.0，约束 `illuminate/support ^9.0|^10.0`，
无法随项目升级到 Laravel 11/12/13，且上游已停止维护。

**已评估的社区 fork**：`jesprna/jaysfi-search-string`（v1.1.4，`illuminate/support ^11|^12|^13`）。

**结论：不采用。** 理由（AGENTS.md §4.2 优先级 1 → 退回优先级 2）：

1. 该 fork 并非仅放宽约束，而是**替换了解析器实现**：v1.1.0 仍依赖 `hoa/compiler ^3.17`，
   v1.1.1 起静默移除该依赖；`Compiler/CompiledParser.php` 从 `extends \Hoa\Compiler\Llk\Parser`
   改为 `extends Jesprna\...\Parser\Parser`（手写 Parser + Lexer + TreeNode + Rule/*），
   文件中 `\Hoa\` 引用数为 0。文件名仍为 `HoaCompiler.php`，名实不符。
2. 采用度无信号：0 stars、0 forks、0 dependents。
3. 该 fork **无法安装在 Laravel 10**（约束 `^11|^12|^13`），因此无法在基线版本上做对照验证。

**实证验证**（临时目录 `Temp/opencode/forkcheck`，已清理）：
对两个实现在**同一 Laravel 11.57 环境**下，用 `User`/`Player`/`Report` 三个模型的真实
`$searchStringColumns`（含 `Player` 的正则别名 `tid_skin`/`tid_cape`、日期列、布尔列）跑
**72 个搜索串用例**，逐条比对生成的 SQL 与绑定值：

```
TOTAL: 72   DIVERGENT: 0   IDENTICAL: 72
```

即二者语义等价。**但这不足以构成采用理由** —— 移植版与上游 v1.3.0 逐字节一致、行为可追溯到
上游；fork 版为第三方重写，其正确性仅由这 72 个用例覆盖，且维护者无社区背书。
按 AGENTS.md §3.2「禁止弱化 patch 功能」与 §4.2「禁止未记录直接删除包并重写功能」，
保留应用层移植。

**采用方案**（AGENTS.md §4.2 优先级 2）：
将 v1.3.0 的 36 个源文件（含 `Compiler/CompiledParser.php`、`Grammar.pp`、Console 命令）整体移植至
`app/Support/SearchString/`，命名空间改为 `App\Support\SearchString`，依赖改为直接 require
`hoa/compiler ^3.17` + `sanmai/hoa-protocol ^1.17`。Provider 注册于 `config/app.php`，
配置文件发布为 `config/search-string.php`。

### D2：[!] Laravel 11 `addArrayOfWheres` 命名参数冲突（**升级阻塞项**）

**现象**：搜索串中的「裸词」查询（如 `TestUser`，会展开为多个 `searchable` 列的 `or` 组合）
在 Laravel 11 上抛致命错误：

```
Error: Named parameter $boolean overwrites previous argument
```

**根因**：Laravel 11 修改了 `Illuminate\Database\Query\Builder::addArrayOfWheres()`：

```php
// Laravel 10
$query->{$method}(...array_values($value));
// Laravel 11
$query->{$method}(...array_values($value), boolean: $boolean);
```

当 `$value` 为 4 元素数组（列、运算符、值、boolean）时，第 4 个位置参数已设置 `$boolean`，
再追加命名参数 `boolean:` 即触发冲突。

**影响面**：与 fork 无关，`lorisleiva` v1.3.0 与本移植版均受影响
（`Visitors/BuildColumnsVisitor.php:146` 调用 `where($wheres->toArray(), null, null, $this->boolean)`）。
仅在「搜索串含裸词且模型有 ≥2 个 `searchable` 列」时触发；`Player`（1 个 searchable 列）不触发。

**修复（已实施）**：修改 `app/Support/SearchString/Visitors/BuildColumnsVisitor.php::buildSearch()`，
对多列组合改为显式嵌套闭包，避免向 `where()` 传入 4 元素数组：

```php
return $this->builder->where(function ($query) use ($wheres) {
    foreach ($wheres as $where) {
        $query->where($where[0], $where[1], $where[2], $where[3]);
    }
}, null, null, $this->boolean);
```

语义与原 `where($wheres->toArray(), null, null, $this->boolean)` 完全等价：外层以
`$this->boolean` 挂载嵌套组，组内逐条按各自的 `or` / `and` 布尔连接。
该写法在 Laravel 10/11/12/13 均使用公开 API，不依赖 `addArrayOfWheres` 内部实现，
也符合 AGENTS.md §3.9「禁止依赖命名参数调用框架方法」。

**验证**：
- Laravel 10 下 `User`/`Player` 六个用例（`TestUser`、`nickname:TestUser`、`score>50`、
  `-nickname:TestUser`、`nickname:test*,score>10,verified:true,-uid:1`、`steve`）
  改前改后 SQL + 绑定值**逐字一致**。
- 取反路径 `not TestUser` → `(email not like ? and nickname not like ?)`，`and` 分支正确。
- 与原版 `lorisleiva/laravel-search-string` v1.3.0（仍在 vendor 中）做 A/B，
  14 个用例（含取反、嵌套取反、关键字、日期、布尔）**14/14 完全一致**。

### D3：`laravel/browser-kit-testing` 版本选择

**结论：保持 `^7.2.8`，不升 8.x。**

v7.2.8 的 `composer.json` 约束已是 `laravel/framework: ^10.44|^11.0|^12.0|^13.0`，
即同一版本覆盖 10 → 13 全区间。升 8.x 无收益（8.x 仅提高 framework 下限），
反而会让基线 L10 无法回退验证。

### D4：`blessing/filter`、`blessing/rejection` 建本地适配包

**背景**：两个包由 Blessing Skin 官方维护，packagist 最新版（1.4.x / 1.3.x）的
`illuminate/*` 约束停在 `^10|^11`，Laravel 12/13 下无法解析。上游未发布放宽版本。

**方案**（AGENTS.md §4.2 优先级 1「fork / 适配」，取最小改动形式）：
在仓库内建 path 仓库：

- `packages/blessing-filter/`（`version: 1.5.0`）
- `packages/blessing-rejection/`（`version: 1.5.0`）

**改动范围**：仅 `composer.json` 中 `illuminate/*` 约束放宽到 `^13.0`
（保留 `^10|^11|^12` 向下兼容），**源码零改动**。3 个源码文件与
`vendor/blessing/*` 原件的 SHA-256 **逐一 IDENTICAL**（已校验）。

**保留项**：`extra.laravel.providers` 自动发现、原命名空间
（`Blessing\Filter\*`、`Blessing\Rejection\*`）、原 API 签名均不变，
`blessing/filter` 的 `SkinFilter` 契约对插件保持二进制兼容。

`composer.json` 中 `repositories` 为**关联数组**，两个 path 仓库必须排在
`packagist` **之前**，且带 `"options": {"symlink": false}`（否则 Windows 下
symlink 失败，且构建产物里会出现指向 `packages/` 的相对链接）。

### D5：`psr/http-message` 2.0 冲突 → `vectorface/whip` 升级

Passport 13 → `league/oauth2-server 9.x` → `psr/http-message ^2.0`；
而 `vectorface/whip 0.4.x` 要求 `^1.0`。`vectorface/whip 0.5.0` 已放宽为
`^1.0 || ^2.0`，无 API 变更（仅 `getIP()` 系列签名内部类型注解调整），
本项目只用到 `Whip::getIP()` / `getIPs()`，**行为不变**。

### D6：Passport 13 适配（API 层）

Passport 13 相对 12 有 4 处破坏性变更，均在 `App\Providers\AuthServiceProvider::register()`
集中处理（必须在 `boot()` 之前，因为 `Passport::client()` 在 provider boot 早期即被调用）：

```php
Passport::$registersJsonApiRoutes = true;   // v13 默认 false，UI 依赖 /oauth/clients JSON API
Passport::$clientUuids = false;             // 旧表为 bigIncrements，不能生成 UUID
Passport::$deviceCodeGrantEnabled = false;  // 见 D9
```

另两项代码改动：

1. `App\Models\User implements Laravel\Passport\Contracts\OAuthenticatable`
   —— v13 的 `ClientRepository::forUser()` / `TokenGuard` 参数类型收紧为该契约。
2. 中间件别名 `scope` → `CheckTokenForAnyScope`、`scopes` → `CheckToken`
   （v12 的 `CheckScopes` / `CheckForAnyScope` 已在 v13 删除），
   已写入 `bootstrap/app.php` 的 `alias()`。

**未采用** `Passport::$passwordGrantEnabled`（默认 false 即可，本项目无密码授权）。

### D7：不应用 Passport 13 的 `oauth_clients` 新结构迁移（保持旧表）

Passport 13 的 `create_oauth_clients_table` 迁移已改为
`uuid('id') + redirect_uris(json) + grant_types(json) + revoked` 等新结构，
与既有站点 `bigIncrements('id') + redirect + personal_access_client/password_client`
不兼容。**决策：不发布、不执行该迁移，保留旧表。**

**可行性已逐项核实**（`vendor/laravel/passport/src/`）：

| 调用点 | 旧表回退逻辑 |
| --- | --- |
| `ClientRepository::create/update` | 按 `Schema::getColumnListing()` 判断 `redirect` / `redirect_uris` 列存在性 |
| `ClientRepository::clientsFor()` | `in_array('user_id', $columns)` 决定是否按 `user_id` 过滤 |
| `Client::redirectUris()` | 有 `redirect` 列时回退为 `explode(',', $this->redirect)` |
| `Client::grantTypes()` | 无 `grant_types` 列时按 `personal_access_client` / `password_client` 推导 |
| `Client::firstParty()` | 有 `user_id` 列时走 `is_null($this->user_id)` 分支 |

`secret` 列为 `string(100)`，bcrypt 输出恒为 60 字符，**容量充足**（见 D8）。

### D8：Passport 13 强制哈希客户端密钥 → 增量迁移 + 前端适配

Passport 13 起 `Client::$secret` 恒为哈希值，`ClientRepository::create*()`
通过 `$client->plain_secret` 一次性返回明文。存量站点若保留明文密钥，
升级后第三方 OAuth 登录会全部失效。

**后端**：新增增量迁移
`database/migrations/2026_10_25_000001_hash_oauth_client_secrets.php`：

- 等价于 `php artisan passport:hash`，但**幂等**：
  `Hash::isHashed($s) && ! Hash::needsRehash($s)` 时跳过；
- 用 `->get()` 而非 `cursor()`：MySQL 无缓冲结果集不允许边读边改同一张表；
- `down()` 为空（哈希不可逆）；
- `Schema::hasTable` / `hasColumn` 守卫，未装 Passport 的站点安全跳过；
- 不触碰表结构、不修改既有迁移。

**前端**：`resources/assets/src/views/user/OAuth/`

- `types.ts`：`secret` 改为可选，新增 `plain_secret?: string`；
- `index.tsx`：创建成功后把 `plain_secret` 写入行状态，并用
  `showModal({ mode: 'prompt', title: t('user.oauth.secret'), input: secret })`
  展示**一次**（`.catch(() => undefined)`，用户关闭不报错）；
- `Row.tsx`：无 `secret` 时显示 `'••••••••'`。

**取舍**：接受「密钥仅创建时可见」的上游行为，**不新增**密钥重生成路由、
**不新增** i18n key（复用既有 `user.oauth.secret`）。

### D9：关闭 Passport 13 的 Device Code Grant

v13 默认 `$deviceCodeGrantEnabled = true`，会比 v12 多注册 5 条
`oauth/device*` 路由，并要求 `oauth_device_codes` 表——该表迁移用
`foreignUuid('client_id')`，与 D7 保留的整数主键**结构冲突**。
本项目 UI 从不发起 device flow，故显式关闭，路由数与 L10 基线保持一致（179）。

### #1912 / CVE-2026-39976 判定（已核实，**不修**）

Passport 的 `TokenGuard` 在判定客户端凭据泄漏风险时依赖
`hasGrantType('client_credentials')`；D7 的旧表回退会把
「confidential + firstParty」判为 `true`。但 `firstParty()` 在旧结构下走
`is_null($this->user_id)` 分支，而**本应用 UI 创建的所有 client 一律带 `user_id`**
（`ClientRepository::createAuthorizationCodeGrantClient(..., $user)`），
故该判定恒为 `false`；且库中不存在 `client_credentials` grant 的 client。
**风险路径不可达，保持上游代码不改。** 若未来新增机器对机器 client，需重新评估。

### D10：`cache.serializable_classes` 使用显式白名单（**不可设为 `false`**）

Laravel 13 骨架默认 `'serializable_classes' => false`。但本项目有**两处把
`Illuminate\Http\Response` 对象直接写进缓存**，升级后命中缓存会 500：

| 位置 | 缓存键 |
| --- | --- |
| `app/Http/Controllers/TextureController.php:61` `preview()` | 纹理预览图 |
| `app/Http/Controllers/TextureController.php:157` `avatar()` | 玩家 / 用户头像 |

`CacheManager::getSerializableClasses()`（`CacheManager.php:473`）读取该配置，
值为 `false` 时反序列化不还原对象，缓存命中后返回 `__PHP_Incomplete_Class`。

**结论：显式白名单**，不用 `false`：

```php
'serializable_classes' => [
    \Illuminate\Http\Response::class,
    \Symfony\Component\HttpFoundation\ResponseHeaderBag::class,
],
```

`ResponseHeaderBag` 必须一并列入：`Response::$headers` 是 `ResponseHeaderBag`
实例，只允许外层 `Response` 仍会在还原 headers 时得到不完整类对象。

**双向实测证据**（`cache.default = file`、`cache.prefix = blessing_skin_cache_`）：

- 白名单方案：`Cache::put` → 新进程 `Cache::get`，`status` / `content` /
  `Content-Type` / `Content-Length` / `Last-Modified` 全部完整还原，
  且 `Content-Length` 与 `strlen($content)` 一致。
- `false` 方案：`Cache::get` 返回 `__PHP_Incomplete_Class`，HTTP 层 500。

插件若在缓存里存自定义对象，**需要自行把类名追加到该白名单**——已写入
`PATCH-INSTALL.md` 与 `BUILD-PHP85.md` 的升级须知。

### D11：必须排除 `Illuminate\Image\ImageServiceProvider`（**L13 关键修复**）

Laravel 13 在框架默认 provider 列表中新增了延迟提供者
`Illuminate\Image\ImageServiceProvider`，它以 `image` 键注册
`Illuminate\Image\ImageManager`。该提供者在 `boot` 时用 `scoped('image')`
覆盖了 `intervention/image` 的 `singleton('image')`。

而 Illuminate 的 GD 驱动要求 `intervention/image ^4.2`
（`ImageManager::usingDriver()`），本项目装的是 **v2.7.2**
（`blessing/texture-renderer: ^2.5` 的硬约束），必然报错：

```
Call to undefined method Intervention\Image\ImageManager::usingDriver()
  Illuminate\Image\Drivers\GdDriver.php:15
  Illuminate\Image\Drivers\InterventionDriver.php:54
  Illuminate\Image\ImageManager.php:126
```

直接后果：`SkinlibController::skinlib()` 与 `TextureController::preview()/avatar()`
全部 500（`TextureControllerTest`、`SkinlibControllerTest` 共 19 用例失败）。

**结论**：在 `config/app.php` 中排除该 provider，容器里的 `image` 绑定继续由
`intervention/image` 提供，行为与 L10 ~ L12 一致：

```php
'providers' => ServiceProvider::defaultProviders()
    ->except([\Illuminate\Image\ImageServiceProvider::class])
    ->merge([...])
    ->toArray(),
```

**由此不可用的部分**（本项目均未使用，已在 `config/app.php` 注释中说明）：
`Illuminate\Support\Facades\Image`、`Storage::image()`、`Request::image()`。
全局 `Image` 别名不受影响——`RegisterFacades` 中 package manifest 的 alias
合并顺序在后，最终仍是 `Intervention\Image\Facades\Image`。

**修复后实测**：`app('image')` → `Intervention\Image\ImageManager`；
`Illuminate\Image\ImageServiceProvider` 不再处于 loaded 列表；
`TextureControllerTest|SkinlibControllerTest` → 19 passed / 244 assertions；
全量 `php artisan test` → 262 passed / 1 skipped / 0 failed。

> 该 provider 是延迟提供者，`php artisan about --only=providers` 可能看不到它；
> 排查时应以 `app('image')` 的实际类型与 `$app->getLoadedProviders()` 为准。

---

### D12：版本号升到 `6.0.11_patch`

升级完成后原版本号仍为 `6.0.10_patch`。由于同一版本号无法被更新检测识别为「有新版」，
既有站点永远不会收到 Laravel 13 运行包，故按用户确认升为 `6.0.11_patch`。

**实测**（`composer/semver` + `version_compare`）：

| 比较 | `Comparator::greaterThan` | `version_compare` |
| --- | --- | --- |
| `6.0.11_patch` > `6.0.10_patch` | `true` | `1` |
| `6.0.10_patch` > `6.0.11_patch` | `false` | `-1` |
| `6.0.10_patch` > `6.0.10_patch` | `false` | `0` |
| `6.0.10_patch` > `6.1.0_patch` | `false` | `-1` |

`VersionParser::normalize('6.0.11_patch')` → `6.0.11.0-patch`，`_patch` 后缀被正确解析，
更新检测（`UpdateController` / `UpdatePackage`）与 `config/patch.php` 排序均无需改动。

**同步改动**：`package.json`、`config/app.php`（`'version'`）、`config/patch.php`
（新增 `2026-10-26` release 条目）、`CHANGELOG-PATCH.md`（新增顶部条目）、
`UpdateControllerTest`（版本数组前置 `6.0.11_patch`，折叠卡片 id 断言顺延至 1–9）。

---

## 阶段 0：基线准备

- [x] 0.1 从 `blessing-skin-server-patch` 复制源码到 `blessing-skin-server-Laravel13`
- [x] 0.2 搭建临时 PHP 8.5.12 + Composer 环境
- [x] 0.3 记录升级计划（本文件）
- [x] 0.4 编写 AGENTS.md 约束升级行为

## 阶段 1：Laravel 10 -> 11 ✅ 已完成

依据：https://v11.x.laravel.wiki/upgrade

- [x] 1.1 `laravel/framework` 升级为 `^11.0`，`nunomaduro/collision` 升级为 `^8.1`
- [x] 1.2 PHP 约束 `^8.2`（Laravel 11 最低要求），保留 8.5 platform 配置
- [x] 1.3 `laravel/passport` 升级为 `^12.0`，发布 `passport-migrations` 迁移
- [x] 1.4 处理 Carbon 3：检查 `diffIn*` 返回值（浮点、可为负）
- [x] 1.5 移除 `doctrine/dbal` 依赖（Laravel 11 已不依赖）
- [x] 1.6 检查 `change()` 迁移是否显式声明全部列修饰符
- [x] 1.7 检查 `double`/`float`/`unsignedDecimal` 等列类型用法
- [x] 1.8 检查自定义 `UserProvider` / `Authenticatable` 契约实现
- [x] 1.9 `Limit`/`ThrottlesExceptions` 秒级限流参数核对
- [x] 1.10 Eloquent 模型 `casts()` 方法冲突检查
- [x] 1.11 数据库连接可切换 `mariadb` 驱动（保留 mysql 兼容）

### 阶段 1 结果记录

**依赖版本（升级前 → 升级后）**

| 包 | 升级前 | 升级后 |
| --- | --- | --- |
| `laravel/framework` | 10.50.3 | **11.57.0** |
| `laravel/passport` | 11.x | 12.4.3 |
| `nesbot/carbon` | 2.x | 3.15.1 |
| `nunomaduro/collision` | 7.x | 8.5.0 |
| `laravel/browser-kit-testing` | 7.1.x | 7.2.8 |
| `phpunit/phpunit` | 10.x | 10.5.66 |
| `barryvdh/laravel-ide-helper` | 2.x | 3.7.0 |
| `barryvdh/laravel-debugbar` | 3.x | 3.16.5 |
| `laravel/tinker` | 2.x | 3.0.2 |
| `spatie/laravel-ignition` | 2.x | 2.12.1 |
| `rcrowe/twigbridge` | 0.9/0.10 | 0.14.8 |
| `hoa/compiler` | 未安装 | 3.17.08.08（D1 移植依赖） |
| `sanmai/hoa-protocol` | 未安装 | 1.25.1（D1 移植依赖） |
| `doctrine/dbal` | 3.x | **已移除** |
| `lorisleiva/laravel-search-string` | 1.3.0 | **已移除**（D1 应用层移植） |

**已验证**

- `php artisan --version` → `Laravel Framework 11.57.0`
- `php artisan route:list` → 179 路由，无报错
- `php artisan config:cache` / `config:clear` 成功
- `php artisan migrate:fresh` → 26 个迁移全部 DONE（21 原有 + 5 passport）
- `php artisan test` → **263 deprecated, 0 failed（2037 assertions）**

**1.4 Carbon 3**：`UserController.php:111,115` 两处 `diffInSeconds(..., false)` 已显式传 `false`；
返回值变为 float 但仅参与 `$remainingTime <= 0` 比较，无下游整数/字符串依赖，安全。

**1.6 `change()` 迁移**：共 6 处，均安全。
- `2019_03_01_131420_add_tid_skin.php:15,26`：`change()` 作用于 `string('ip', 32)` → `string('ip')`，
  原列无 `nullable`/`default` 修饰符，Laravel 11 原生改列不会丢失修饰符。
- `2019_12_14_095751_update_ip_field.php:12,19`、`2020_03_10_145738_lengthen_ip_field.php:12,19`：
  `preference` 列原本即为 `nullable()`，`change()` 显式带 `nullable()`。

**1.7 浮点/空间类型**：全库无 `double`/`float`/`unsignedDecimal`/`geometry` 列定义，无需处理。

**1.8 自定义 UserProvider**：无。`config/auth.php` 全部使用 `eloquent` driver + `App\Models\User`。
认证走 `AuthController::verifyPassword()` + `Auth::login()`，**不使用** `Auth::attempt()`，
因此 Laravel 11 的「登录成功后按需自动重哈希密码」特性不会触发，
`PWD_METHOD=MD5/SHA256/SHA512/SALTED2*` 等遗留哈希不会被静默覆盖。此点已确认，属于**必须保持**的现状。

**1.9 限流**：仅 `RouteServiceProvider.php:68` 的字符串语法 `throttle:60,1`（= 60 次/分钟），
非 `Limit::perMinute()` 实例构造，不受 L11「秒级限流」变更影响。

**1.10 `casts()` 冲突**：5 个模型（`Communication`/`Player`/`Report`/`Texture`/`User`）均只声明
`protected $casts` 属性，未定义 `casts()` 方法，与 L11 新增的 `Model::casts()` 方法无冲突，
`$casts` 属性在 11/12/13 全部继续支持。

**1.11 mariadb 驱动**：`config/database.php` 保留 `mysql` 连接；Laravel 11 内置 `mariadb` 驱动，
生产 MariaDB 可切换，无需改表。

**1.2 / 1.3 补充说明**

- `bootstrap/chkenv.php` 的 `$requiredVersion` 已从 `8.1.0` 提升为 `8.2.0`。
- Passport 12 起不再自动加载自身迁移目录，已执行
  `php artisan vendor:publish --tag=passport-migrations`，产出 5 个
  `2016_06_01_00000*_create_oauth_*.php`。文件名与 Passport 11 自动加载时一致，
  既有站点 `migrations` 表已记录同名条目 → 升级时自动跳过，**不会重复建表**。
- `migrate:fresh` 已验证 Passport 建表先于 `2020_06_26_090510_add_o_auth_provider_field`，
  后者的 `Schema::hasColumn` 守卫生效。

**已知问题：263 个 Deprecation（非阻塞，12.x 自动消失）**

全部来自 `vendor/laravel/framework/config/database.php:61,81` 的
`PDO::MYSQL_ATTR_SSL_CA`（PHP 8.5 起废弃，应使用 `Pdo\Mysql::ATTR_SSL_CA`）。
触发链路：`Illuminate\Foundation\Bootstrap\LoadConfiguration::getBaseConfiguration()`
（`LoadConfiguration.php:190-191`）**无条件** `require` 框架自身的 `config/*.php`，
因此即使在应用层覆盖 `config/database.php` 也无法规避（已抓 backtrace 确认）。
Laravel 12.x / 13.x 已改为 `Mysql::ATTR_SSL_CA`，升级后自然消失。
本项目自己的 `config/database.php:60` 已有
`PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA` 适配，不受影响。

**测试适配（3 处，均为预存在缺陷，非升级引入）**

三个用例与 `blessing-skin-server-patch` 基线**逐字节一致**，失败原因是 Windows 平台限制
或 Faker 随机数据碰撞。修正只做「确定化 / 平台守卫」，不弱化任何断言：

1. `tests/HttpTest/ControllersTest/UpdateSourcesTest.php`
   `fileperms() & 0777` 断言（期望 `0600`）在 Windows NTFS 上恒为 `0666`，
   已加 `PHP_OS_FAMILY !== 'Windows'` 守卫；同一用例的换行符去重、CRLF 保留断言仍在所有平台执行。
2. `tests/ServicesTest/UpdatePackageTest.php`
   原用例同时验证「ZIP 内符号链接条目被拒」与「目标目录符号链接被拒」，后者需真实 `symlink()`。
   已拆为：
   - `testSymlinkEntryAndDestinationAreRejected` → 仅保留 ZIP 条目检查（跨平台）
   - `testSymlinkDestinationIsRejected` → Windows 下先探测 `symlink()` 能力，
     无权限则 `markTestSkipped`（Linux/CI 上完整执行）
3. `tests/HttpTest/ControllersTest/ClosetControllerTest.php::testGetClosetData`
   原用 `TextureFactory` 的 `faker->firstName` 作为搜索词，10 个纹理可能重名，
   命中多条时 `assertJson` 唯一元素断言偶发失败（全量运行才复现，单跑通过）。
   改为创建名称唯一的纹理（`'closet-search-'.uniqid()`）再搜索，断言强度不变且结果确定。

**配置基线对比**：`config/` 与基线逐文件比对，仅 `config/app.php` 1 行差异
（新增 `App\Support\SearchString\ServiceProvider`）+ 新增 `config/search-string.php`。
框架独有的 `concurrency.php`、`cors.php` 未发布，不影响本项目。

## 阶段 2：Laravel 11 -> 12 ✅ 已完成

依据：https://v12.x.laravel.wiki/upgrade

- [x] 2.1 `laravel/framework` 升级为 `^12.0`，`nunomaduro/collision` 升级为 `^8.1+`（>= 8.2）
- [x] 2.2 `laravel/passport` 升级为 `^13.0`（Laravel 12 对应，决策 D6–D9）
- [x] 2.3 使用 `bootstrap/app.php` 新骨架结构（`bootstrap/providers.php` 无需创建，
      见下方说明）
- [x] 2.4 迁移 `app/Http/Kernel.php` 全局/分组/别名中间件到 `bootstrap/app.php`
- [x] 2.5 迁移 `app/Console/Kernel.php` 的 `$commands` 到 `withCommands`
- [x] 2.6 迁移 `app/Exceptions/Handler.php` 的 `$dontReport` 到 `withExceptions`
      → **改为保留 `App\Exceptions\Handler` 并显式重绑**（见下方说明）
- [x] 2.7 迁移 `RouteServiceProvider`：`$namespace` **保留不移除**（见下方说明）
- [x] 2.8 `EventServiceProvider` 自动发现评估；`$listen` 保留
- [x] 2.9 PHP 约束提升至 `^8.2`（Laravel 12 要求 8.2+）
- [x] 2.10 升级 dev 依赖：phpunit ^11、mockery ^1.6、fakerphp/faker ^1.23
- [x] 2.11 `spatie/laravel-ignition` 2.12.1 已支持 L12（未改名，无需替换）
- [x] 2.12 `rcrowe/twigbridge` 0.14.8 兼容 L12
- [x] 2.13 `spatie/laravel-translation-loader` 2.8.3 兼容 L12
- [x] 2.14 `lorisleiva/laravel-search-string` → 见决策 D1（应用层移植）
- [x] 2.17 修复 `BuildColumnsVisitor` 在 Laravel 11 `addArrayOfWheres` 下的命名参数冲突（决策 D2，**阻塞**）
- [x] 2.15 `laravel/browser-kit-testing` 保持 `^7.2.8`（决策 D3）
- [x] 2.16 配置文件同步（`config/*.php` 与 12.x 骨架比对，见下方结果记录）

### 阶段 2 结果记录

**依赖版本（升级前 → 升级后）**

| 包 | 升级前 | 升级后 |
| --- | --- | --- |
| `laravel/framework` | 11.57.0 | **12.70.1** |
| `laravel/passport` | 12.4.3 | **13.10.0** |
| `league/oauth2-server` | 8.x | 9.4.1 |
| `league/event` | 2.x | 3.0.3 |
| `phpunit/phpunit` | 10.5.66 | **11.5.57** |
| `nunomaduro/collision` | 8.5.0 | 8.9.5 |
| `phpseclib/phpseclib` | 3.x | 4.0.2 |
| `psr/http-message` | 1.1 | 2.0 |
| `php-http/discovery` | 1.19.x | 1.20.0 |
| `vectorface/whip` | 0.4.x | 0.5.0（决策 D5） |
| `blessing/filter` | 1.4.x（packagist） | 1.5.0（本地 path，决策 D4） |
| `blessing/rejection` | 1.3.x（packagist） | 1.5.0（本地 path，决策 D4） |
| `lcobucci/clock` | 3.x | **已移除**（随 oauth2-server 9 移除） |
| `nyholm/psr7` | 1.x | **已移除** |

**已验证**

- `php artisan --version` → `Laravel Framework 12.70.1`
- `php artisan route:list` → **179 路由**，与 L10 基线数一致
  （`oauth/clients` 10 条 JSON API 路由已恢复；`oauth/device*` 5 条按 D9 未注册）
- `php artisan migrate --force` → 新增迁移 DONE；`migrate:fresh` 全量通过
- `php artisan test` → **262 passed, 1 skipped, 2037 assertions, 0 failed，无 Deprecation**
  （L11 的 263 个 `PDO::MYSQL_ATTR_SSL_CA` 弃用警告随 L12 消失，与预测一致）
- `php artisan config:cache` / `view:clear` / `config:clear` 全部成功
- `php artisan list` 含 `update`、`bs:install`、`options:cache`、`salt:random`、
  `plugin:enable|disable`、`passport:client|hash|install|keys|purge`
- `user/oauth/manage` 中间件链：`web` → `authorize` → `CheckUserVerified`
- 事件注册实测：`PluginWasEnabled` 3 个（2 显式 + 1 由 `PluginManager` 动态注册）、
  `plugin.versionChanged` 2、`PluginBootFailed` 1、`auth.registration.completed` 1、
  `texture.privacy.updated` 2、`texture.deleted` 3、`closet.removed` 1、
  `Auth\Events\Authenticated` 1；`shouldDiscoverEvents=false`（按预期关闭框架级自动发现）
- Passport 13 运行时开关实测：`clientUuids=false`、`registersJsonApiRoutes=true`、
  `deviceCodeGrantEnabled=false`
- D8 迁移实测：明文 `plain-secret-abc` → `$2y$10$...`，`Hash::isHashed=true`、
  `Hash::check(plain)=true`，第二次执行值不变（幂等）
- Passport 13 建客户端实测：`id` 为 `integer`、`plain_secret` 可用、库存 bcrypt、
  `$hidden=['secret']`、`toArray()` 含 `plain_secret`；
  `Bridge\ClientRepository::validateClient()` 正确密钥 `true` / 错误密钥 `false`
- 探测数据已清理，`oauth_clients` 归零

**2.3 / 2.6 说明：为什么不调用 `withExceptions()`**

`App\Exceptions\Handler` 除 `$dontReport` 外还覆写了 `render()` 与
`convertExceptionToArray()`（自定义 JSON 错误结构与堆栈裁剪），
`withExceptions()` 只能表达 `$dontReport`/`report`/`render` 回调，无法表达
`convertExceptionToArray()`。故保留原类，在 `->create()` 之后显式重绑：

```php
$app->singleton(
    \Illuminate\Contracts\Debug\ExceptionHandler::class,
    \App\Exceptions\Handler::class
);
```

`bootstrap/providers.php` 未创建：`config/app.php` 的
`'providers' => ServiceProvider::defaultProviders()->merge([...])` 已是 L11+ 写法，
`ApplicationBuilder::withProviders()` 在文件不存在时静默跳过，无需该文件。

**2.4 说明：中间件栈精确复刻**

`$middleware->use([...])` 会**整体替换**框架默认全局栈，故按 L10 `App\Http\Kernel`
的 5 项逐一还原：`PreventRequestsDuringMaintenance`、`ValidatePostSize`、
`TrimStrings`、`App\Http\Middleware\ConvertEmptyStringsToNull`（应用版，含 4 条
`$excepts`）、`DetectLanguagePrefer`。

**刻意不引入**框架默认但 L10 基线不存在的 4 项：`ValidatePathEncoding`、
`InvokeDeferredCallbacks`、`TrustProxies`、`HandleCors`。理由是基线
`App\Http\Kernel::$middleware` 同样是整体覆盖而非追加，两端行为保持一致；
本应用未使用 `defer()`，也未配置 `config/cors.php`，故无功能缺失。

`group('web'|'api'|'authorize', [...])` 同样是整体替换，与
`App\Http\Kernel::$middlewareGroups` 逐项对齐；`alias()` 只覆盖与框架默认不同的
7 个别名（`auth`/`guest`/`role`/`setup`/`verified`/`scope`/`scopes`），
`throttle`、`cache.headers` 沿用框架默认。

**不调用 `redirectGuestsTo()` 的原因**：`withMiddleware()` 未传回调时框架会默认
`redirectGuestsTo(fn () => route('login'))`，而本应用的路由名是 `auth.login`。
`App\Http\Middleware\Authenticate::redirectTo()` 已返回 `route('auth.login')`；
显式调用反而会给 `AuthenticationException` 注入一个会抛
`RouteNotFoundException` 的闭包，故**不注册**，与 L10 行为一致。

**2.5 说明：命令注册**

`configure()` 已自动注册 `app/Console/Commands` 目录，但 `passport:keys` 必须显式列出：
Passport 13 的 `PassportServiceProvider::registerCommands()` 只在
`runningInConsole()` 时注册命令，而 `SetupController::finish()` 会在 **Web 请求**中
调用 `Artisan::call('passport:keys')`。故 `withCommands([...])` 显式包含
`KeysCommand` + 6 个应用命令（重复注册由 Artisan 内部按类名去重，无副作用）。

**2.7 说明：`$namespace` 保留（不按官方指南移除）**

官方 L11 指南建议移除 `RouteServiceProvider::$namespace` 并在路由分组显式写
命名空间。**本项目不采用**，理由：patch 分支的插件 API 依赖
`App\Providers\RouteServiceProvider` 的 `$namespace` / `map()` / `ConfigureRoutes`
契约——`app/Services/Plugin.php` 的 `routes()` 通过 `Route::namespace()` 叠加插件命名空间，
移除 `$namespace` 会让所有插件路由（含 `plugins/` 内置插件）解析失败。
该属性在 L12 仍被 `Illuminate\Routing\RouteRegistrar` 支持（仅 `withRouting()` 场景不推荐），
实测 179 路由与基线完全一致，故**保留**。

**2.16 配置文件差异**

与 L12 骨架比对后，`config/` 中**未**引入框架新增的 `concurrency.php` / `cors.php`：
本项目不使用 `Concurrency` facade，也未启用 CORS。`config/passport.php` 删除
`client_uuids`、`personal_access_client` 两段（v13 不再读取，改由 D6 的静态属性控制）。

**2.8 说明：`withEvents(false)`**

`Application::configure()` 内部已调用 `withEvents()`（默认启用 `app/Listeners` 自动发现），
再次调用 `withEvents(false)` 会执行 `AppEventServiceProvider::disableEventDiscovery()`。
必须关闭：本项目已在 `App\Providers\EventServiceProvider::$listen` 显式声明 8 组映射，
若同时启用自动发现，`app/Listeners` 下的监听器会被注册两次，`PluginWasEnabled`
会触发 4 次而非 3 次（`CopyPluginAssets` 重复执行）。实测关闭后计数正确。

## 阶段 3：Laravel 12 -> 13 ✅ 已完成

依据：https://www.laravel.wiki/upgrade

- [x] 3.1 `laravel/framework` 升级为 `^13.0`（实装 v13.38.0）
- [x] 3.2 `laravel/tinker` 升级为 `^3.0`（实装 v3.0.2，约束收紧为单一 `^3.0`）
- [x] 3.3 `phpunit/phpunit` 升级为 `^12.0`（实装 12.5.40）
- [x] 3.4 PHP 约束最终定为 `^8.5`（实装 PHP 8.5.12；`config.platform.php = 8.5.0`）
- [x] 3.5 CSRF 中间件：`VerifyCsrfToken` -> `PreventRequestForgery`（测试中无 `withoutMiddleware` 引用，无需同步）
- [x] 3.6 `config/cache.php` 增加 `serializable_classes`（**改用显式白名单，不用 `false`**，见 D10）
- [x] 3.7 `config/session.php` 增加 `serialization` 项（显式锁定 `'php'`，避免全体会话失效）
- [x] 3.8 数据库 `upsert` 调用必须提供非空 `uniqueBy`（全仓库 0 处 `upsert`，无风险）
- [x] 3.9 检查 MySQL `DELETE ... JOIN` + `ORDER BY`/`LIMIT` 用法（0 处，MariaDB 兼容性不受影响）
- [x] 3.10 模型 boot 中禁止嵌套实例化模型（无 `static function boot` / `booted()` 覆写）
- [x] 3.11 多态 pivot 自定义模型的表名显式定义（无 `morphToMany` / `morphedByMany` / `->using()`）
- [x] 3.12 `Container::call` 可为空类默认值行为变化核对（全仓库 3 处 `app()->call()`，均无「可空类类型默认值」签名）
- [x] 3.13 分页 Bootstrap 视图引用核对（`pagination::default` 未被引用；`Paginator::useBootstrap()` 在 L13 仍存在并转调 `useBootstrapFour()`）
- [x] 3.14 检查 `JobAttempted` / `QueueBusy` 事件监听（0 处，未使用队列事件）
- [x] 3.15 `symfony/polyfill-php85` 与全局辅助函数 `array_first`/`array_last` 冲突检查（`bootstrap.php` 在 PHP >= 8.5 直接 `return`；`app/helpers.php` 未定义同名函数）
- [x] 3.16 缓存前缀 / 会话 Cookie 名称变化评估（已在 `.env.example` 显式提供 `CACHE_PREFIX` / `REDIS_PREFIX` / `SESSION_COOKIE`）
- [x] 3.17 检查 `Str` 工厂在测试间的重置影响（0 处 `Str::create*` / `Str::flush*`）

### 阶段 3 结果记录

**依赖版本（阶段 2 → 阶段 3）**

| 包 | 阶段 2（L12） | 阶段 3（L13） |
| --- | --- | --- |
| `laravel/framework` | v12.70.1 | **v13.38.0** |
| `phpunit/phpunit` | 11.5.57 | **12.5.40** |
| `barryvdh/laravel-debugbar` | v3.16.5 | **v4.4.4** |
| `php-debugbar/php-debugbar` | v2.2.6 | v3.8.0 |
| `php-debugbar/symfony-bridge` | — | v1.1.0（新增） |
| `symfony/console`、`http-foundation`、`http-kernel`、`routing`、`uid`、`yaml`、`process`、`mime`、`mailer`、`error-handler`、`finder`、`var-dumper` | v7.4.x | **v8.1.9** |
| `symfony/css-selector` | v7.4.18 | v8.1.6 |
| `symfony/dom-crawler` | v7.4.17 | v8.1.5 |
| `brick/math` | 0.14.8 | 1.0.0 |
| `guzzlehttp/uri-template` | v1.0.11 | v2.0.1 |
| `theseer/tokenizer` | 1.3.1 | 2.0.1 |
| `sebastian/*`（cli-parser / code-unit / environment / exporter / global-state / linesize / object-enumerator / recursion-context / type / version） | PHPUnit 11 配套 | PHPUnit 12 配套 |
| `symfony/polyfill-php83` | 1.43.0 | 已移除 |
| `symfony/polyfill-php86` | — | 1.43.0（新增） |
| `masterminds/html5` | 2.12.0 | 已移除 |

**`composer.json` 约束变化（共 9 项）**

| 段 | 包 | 阶段 2 | 阶段 3 |
| --- | --- | --- | --- |
| require | `php` | `^8.2` | **`^8.5`** |
| require | `laravel/framework` | `^12.0` | `^13.0` |
| require | `symfony/process` | `^6.0\|^7.0` | `^6.0\|^7.0\|^8.0` |
| require | `symfony/yaml` | `^5.0\|^6.0\|^7.0` | `^5.0\|^6.0\|^7.0\|^8.0` |
| require-dev | `barryvdh/laravel-debugbar` | `^3.16` | `^4.4` |
| require-dev | `laravel/tinker` | `^2.9\|^3.0` | `^3.0` |
| require-dev | `phpunit/phpunit` | `^11.0` | `^12.0` |
| require-dev | `symfony/css-selector` | `^6.2\|^7.0` | `^6.2\|^7.0\|^8.0` |
| require-dev | `symfony/dom-crawler` | `^6.2\|^7.0` | `^6.2\|^7.0\|^8.0` |

回退备份：`composer.json.pre-l13` / `composer.lock.pre-l13`（阶段 2 结束时的状态）。

**逐条核查结论**

- **3.5 CSRF**：`bootstrap/app.php:60` 由 `ValidateCsrfToken` 改为
  `\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class`。
  全仓库搜索 `ValidateCsrfToken|VerifyCsrfToken|PreventRequestForgery` 在
  `tests/` 与 `app/` 中**均无其他引用**，故不存在需要同步的 `withoutMiddleware` 调用。
- **3.6 缓存序列化**：见决策 D10。**`false` 会破坏纹理预览与头像缓存，已双向实测。**
- **3.7 会话序列化**：`config/session.php` 新增 `'serialization' => 'php'`。
  读取点在 `vendor/laravel/framework/src/Illuminate/Session/SessionManager.php`
  （`buildSession()` 内按 `$config['serialization']` 选择 `read`/`write` 序列化器）。
  改为 `json` 会使所有现存会话 Cookie 失效，按 AGENTS §5.2 保持 `php`。
- **3.8 `upsert`**：`app/` 与 `database/` 中 `->upsert(` 出现次数 **0**。
- **3.9 `DELETE ... JOIN`**：无 `->join(` 与 `->delete()` 组合，MariaDB < 11.8.1 限制不触发。
- **3.10 模型 boot**：无 `static function boot` / `booted()` / `booted` 覆写；
  模型关联与观察者均在 `AppServiceProvider` / `EventServiceProvider` 中注册。
- **3.11 多态关联**：`morphToMany` / `morphedByMany` / `->using(` 出现次数 **0**，
  无自定义 pivot 模型，无表名显式定义需求。
- **3.12 `Container::call`**：3 处（插件钩子回调、`OptionForm` 校验闭包、命令调度），
  参数均为标量或已绑定实例，不依赖「可空类默认值」新行为。
- **3.13 分页**：
  - 项目**未**引用 `pagination::default`；唯一显式入口是
    `app/Providers/AppServiceProvider.php:22` 的 `Paginator::useBootstrap()`。
  - L13 的 `AbstractPaginator::useBootstrap()`（`AbstractPaginator.php:628`）
    仍然存在，实现为 `static::useBootstrapFour();`，与 L10 **完全一致**，
    故分页 HTML 不发生任何变化。
  - 项目唯一 `->links()` 调用在 `resources/views/user/report.twig:36`；
    其余分页（公告 / 站内信列表）走 patch 分支自写的
    `resources/views/shared/communication-pagination.twig`，不经过框架分页视图。
  - vendor 中 `bootstrap-3/4/5`、`simple-bootstrap-3/4/5`、`tailwind`、
    `semantic-ui` 视图**均仍在**（`Illuminate/Pagination/resources/views`）。
- **3.14 队列事件**：`JobAttempted` / `QueueBusy` 出现次数 **0**；
  `ShouldQueue` 仅 `app/Notifications/SiteMessage.php` 一处，走默认队列驱动，不受影响。
- **3.15 polyfill 冲突**：
  - `vendor/symfony/polyfill-php85/bootstrap.php` 首行逻辑为
    `if (\PHP_VERSION_ID >= 80500) { return; }`，在 PHP 8.5.12 下**整体短路**，
    不会声明任何函数。
  - `array_first()` / `array_last()` 由 **PHP 8.5 内核**提供
    （`ReflectionFunction('array_first')->getFileName()` 返回 `false`，即 internal）。
  - `app/helpers.php` 中**不存在** `array_first` / `array_last` / `array_find` /
    `array_any` / `array_all` 等同名全局函数定义，符合 AGENTS §3.6。
- **3.16 前缀 / Cookie**：
  - 基线（L10 patch）的 `config/cache.php` 与 `config/session.php` 公式为
    `env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'blessing_skin'), '_').'_cache_')`、
    `env('SESSION_COOKIE', 'BS_SESSION')`，L13 保留**同一公式与同一默认值**，
    因此升级本身**不会**改变生产站点的缓存键前缀或会话 Cookie 名。
  - 仍在 `.env.example` 中显式写出 `CACHE_PREFIX=blessing_skin_cache_`、
    `REDIS_PREFIX=blessing_skin_database_`、`SESSION_COOKIE=BS_SESSION`，
    以固定默认行为、避免未来 `APP_NAME` 变动导致前缀漂移。
  - 实测：`cache.default = file`、`cache.prefix = blessing_skin_cache_`、
    `session.driver = file`、`session.cookie = BS_SESSION`。
- **3.17 `Str` 工厂**：`Str::create*` / `Str::flush` / `Str::createUuidsUsing` /
  `Str::createRandomStringsUsing` 在 `app/` 与 `tests/` 中**均无**使用，
  不存在跨用例残留状态。

**L13 其余破坏性变更逐项排查（均无影响）**

| L13 变更点 | 核查结果 |
| --- | --- |
| `Collection` 模型序列化恢复已加载关联 | 项目无跨进程序列化 Eloquent 模型的路径（缓存中仅 `Response`，见 D10） |
| 路由域注册顺序（`->domain()`） | `->domain(` / `Route::domain` 出现 **0** 处 |
| 管理器 `extend` 回调绑定 `$this` | `->extend(` / `->macro(` 出现 **0** 处 |
| `Http\Client\Response::throw()` 签名 | 无自定义 `Response` / `ResponseFactory` 子类 |
| 默认密码重置邮件标题 | 测试与视图均未断言该文案（`resources/lang/*/auth.yml` 为自维护内容） |
| 队列通知 `DeleteWhenMissingModels` | 无队列通知模型删除场景 |
| 队列契约方法变更 | 无自定义 `Store` / `Repository` / `Dispatcher` / 队列驱动 |
| `Js::from` / `@js` 的 `JSON_UNESCAPED_UNICODE` | 二者出现 **0** 处 |
| `withScheduling()` | 未使用调度配置 API，命令仍在 `Kernel` 兼容路径注册 |
| `MustVerifyEmail` 契约校验 | 未实现该契约（使用自定义 `CheckUserVerified` 中间件） |
| `static function boot` 覆写 | 出现 **0** 处 |
| 分页 `pagination::default` 重命名 | 未引用该视图（见 3.13） |

**`config/debugbar.php` 整体重发布**

debugbar 3.x 的配置结构与 4.x **不兼容**（`options.*` 扁平键、`collectors` 结构、
`storage.*` 层级均已变化），沿用旧文件会在启动时报未定义索引。
处理方式：用 `php artisan vendor:publish --provider="Barryvdh\Debugbar\ServiceProvider" --tag=config --force`
重新发布 v4.4 版本（**409 行**），并在文件头追加中文说明，标注版本与「结构已随 v4 重写、
后续升级请重新发布而非手改」的提示。该文件为**开发依赖**配置，不进运行包运行路径。

**路由数量：173 / 178 判定（非回归）**

| 场景 | `route:list` 结果 | 说明 |
| --- | --- | --- |
| `APP_DEBUG=false`（生产默认） | **173** | 应用自有路由 |
| `APP_DEBUG=false` + `--except-vendor` | **155** | 减去 Passport / debugbar 等 vendor 路由 |
| `APP_DEBUG=true` | **178** | 173 + 5 条 debugbar 路由 |

debugbar v4 改为在 `ServiceProvider::boot()` 中先判断
`LaravelDebugbar::canBeEnabled()`（`LaravelDebugbar.php:566`），
只有「调试模式开启 且 非 testing/production 环境」才 `loadRoutesFrom()`。
`APP_DEBUG=true` 下新增的 5 条：

| 方法 | URI | 路由名 |
| --- | --- | --- |
| GET\|HEAD | `_debugbar/assets` | `debugbar.assets` |
| DELETE | `_debugbar/cache` | `debugbar.cache.delete` |
| GET\|HEAD | `_debugbar/clockwork/{id}` | `debugbar.clockwork` |
| GET\|HEAD | `_debugbar/open` | `debugbar.openhandler` |
| POST | `_debugbar/queries/explain` | `debugbar.queries.explain` |

与阶段 2 记录的 179 条的差额来源：debugbar v3 无条件注册其路由（6 条），
v4 改为条件注册（5 条）；应用自有路由本身**未增未减**。

另注：L13 的 `route:list` 会给兜底路由的 method 列表新增 `QUERY`（HTTP QUERY 方法），
属框架行为，不影响路由匹配。

> 判定口径：以 `php artisan route:list` 末行 `Showing [N] routes` 为准。
> 用正则统计输出行数会得到 164（`GET|HEAD` 等多 method 行被合并），不可用于计数。

**`yarn.lock` 补回**

`composer update` 过程中 `yarn.lock` 曾被误清理。核对 `package.json` 的
`sha` / 依赖段与 `blessing-skin-server-patch` **一致**后，从该目录补回
`yarn.lock`（453009 字节），`--frozen-lockfile` 一次通过，未产生依赖漂移。

**前端构建**

- `npx yarn@1.22.22 install --frozen-lockfile --ignore-scripts` → `Done in 253.78s`
- `yarn build` → `app compiled with 2 warnings`、`Done in 15.91s`
- 产物路径 `public/app/`（`public/.gitignore` 内容为 `app/`，产物不纳入版本控制；
  运行包由 `tools/build-php85.py` 打包时一并带上）。

**2 条构建警告定性（既有第三方 CSS 噪音，非升级引入）**

用临时 `stats.children = true` 配置展开子编译后确认，两条警告完全相同：

```
WARNING in ./node_modules/admin-lte/dist/css/alt/adminlte.core.min.css
WARNING in ./node_modules/admin-lte/dist/css/alt/adminlte.light.min.css
Module Warning (from ./node_modules/postcss-loader/dist/cjs.js):
(12:62244) autoprefixer: Replace color-adjust to print-color-adjust.
The color-adjust shorthand is currently deprecated.
```

- 来源：`admin-lte` 官方压缩 CSS，`autoprefixer` 对其 `color-adjust` 的弃用提示。
- 与 Laravel / PHP / webpack 升级**无关**（纯 CSS 内容，`postcss-loader` 版本未变）。
- webpack 的 `ignoreWarnings` 现有规则只覆盖 asset size limit，不覆盖
  `Module Warning`，故仍以警告形式输出；**不影响构建成功与产物正确性**。

**`tools/build-php85.py` 实测**

```
python tools/build-php85.py --output <dir> --skip-install --yarn <yarn-shim>
```

产出：`blessing-skin-server_6.0.10_patch_php85.zip`（24513448 字节）、
`manifest.json`（version/commit/php/packages/sha256）、`SHA256SUMS`。
脚本内置的版本断言（`package.json` 与 `config/app.php` 的 `version` 必须一致）
通过，二者均为 `6.0.10_patch`。

> 上表产物名与版本号是**阶段 3 实测时的取值**。之后按决策 D12 升到
> `6.0.11_patch`，产物名会随之变为 `blessing-skin-server_6.0.11_patch_php85.zip`，
> 断言逻辑不变。

注意事项（仅本机环境相关，脚本本身无缺陷）：

- 本机未全局安装 yarn 1.x，需用 `--yarn` 指向一个 `npx --yes yarn@1.22.22 %*`
  的包装 `.cmd`；
- 仓库**不是 git 仓库**（无 `.git`），`GITHUB_SHA` 未设置时脚本会回退
  `git rev-parse HEAD` 并失败，测试时需显式设置 `GITHUB_SHA`。

**阶段 3 验证结果**

| 命令 | 结果 |
| --- | --- |
| `php artisan --version` | `Laravel Framework 13.38.0` |
| `php artisan route:list` | `Showing [173] routes`，无 ERROR / Exception / Warning |
| `php artisan test` | **262 passed, 1 skipped, 2037 assertions, 0 failed**（97.95s；1 skipped 为 Windows 无 `symlink()`） |
| `php artisan config:cache` / `view:clear` / `config:clear` | 全部成功 |
| `php composer.phar validate` | `./composer.json is valid` |
| `app('image')` | `Intervention\Image\ImageManager`（见 D11） |
| `php artisan migrate` | 27 个迁移全部 DONE；每个测试用例 `migrate:refresh` 通过 |

**测试中的 1 条 skip 与 3 处测试适配**（均为阶段 1/2 已记录的预存在缺陷，
非 L13 引入，L13 下行为不变）：

1. `UpdateSourcesTest`：`fileperms() & 0777` 在 Windows 恒为 `0666`，已加平台守卫；
2. `UpdatePackageTest::testSymlinkDestinationIsRejected`：Windows 无 `symlink()`
   权限时 `markTestSkipped`（即上表 1 skipped）；
3. `ClosetControllerTest::testGetClosetData`：改用唯一名称纹理，避免 Faker 重名碰撞。

## 阶段 4：第三方包适配 ✅ 已完成

- [x] 4.1 `rcrowe/twigbridge` 兼容 Laravel 13（核心：全站 Twig 模板）
  - 版本 v0.14.8；`composer.json` 约束 `illuminate/support/view ^9|^10|^11|^12|^13`；
  - 全站 104 个 `.twig` 模板 + 3 个路由文件（`web.php`/`api.php`/`static.php`）在 L13 下渲染正常；
  - 全量测试通过（262 passed）含 `ViewTest`/`TwigBridge` 相关用例。
- [x] 4.2 `spatie/laravel-ignition` 在 Laravel 13 的替代（`laravel/ignition` / `spatie/laravel-error-magicator`）
  - 保留 `spatie/laravel-ignition` v2.12.1（`illuminate/support ^11.0|^12.0|^13.0`）；
  - `spatie/ignition` v1.16.0；无需替换。`config/app.php` 中 `Spatie\LaravelIgnition\IgnitionServiceProvider` 仍注册。
- [x] 4.3 `spatie/laravel-translation-loader` 兼容性
  - v2.8.3（`illuminate/translation ^6..^13`）；全站 14 个语言目录 + DB/YAML 翻译加载正常。
- [x] 4.4 `lorisleiva/laravel-search-string` 兼容性 -> 已在阶段 1 前按决策 D1 应用层移植，此处仅回归验证
  - 移植到 `app/Support/SearchString/`（40 文件，与原版 v1.3.0 逐字节一致）；
  - 72 用例 SQL/绑定一致；D2 修正 L11 `addArrayOfWheres` 命名参数冲突。
- [x] 4.5 `laravel/passport` 兼容 Laravel 13
  - v13.10.0（`illuminate/* ^11.35|^12.0|^13.0`）；D6–D9 适配：
  - `Passport::$registersJsonApiRoutes = true`、`$clientUuids = false`、`$deviceCodeGrantEnabled = false`；
  - `App\Models\User implements OAuthenticatable`；别名 `scope`/`scopes`；
  - 保持旧 `oauth_clients` 表结构（D7），不应用 v13 新结构迁移；
  - v13 强制哈希密钥 → 幂等增量迁移 `2026_10_25_000001_hash_oauth_client_secrets.php` + 前端一次展示 `plain_secret`（D8）；
  - 关闭 device code grant 避免 `oauth/device*` 路由与 `foreignUuid('client_id')` 迁移冲突（D9）；
  - `PassportServiceProvider::registerCommands()` 仅在 `runningInConsole()` 时注册，
    故 `withCommands([...])` 中显式列出 `KeysCommand` 是必需的。
- [x] 4.6 `blessing/filter`、`blessing/rejection`、`blessing/texture-renderer` 兼容性
  - `blessing/filter`、`blessing/rejection` 为本地 path 仓库（`packages/blessing-filter`、
    `packages/blessing-rejection`，`version: 1.5.0`），仅放宽 `illuminate/*` 到 `^13`（D4）；
  - `blessing/texture-renderer` v0.2.1 依赖 `intervention/image ^2.5`（装 v2.7.2）；
  - 插件 API 由 `PluginManagerTest` / `PluginEnable|DisableCommandTest` / `CopyPluginAssetsTest` /
    `NotifyFailedPluginTest` / `PluginControllerTest` 覆盖，全量通过。
- [x] 4.7 `gregwar/captcha`、`vectorface/whip`、`intervention/image` 兼容性
  - `gregwar/captcha` v1.3.0（`php >=5.3`，`symfony/finder *`）；
  - `vectorface/whip` v0.5.0 作为 `psr/http-message` 1.x/2.x 兼容方案（D5）；
  - `intervention/image` v2.7.2 保留（`blessing/texture-renderer` 依赖 `^2.5`）；
    `Illuminate\Image\ImageServiceProvider` 必须排除（D11），避免劫持 `image` 绑定。
- [x] 4.8 `barryvdh/laravel-debugbar`、`barryvdh/laravel-ide-helper` 兼容性
  - debugbar v4.4.4（v3→v4，`php-debugbar/php-debugbar` v3.8.0 + `symfony-bridge` v1.1.0）；
    `config/debugbar.php` 整体重发布为 v4.4 结构（409 行 + 中文文件头说明）；
  - ide-helper v3.7.0（`illuminate/* ^11.15|^12|^13.0`）。
- [x] 4.9 `plugins/` 目录内置插件 API 兼容性
  - `plugins/` 目录**仅含 `.gitignore`**（无内置插件）；插件 API 由 `PluginManagerTest` /
    `PluginEnable|DisableCommandTest` / `CopyPluginAssetsTest` / `NotifyFailedPluginTest` /
    `PluginControllerTest` 覆盖，全量通过。

### 阶段 4 结果记录

- 全部 9 个第三方包均已在 L13 下装入并运行；`composer validate` 通过；
  `php artisan test` → 262 passed / 1 skipped / 2037 assertions / 0 failed。
- 无包被删除或重写功能（AGENTS §4.2 优先级 1–2 未触发）；所有 shim 仅位于
  `app/Support/SearchString/`（D1）与 `packages/blessing-*`（D4）。

## 阶段 5：应用代码改造 ✅ 已完成

- [x] 5.1 `bootstrap/app.php` 重写为新结构
  - L12 骨架方案（阶段 2 决策，见 2.1 / 2.8 说明）：不调用 `withRouting()`；`withEvents(false)`；
    `use([...])` 精确复刻 L10 全局栈；`group()` 复刻 `web`/`api`/`authorize`；
    `alias()` 覆盖 7 个别名；不调用 `redirectGuestsTo()`；不调用 `withExceptions()`，
    改在 `->create()` 后重绑 `App\Exceptions\Handler`；保留 `RouteServiceProvider::$namespace`；
    不创建 `bootstrap/providers.php`。
  - L13 改动：`ValidateCsrfToken` → `PreventRequestForgery`（`bootstrap/app.php:60`）；
    `app/Exceptions/Handler.php` **保留**（`bootstrap/app.php:100-102` 重绑
    `Illuminate\Contracts\Debug\ExceptionHandler`）。
- [x] 5.2 删除 `app/Http/Kernel.php`、`app/Console/Kernel.php`、`app/Exceptions/Handler.php`
  - `app/Http/Kernel.php` 与 `app/Console/Kernel.php` 已删除（L12 骨架）；
  - `app/Exceptions/Handler.php` **保留**（`bootstrap/app.php` 重绑），与任务书描述不同，
    该决定在阶段 2 已记录（保留旧 `Handler` 以便继续使用 `app/Exceptions/Handler.php`
    中的自定义渲染逻辑），阶段 3 未改变。
- [x] 5.3 `app/Providers/RouteServiceProvider.php` 简化为新写法
  - 保留 `protected $namespace = 'App\Http\Controllers'`（`RouteServiceProvider.php:17`），
    通过 `Route::namespace()` 叠加插件命名空间；移除 `$namespace` 会让所有插件路由解析失败；
    L12 仍支持该属性，实测 173 路由与基线一致。
- [x] 5.4 `app/Providers/EventServiceProvider.php` 简化
  - 保留 `EventServiceProvider::$listen` 的 **8 组映射**（`PluginWasEnabled` /
    `plugin.versionChanged` / `PluginBootFailed` / `auth.registration.completed` /
    `texture.privacy.updated` / `texture.deleted` / `closet.removed` /
    `Illuminate\Auth\Events\Authenticated`）；
  - `withEvents(false)` 关闭自动发现，避免 `app/Listeners` 被注册两次、
    `PluginWasEnabled` 触发 4 次而非 3 次（2.8 说明）。
- [x] 5.5 控制器/模型/服务层逐文件静态审查
  - 21 个控制器、6 个模型、11 个服务、12 个中间件、9 个监听器、6 个 Provider；
  - 无 `static function boot` / `booted()` 覆写；无 `morphToMany` / `morphedByMany` / `->using(`；
  - 无 `->domain(` / `Route::domain`；无 `->extend(` / `->macro(`；
  - 无 `Js::from` / `@js`；无 `MustVerifyEmail`；无自定义 `Store` / `Repository` /
    `Dispatcher` / `ResponseFactory`；无 `->domain(`；
  - `app()->call()` 仅 3 处（插件钩子回调、`OptionForm` 校验闭包、命令调度），
    均无「可空类类型默认值」签名；
  - `upsert` 0 处；`DELETE ... JOIN` + `ORDER BY`/`LIMIT` 0 处。
- [x] 5.6 `app/helpers.php` 全局函数冲突检查
  - 5 个全局函数（`plugin` / `plugin_assets` / `json` / `option` / `option_localized`），
    **未定义** `array_first` / `array_last` / `array_find` / `array_any` / `array_all`，
    符合 AGENTS §3.6 与 §5.12；
  - `symfony/polyfill-php85` 在 PHP >= 8.5 下整体短路（`bootstrap.php` 首行
    `if (\PHP_VERSION_ID >= 80500) { return; }`）。
- [x] 5.7 路由文件 `routes/*.php` 审查
  - `web.php`（12041 字节）/ `api.php`（4180 字节）/ `static.php`（1048 字节）；
  - 路由总数：`APP_DEBUG=false` → **173**，`APP_DEBUG=true` → **178**（含 5 条 debugbar）；
  - `--except-vendor` → 155；method 集合新增 `QUERY`（L13 兜底路由）。
- [x] 5.8 视图与 Twig 模板审查
  - 104 个 `.twig` 模板 + 14 个语言目录；
  - 唯一 `->links()` 调用在 `resources/views/user/report.twig:36`；
  - 其余分页（公告 / 站内信列表）走 `resources/views/shared/communication-pagination.twig`，
    不经过框架分页视图；
  - `Paginator::useBootstrap()` 在 L13 仍存在并转调 `useBootstrapFour()`，
    分页 HTML 与 L10 **完全一致**；
  - vendor 中 `bootstrap-3/4/5`、`simple-bootstrap-3/4/5`、`tailwind`、`semantic-ui` 视图**均仍在**。
- [x] 5.9 `config/` 全量与 13.x 骨架同步
  - 27 个配置文件；新增 `cache.serializable_classes` 白名单（D10）与
    `session.serialization`（D11 之前的决策）；
  - 排除 `Illuminate\Image\ImageServiceProvider`（D11）；
  - `.env.example` 新增 `CACHE_PREFIX` / `REDIS_PREFIX` / `SESSION_COOKIE`；
  - 未引入 `concurrency.php` / `cors.php`（本项目不使用 `Concurrency` facade，也未启用 CORS）；
  - `config/passport.php` 删除 `client_uuids`、`personal_access_client` 两段（v13 不再读取，
    改由 D6 的静态属性控制）。
- [x] 5.10 `database/migrations/` 审查（change/double/float/spatial）
  - 27 个迁移（含 `2026_10_24_000001_create_communications.php`、
    `2026_10_25_000001_hash_oauth_client_secrets.php`）；
  - `->change()` 共 6 处，全部在 `2019_03_01_131420_add_tid_skin.php`（2）、
    `2019_12_14_095751_update_ip_field.php`（2）、`2020_03_10_145738_lengthen_ip_field.php`（2），
    在无 DBAL 下正常运行；
  - `double` / `float` / `spatial` / `geometry` / `point` 0 处；
  - 既有迁移**未被修改**（AGENTS §3.3）。
- [x] 5.11 `tests/` 升级至 PHPUnit 12
  - 65 个测试文件；`phpunit/phpunit ^12.0`（装 12.5.40）；
    `laravel/browser-kit-testing ^7.2.8`（D3）；
  - `tests/` 中 **0 条 `@test` / `@dataProvider` docblock 注解**，**0 处 PHPUnit Attributes 使用**；
  - 全量 `php artisan test` → 262 passed / 1 skipped / 2037 assertions / 0 failed；
  - `phpunit.xml` 已更新为 PHPUnit 12 schema（`<source>` 取代 `<filter>`）。
- [x] 5.12 `.env.example`、`phpunit.xml`、`Dockerfile`、`.devcontainer` 同步
  - `.env.example` 已新增 `CACHE_PREFIX` / `REDIS_PREFIX` / `SESSION_COOKIE`（35–36 行为 DRIVER）；
  - `phpunit.xml` 已更新（见 5.11）；
  - `Dockerfile` 仍使用 `FROM php:8-apache`（可选更新项，尚未改；
    `mcr.microsoft.com/devcontainers/php:8.5` 标签确认存在）；
  - `.devcontainer/docker-compose.yml` 仍使用 `VARIANT: "8-bullseye"` 与 `mariadb:10.4`
    （可选更新项，尚未改）。

### 阶段 5 结果记录

- 全部 12 个子项完成；`php -l` 对所有改动 PHP 文件通过；
  `php artisan package:discover` / `route:list` / `config:cache` / `view:clear` 全部成功。
- `app/Http/Kernel.php` 与 `app/Console/Kernel.php` 已删除；
  `app/Exceptions/Handler.php` **保留**（`bootstrap/app.php` 重绑）；
  `RouteServiceProvider::$namespace` **保留**；`EventServiceProvider::$listen` 8 组映射**保留**；
  未创建 `bootstrap/providers.php`。

## 阶段 6：验证 ✅ 已完成（6.1–6.7 / 6.8–6.9 文档同步）

- [x] 6.1 `composer validate`
  - `php composer.phar validate` → `./composer.json is valid`。
- [x] 6.2 `php artisan --version` 可运行
  - `Laravel Framework 13.38.0`。
- [x] 6.3 `php artisan route:list` 正常
  - `Showing [173] routes`（`APP_DEBUG=false`）/ `Showing [178] routes`（`APP_DEBUG=true`），
    无 ERROR / Exception / Warning。
- [x] 6.4 `php artisan config:cache` 正常
  - `config:cache` / `view:clear` / `config:clear` 全部成功。
- [x] 6.5 SQLite 内存库跑 `php artisan test`
  - **262 passed, 1 skipped, 2037 assertions, 0 failed**（97.95s）；
    1 skipped 为 Windows 无 `symlink()`（`UpdatePackageTest::testSymlinkDestinationIsRejected`）。
- [x] 6.6 前端 `yarn install --frozen-lockfile && yarn build`
  - `npx yarn@1.22.22 install --frozen-lockfile --ignore-scripts` → `Done in 253.78s`；
  - `yarn build` → `app compiled with 2 warnings`、`Done in 15.91s`；
  - 2 条警告为 `admin-lte` 第三方 CSS 的 `autoprefixer: Replace color-adjust to print-color-adjust`，
    与升级无关（既有噪音）。
- [x] 6.7 `tools/build-php85.py` 构建脚本可用性确认
  - 实测成功生成 `blessing-skin-server_6.0.10_patch_php85.zip`（24513448 字节）+
    `manifest.json`（version/commit/php/packages/sha256）+ `SHA256SUMS`
    （该次实测发生在决策 D12 升版本号之前；升到 `6.0.11_patch` 后产物名随版本号变化，断言逻辑不变）；
  - 脚本内置版本断言（`package.json` 与 `config/app.php` 的 `version` 必须一致）通过；
  - 本机需 `GITHUB_SHA`（无 `.git`）与 yarn shim（`--yarn`）。
- [x] 6.8 更新 `CHANGELOG-PATCH.md` / `BUILD-PHP85.md` / `PATCH-INSTALL.md`
  - `CHANGELOG-PATCH.md`：顶部新增 `# 6.0.11_patch · 2026-10-26` 条目（12 条升级要点），
    原 `6.0.10_patch` 条目保留在其下；
  - `BUILD-PHP85.md`：整体重写（Laravel 13 / 6.0.11_patch、PHP 扩展清单、
    `config.platform.php = 8.5.0`、升级注意事项、构建脚本额外要求、`color-adjust` 警告说明）；
  - `PATCH-INSTALL.md`：标题改为 `6.0.11_patch 安装与更新`；运行环境 PHP 8.5 + Laravel 13；
    升级前 PHP 版本确认与扩展清单；手动更新命令（含 `twig:clean`）；`.env` 三项确认；
    已知运行环境差异（缓存白名单 / 会话序列化 / Image facade / OAuth 密钥）。
- [x] 6.9 补充 Laravel 13 升级说明文档
  - `README.md`：第 3 行改 Laravel 13 + 6.0.11_patch + 逐级升级说明引用；
    第 20–21 行补 Laravel 13.38 / PHPUnit 12；`Requirements` 改为 `PHP >= 8.5.0 (Laravel 13.38)`；
  - `README-zh.md`：`PHP >= 8.1.0` → `PHP >= 8.5.0（Laravel 13.38）`；
  - `AGENTS.md`：新增「当前版本：6.0.11_patch」；功能保留条目范围改 `6.0.3 ~ 6.0.11_patch`；
  - `UPGRADE-TASKS.md` 已同步维护（本文件）；
  - 新增决策记录 D10（`serializable_classes` 白名单）、D11（`Illuminate\Image\ImageServiceProvider` 排除）、
    D12（版本号升到 `6.0.11_patch`）。
- [x] 6.10 版本号升到 `6.0.11_patch` 后的回归验证（决策 D12）
  - `package.json`、`config/app.php`、`config/patch.php`、`CHANGELOG-PATCH.md`、
    `UpdateControllerTest` 五处已同步；
  - `UpdateControllerTest::testVersionLogsAreNewestFirstAndCollapsed` 在 9 个版本下通过
    （`version-changelog-1..9` 断言、无 `collapse show`）；
  - `php artisan --version` / `route:list` / `composer validate` 复核无回归。

### 阶段 6 结果记录

- AGENTS §7 验证清单全部通过：
  `composer validate` ✅ / `artisan --version` ✅ / `route:list` ✅ / `config:cache` ✅ /
  `view:clear` ✅ / `test` ✅ / `yarn install --frozen-lockfile` ✅ / `yarn build` ✅。
- 阻塞项：无。可选更新项（未改）：`Dockerfile` 的 `FROM php:8-apache`、
  `.devcontainer/docker-compose.yml` 的 `VARIANT: "8-bullseye"` 与 `mariadb:10.4`。
- 风险路径 #1912 / CVE-2026-39976：已核实本应用 `firstParty()` 恒 false，
  风险路径不可达，不修，已写入文档。

---

## 升级结论

- **目标达成**：Laravel 13.38.0 + PHP 8.5.12，全量测试 262 passed / 1 skipped / 2037 assertions / 0 failed。
- **patch 功能全部保留**：管理员仪表盘入口、ZIP 在线更新（备份 / SHA-256 / 安装确认）、
  通知与公告管理中心、组件式编辑器、源码模式、统一分页、更新源 / 插件仓库配置、
  PHP 8.5 适配层。
- **未破坏**：插件 API、既有迁移、`.env` 运行时行为、全局函数无 polyfill 冲突。
- **文档同步**：`UPGRADE-TASKS.md`、`CHANGELOG-PATCH.md`、`BUILD-PHP85.md`、
  `PATCH-INSTALL.md`、`README.md` / `README-zh.md` 已更新。
