# WooCommerce 1C Data Cleaner

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/8.3/en.php)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6%2B-96588A?logo=woocommerce&logoColor=white)](https://woocommerce.com/)
[![MySQL](https://img.shields.io/badge/MySQL%20%2F%20MariaDB-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![版本](https://img.shields.io/badge/version-3.0.0-blue.svg)](1c-data-cleaner.php)
[![单文件](https://img.shields.io/badge/deploy-single%20file-informational)](1c-data-cleaner.php)

<!-- md-i18n:switcher -->
**其他语言：** [English](README.md) · [Deutsch](README.de.md) · 中文 · [हिन्दी](README.hi.md) · [Español](README.es.md) · [Русский](README.ru.md)

**一个 PHP 文件，用于安全地重置 1C 与 WooCommerce 之间过期的交换 ID：商品、价格与仓库的 GUID。** 该工具面向 **ItGalaxy WooCommerce Synchronization 1C** 插件——«WooCommerce — 1C:Enterprise — Data Exchange»——并且首先在配置或 1C 版本升级之后需要，此时旧交换中的标识符不再与当前数据匹配。

脚本会删除旧标识符与相关服务数据，**但不删除商品**。在下一次同步时，ItGalaxy 插件**按 SKU** 找到每个商品，并写入它从 1C 得到的当前 ID / GUID。价格与仓库的关联同时重建。

> [!IMPORTANT]
> `{prefix}posts` 中的商品与变体记录**从不删除**。脚本只处理元数据、服务选项和 WooCommerce 缓存表。运行前请务必备份数据库。

---

## 目录

- [用途与适用场景](#用途与适用场景)
- [如何解决 GUID 问题](#如何解决-guid-问题)
- [功能特性](#功能特性)
- [数据库中的变化](#数据库中的变化)
- [环境要求](#环境要求)
- [安装](#安装)
- [首次运行与访问密码](#首次运行与访问密码)
- [重置忘记的密码](#重置忘记的密码)
- [调试模式](#调试模式)
- [工作流程](#工作流程)
- [CLI 模式](#cli-模式)
- [架构与安全](#架构与安全)
- [备份与回滚](#备份与回滚)
- [故障排查](#故障排查)
- [常见问题](#常见问题)
- [路线图](#路线图)
- [许可证与作者](#许可证与作者)

---

## 用途与适用场景

脚本为 ItGalaxy 插件 **WooCommerce Synchronization 1C / WooCommerce — 1C:Enterprise — Data Exchange** 而编写。该插件的官方文档与说明位于 ITGALAXY 网站：[«WooCommerce — 1C:Enterprise — Data Exchange — Instructions»](https://itgalaxy.company/software/wordpress-woocommerce-1c-%D0%BF%D1%80%D0%B5%D0%B4%D0%BF%D1%80%D0%B8%D1%8F%D1%82%D0%B8%D0%B5-%D0%BE%D0%B1%D0%BC%D0%B5%D0%BD-%D0%B4%D0%B0%D0%BD%D0%BD%D1%8B%D0%BC%D0%B8/woocommerce-1c%D0%BF%D1%80%D0%B5%D0%B4%D0%BF%D1%80%D0%B8%D1%8F%D1%82%D0%B8%D0%B5-%D0%BE%D0%B1%D0%BC%D0%B5%D0%BD-%D0%B4%D0%B0%D0%BD%D0%BD%D1%8B%D0%BC%D0%B8-%D0%B8%D0%BD%D1%81/)。

典型场景如下：

1. 在 1C 一侧升级配置、1C 迁移到新版本、数据库从备份恢复、物料主数据迁移或交换规则变更。
2. 结果，插件先前存入 WooCommerce 的商品、价格类型或仓库 GUID / ID 可能过期，或在新版 1C 中消失。
3. 新的交换无法按旧 ID 匹配部分对象：出现重复，以及「卡住」的价格、库存或仓库。
4. 管理员运行本脚本，只清除过期的标识符和相关交换数据。
5. 在下一次交换时，ItGalaxy 插件**按 SKU** 匹配商品，并写入当前 1C 数据库中的新 ID / GUID。
6. 价格和库存按 1C 中当前的价格类型和仓库重新加载。

这正是为什么它不是「目录删除器」，而是把现有商品在受控条件下重新关联到更新后 1C 的服务工具。

### 它消除哪些症状

- 1C 升级后，商品在交换过程中开始重复。
- 价格不更新、保持为空，或属于旧的价格类型。
- 仓库库存不到达、显示不正确，或从旧数据库「卡住」。
- 插件设置和 WooCommerce 列表中残留不存在的价格、仓库或 GUID。
- 需要把一个现有 WooCommerce 目录连接到新的或恢复后的 1C 数据库，而不手工删除商品。

> [!WARNING]
> 清理之前，确认 1C 中商品的 SKU 已填写且唯一。SKU 正是下一次同步时重新匹配商品所用的键。如果不同商品共用一个 SKU，或 SKU 缺失，自动匹配可能产生歧义。

---

## 如何解决 GUID 问题

### 清理之前

```text
WooCommerce                         Old / updated 1C
───────────                         ──────────────────
Product: "Battery X"                Product: "Battery X"
SKU: BAT-X-100                      SKU: BAT-X-100
_id_1c: OLD-GUID-123   ────────X──> GUID: NEW-GUID-987
```

1C 升级后，先前写入 `wp_postmeta` 的旧 `OLD-GUID-123` 可能已缺失，或已属于另一个对象。插件不能继续使用这个过期链接。

### 清理后重新交换

```text
1. The script deletes the old _id_1c, the old price / stock data and the GUID maps.

2. The ItGalaxy plugin runs the exchange:

WooCommerce                         1C
───────────                         ──────────────────────
Product: "Battery X"                Product: "Battery X"
SKU: BAT-X-100   <───────────>      SKU: BAT-X-100

3. Matching by SKU succeeds:
   WooCommerce postmeta._id_1c = NEW-GUID-987
   WooCommerce receives current prices and stock.
```

因此脚本为重复同步创建一个「干净的起点」。它自己既不生成 GUID，也不更改物料主数据：1C 提供当前标识符，ItGalaxy 插件在交换过程中把它们写入 WordPress。

---

## 功能特性

- **目标范围：** 适用于 ItGalaxy WooCommerce Synchronization 1C / WooCommerce — 1C:Enterprise — Data Exchange。
- **四种操作：** 清理 1C GUID、价格、库存，或在一次事务中完整清理这三组。
- **不删除目录：** `posts` 记录、商品、变体、标题、描述、图片、分类和 SKU 均不删除。
- **预览：** 运行之前可以看到商品、GUID、价格与库存行、价格类型和仓库的数量。
- **WordPress 自动查找：** 数据库参数从 `wp-config.php` 读取；该文件在脚本所在目录及其上三级内查找。
- **事务：** `START TRANSACTION` → 操作 → `COMMIT`；发生异常时执行 `ROLLBACK`。
- **预处理语句：** 优先 PDO MySQL，后备 mysqli；用户值从不拼接进 SQL。
- **密码与 ACL：** 首次运行时创建密码，只存储其哈希，已授权的 IP 记住 30 天。
- **AJAX 界面：** 页面不重载；操作期间按钮切换状态，其他按钮被阻止，控制台错误不被清除。
- **调试模式：** 服务器与客户端事件、SQL、行数和耗时的完整日志——通过界面开关或 `?debug=1`。
- **CLI 访问恢复：** 用于检查、生成和手工设置密码的命令。
- **单文件：** 不需要 Composer，没有额外库，也不需要 WordPress 引导。

---

## 数据库中的变化

`{prefix}` 是 `wp-config.php` 中 `$table_prefix` 的值；标准 WordPress 安装为 `wp_`。

| ItGalaxy 交换数据 | 存储位置 | 脚本做什么 |
|---|---|---|
| 商品与变体 GUID | `{prefix}postmeta.meta_key = '_id_1c'` | 对 `product` 和 `product_variation` 执行删除 |
| 分类与标签 GUID | `{prefix}termmeta.meta_key = '_id_1c'` | 删除旧 ID |
| 属性 GUID | `{prefix}woocommerce_attribute_taxonomies.id_1c` | 字段清空为空字符串 |
| GUID 映射 | `{prefix}options`：`all1cProducts`、`currentAll1cGroup`、`itglx_wc1c_nomenclature_categories` | 值替换为空序列化数组 |
| 按价格类型的价格 | `{prefix}postmeta.meta_key = '_all_prices'` | 删除 |
| WooCommerce 基础价格 | `{prefix}postmeta`：`_price`、`_regular_price`、`_sale_price` | 删除——当前值由 1C 传入 |
| 价格类型列表 | `{prefix}options.option_name = 'all_prices_types'` | 数组清空 |
| 价格类型映射设置 | `wc-itgalaxy-1c-exchange-settings`，键 `price_type_*` | 删除键 `price_type_N`、`price_type_N_text`、`price_type_N_summ` |
| 按仓库的库存 | `{prefix}postmeta.meta_key = '_separate_warehouse_stock'` | 删除 |
| 总库存 | `{prefix}postmeta.meta_key = '_stock'` | 删除 |
| 仓库列表 | `{prefix}options.option_name = 'all_1c_stocks'` | 数组清空 |
| 库存状态 | `{prefix}postmeta`：`_stock_status`、`_manage_stock`、`_backorders` | 中性值：`instock`、`no`、`no` |
| WooCommerce 价格缓存 | `{prefix}wc_product_meta_lookup`：`min_price`、`max_price`、`onsale` | 归零 |
| WooCommerce 库存缓存 | `{prefix}wc_product_meta_lookup`：`stock_quantity`、`stock_status` | `0` 和 `instock` |

缺失的 `woocommerce_attribute_taxonomies` 或 `wc_product_meta_lookup` 表会被自动检测，跳过并记录一条日志。

### 按钮与场景对照

| 按钮 | 清理内容 | 使用时机 |
|---|---|---|
| **«Очистить GUID 1С»**（清除 1C GUID） | 旧 `_id_1c`、分类/标签/属性 GUID 以及 GUID 映射 | 1C 升级或迁移之后，出现重复和错误关联时 |
| **«Очистить цены»**（清除价格） | 旧价格、价格类型、`price_type_*`、价格缓存 | 1C 升级后价格类型变更，或价格无法同步时 |
| **«Очистить остатки»**（清除库存） | 旧库存、仓库、库存状态和缓存 | 仓库变更、库存「卡住」或 1C 不到达时 |
| **«Очистить всё»**（全部清除） | 上述所有组，在一次事务中 | 1C 数据库全面升级或迁移之后 |

---

## 环境要求

| 组件 | 最低要求 | 说明 |
|---|---|---|
| PHP | 8.3 | 使用 `enum`、`readonly`、`match` 和严格类型 |
| PHP 扩展 | `pdo_mysql` **或** `mysqli` | 优先 PDO；还需要标准的 `json`、`session` 和 `hash` |
| MySQL / MariaDB | MySQL 5.7 / MariaDB 10.3 | 支持 InnoDB 事务和 `DELETE … INNER JOIN` |
| WordPress | 5.6+ | 需要可读的 `wp-config.php` |
| WooCommerce | 6+ | 查找表可选，其缺失已被处理 |
| 交换插件 | ItGalaxy WooCommerce Synchronization 1C | «WooCommerce — 1C:Enterprise — Data Exchange» |
| 文件系统权限 | 对脚本目录有写权限 | 用于密码哈希文件和 ACL |
| 浏览器 | 现代版本 | `fetch`、`URL`，可选 Clipboard API |

---

## 安装

### 1. 备份数据库

```bash
# Full backup dump before cleaning
mysqldump --single-transaction --routines --triggers -u USER -p DBNAME \
  | gzip > backup-before-wc1c-clean-$(date +%F-%H%M).sql.gz
```

### 2. 将脚本下载到 WordPress 根目录

文件必须与 `wp-config.php` 位于同一目录，或距它不超过三级。

```bash
cd /var/www/example.com/public_html
curl -fsSLO https://raw.githubusercontent.com/paulmann/woocommerce-1c-data-cleaner/main/1c-data-cleaner.php

# Example permissions — adjust the web server user for your environment
chmod 640 1c-data-cleaner.php
chown www-data:www-data 1c-data-cleaner.php
```

### 3. 在 Web 服务器层面限制访问

脚本密码不能替代网络防护。作为额外保障，建议只允许来自自己 IP 的访问。

**Nginx：**

```nginx
location = /1c-data-cleaner.php {
    allow 203.0.113.10; # Replace with your own external IP
    deny all;

    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}

# The auth store and the reset marker must never be served over HTTP
location ~ /\.wc1c-cleaner- {
    deny all;
    return 404;
}
```

**Apache / `.htaccess`：**

```apache
<Files "1c-data-cleaner.php">
    Require ip 203.0.113.10
</Files>

<FilesMatch "^\.wc1c-cleaner-">
    Require all denied
</FilesMatch>
```

### 4. 打开界面

在浏览器中打开：

```text
https://example.com/1c-data-cleaner.php
```

首次运行时，脚本生成密码、授权当前会话，并且只显示一次密码。

---

## 首次运行与访问密码

首次请求时发生以下事情：

1. 使用加密安全的 `random_int()` 生成器生成 20 个字符的密码。
2. 只把密码的**哈希**写入 `.wc1c-cleaner-auth.json`：PHP 提供时使用 Argon2id，否则通过 `PASSWORD_DEFAULT` 使用 bcrypt。
3. 文件以原子方式写入（`.tmp` + `rename`），权限为 `0600`。
4. 当前用户被自动授权，其 IP 被加入 ACL。
5. 密码显示一次，并提供复制选项。

```text
┌────────────────────────────────────────────────────────────────────┐
│ Пароль доступа сгенерирован — сохраните его сейчас                 │
│                                                                    │
│ 7hK2mQ+xR9tLpZ4wNb3e                          [Скопировать]        │
│                                                                    │
│ Он нужен для входа с другого IP или другого компьютера.           │
│ На текущем IP пароль больше не спрашивается.                      │
└────────────────────────────────────────────────────────────────────┘
```

### 访问模型

- 当前 PHP 会话已授权，因此在会话结束前不再询问密码。
- 成功授权的 IP 被写入 ACL，有效期 30 天。IP 本身不存入文件：使用带独立随机盐的 `HMAC-SHA-256`。
- 从其他 IP 或另一台计算机访问时显示登录表单——需要已保存的密码。
- 一个会话内失败 8 次后阻止进一步输入；每次错误密码增加 400 ms 延迟。
- «Выйти и забыть IP»（退出并忘记此 IP）销毁会话，并从 ACL 中移除当前 IP。

> [!NOTE]
> 如果站点位于反向代理或 Cloudflare 之后，必要时启用 `Config::TRUST_PROXY = true`。仅当代理正确重写客户端 IP 时才这样做：直接访问时 `X-Forwarded-For` 头可以被伪造。

---

## 重置忘记的密码

重置会更换 ACL 盐，因此每个客户端——包括先前受信任的 IP——都必须重新输入新密码。

### 方式 1：SSH / CLI — 推荐

```bash
# Show the state of the access store
php 1c-data-cleaner.php --status

# Generate a new password and print it to the console
php 1c-data-cleaner.php --reset

# Set your own password manually, at least 10 characters
php 1c-data-cleaner.php --status
```

### 方式 2：FTP 或文件管理器

删除脚本旁边的存储文件：

```bash
rm /var/www/example.com/public_html/.wc1c-cleaner-auth.json
```

下次打开页面时，会创建并显示一次新密码。

### 方式 3：重置标记文件

如果可以创建文件，但不希望删除现有文件：

```bash
touch /var/www/example.com/public_html/.wc1c-cleaner-reset
```

在下一次 HTTP 请求时，脚本删除旧存储、删除标记，并创建新的密码 + ACL 对。

---

## 调试模式

无需改动代码即可开启调试模式：

- 通过界面顶部的 **«Режим отладки»**（调试模式）开关；
- 通过 `?debug=1` URL 参数开启；
- 通过 `?debug=0` URL 参数关闭。

状态保存在当前 PHP 会话中。开启调试后，脚本在界面显示并发送到浏览器控制台的完整日志：

```text
+    0.31 ms  INFO  Входящий запрос {"method":"POST","php":"8.3.14","debug":true}
+    1.08 ms  INFO  wp-config.php найден {"path":"/var/www/.../wp-config.php"}
+    3.42 ms  INFO  Подключение к БД установлено {"driver":"PDO","port":3306}
+   12.77 ms  INFO  Транзакция START
+   13.05 ms  INFO  Шаг 1/4: GUID товаров и вариаций
+  184.62 ms  DEBUG SQL выполнен {"rows":10432,"ms":171.2}
+  512.90 ms  INFO  Транзакция COMMIT
```

日志覆盖授权与 ACL 事件、CSRF 校验、`wp-config.php` 查找、数据库驱动、SQL 查询及其耗时、事务边界、受影响行数、清理前后的预览，以及 JavaScript 错误和客户端网络错误。密码、哈希、令牌和盐被掩码为 `***`。

---

## 工作流程

1. 检查当前 1C 中商品的 SKU 正确且唯一。
2. 创建数据库备份。
3. 如果与 1C 的自动交换按计划运行，在工作期间停止它。
4. 把 `1c-data-cleaner.php` 上传到 WordPress 目录并在浏览器中打开。
5. 首次运行时保存生成的密码。
6. 如果需要记录操作的详细进度，开启调试模式。
7. 查看 **«Текущее состояние»**（当前状态）表，选择需要的清理范围。
8. 勾选复选框「Я понимаю, что данные будут удалены, бэкап сделан」（我明白数据将被删除，备份已完成）。
9. 点击清理按钮，并在对话框中确认操作。
10. 确认「Результат」（结果）块中没有错误，且调试日志中包含 `Транзакция COMMIT`。
11. 在 ItGalaxy 插件中启动同步。商品按 SKU 匹配，之后插件创建当前的 GUID / ID、价格和库存。
12. 在店面与 WordPress 管理后台检查若干商品、价格和库存。
13. 从服务器删除 `1c-data-cleaner.php`、`.wc1c-cleaner-auth.json` 以及可能存在的 `.wc1c-cleaner-reset`。

> [!TIP]
> 对于 1C 的全面升级，一次 «Очистить всё»（全部清除）后接一次完整交换通常足够。对于局部问题，更安全的是选择针对性的按钮：例如只有价格类型发生变化时，仅用 «Очистить цены»（清除价格）。

---

## CLI 模式

CLI 模式只用于访问管理，不用于删除数据库数据。

```bash
$ php 1c-data-cleaner.php --status
WooCommerce 1C Data Cleaner 3.0.0
Хранилище: /var/www/example.com/public_html/.wc1c-cleaner-auth.json
Создано: 2026-08-27 15:42:10
Тип пароля: сгенерирован автоматически
Доверенных IP: 2
```

| 命令 | 结果 |
|---|---|
| `php 1c-data-cleaner.php --status` | 访问文件的状态、创建日期、受信任 IP 数量 |
| `php 1c-data-cleaner.php --reset` | 创建并打印新的随机密码，重置 ACL |
| `php 1c-data-cleaner.php --set-password='…'` | 设置至少 10 个字符的管理员密码并重置 ACL |
| `php 1c-data-cleaner.php --help` | 打印简短的速查表 |

---

## 架构与安全

脚本是一个带 `declare(strict_types=1)` 的单一 PHP 文件，划分为若干小组件。

| 组件 | 职责 |
|---|---|
| `Config` | 版本、元数据、交换键集合、ACL 有效期、服务文件路径 |
| `EventLog` | 带耗时和敏感值掩码的事件日志 |
| `Credentials` | 密码生成与哈希、按 IP 的 ACL、原子写入存储、重置标记 |
| `Session` / `Debug` | 安全 Cookie、CSRF、调试模式的存储与切换 |
| `Auth` | 按会话、IP 和密码授权，尝试次数限制，退出 |
| `WpConfig` | 查找并安全解析 `wp-config.php` 参数，不包含该文件 |
| `Db` | PDO/mysqli、预处理语句、事务、表标识符校验 |
| `Action` | 对可用操作的严格枚举 |
| `Cleaner` | 清理 GUID、价格、库存和服务映射 |
| `Preview` | 统计操作前后的数据 |
| JSON API | `login`、`logout`、`ack_password`、`set_debug`、`preview`、`guid`、`prices`、`stocks`、`all` |

### 防护措施

- 用户值不拼接进 SQL：使用预处理语句；
- 表名用 `[A-Za-z0-9_]+` 正则校验；
- 调用 `unserialize()` 时使用 `allowed_classes => false`；
- CSRF 令牌用 `random_bytes(32)` 创建，并用 `hash_equals` 校验；
- 成功授权后重新生成会话 ID；
- 会话 Cookie 带有 `HttpOnly`、`SameSite=Strict`，在 HTTPS 下还带 `Secure`；
- 响应带有 `Cache-Control: no-store`、`X-Robots-Tag: noindex, nofollow` 以及 meta robots 标签；
- `display_errors=0`：PHP 通知和警告进入 EventLog，不破坏 JSON 响应；
- `.gitignore` 排除密码存储、ACL 和重置标记。

> [!WARNING]
> 脚本提供对数据库的直接访问，并执行批量 `DELETE` / `UPDATE`。工作完成后不要把它留在公开的 Web 根目录。密码不能替代通过 Nginx、Apache、VPN 或 HTTP Basic Auth 限制 IP。

---

## 备份与回滚

### 受影响表的快速备份

如果表前缀不同，把 `wp_` 换成实际前缀。

```bash
mysqldump --single-transaction -u USER -p DBNAME \
  wp_postmeta \
  wp_termmeta \
  wp_options \
  wp_woocommerce_attribute_taxonomies \
  wp_wc_product_meta_lookup \
  | gzip > wc1c-before-clean-$(date +%F-%H%M).sql.gz
```

### 从转储恢复

```bash
gunzip < wc1c-before-clean-2026-08-27-1542.sql.gz | mysql -u USER -p DBNAME
```

恢复或新的完整交换之后，可以清除缓存：

```bash
wp cache flush
wp wc update_db
```

事务只在**单次运行内**防止操作被部分应用：如果在 `COMMIT` 之前发生错误，会执行 `ROLLBACK`。成功 `COMMIT` 之后，唯一的回退方式是从事先的备份恢复。

---

## 故障排查

| 症状 | 可能原因 | 解决办法 |
|---|---|---|
| 「Файл wp-config.php не найден」 | 脚本距 WordPress 超过三级 | 把它移到 WordPress 根目录、`wp-config.php` 旁边 |
| 「Не удалось распознать DB_NAME / DB_USER / $table_prefix」 | `wp-config.php` 中的参数以非标准方式设置，例如通过 `getenv()` | 使用字符串形式的 `define()` 调用，或按环境调整 `WpConfig::parse()` |
| 「На сервере не включено ни PDO MySQL, ни mysqli」 | 缺少 PHP MySQL 扩展 | 在主机面板 / `php.ini` 中启用 `pdo_mysql` 或 `mysqli` |
| 「Недействительный токен сессии」 | 会话已过期、Cookie 被阻止、负载均衡器未保持会话粘性 | 重新加载页面、允许 Cookie、检查 PHP 会话配置 |
| 密码未保存 | 脚本目录不可写，使用了临时目录 | 给 Web 用户对脚本目录的写权限 |
| 按钮长时间显示「Выполняется…」 | `postmeta` 表过大、MySQL 锁或 PHP-FPM 超时 | 开启调试，找到慢 SQL，检查索引和 `request_terminate_timeout` |
| 清理后价格或库存为空 | 在下一次交换之前这是预期状态 | 在 ItGalaxy 中启动交换并等待完成 |
| 交换后出现重复 | SKU 不匹配或不唯一 | 在 1C 和 WooCommerce 中修正 SKU，删除错误的重复项，重复一次受控交换 |
| 交换后库存过期 | 对象、页面或 CDN 缓存 | `wp cache flush`，重置 WooCommerce 与 CDN 缓存 |

---

## 常见问题

### 商品、图片和描述真的会保留吗？

会。脚本中不包含对 `{prefix}posts` 的 `DELETE`。它不删除商品、变体、SKU、标题、描述、媒体文件、分类或标签。只清理交换的服务关联、价格、库存以及相关缓存值。

### 什么时候应该使用脚本？

主要场景是 1C 版本或配置升级、迁移到另一数据库、从备份恢复数据库，以及旧的商品、价格或仓库 GUID 不再与新 1C 数据匹配的其他情况。该工具删除旧 ID；下一次交换会创建新的。

### 为什么清理后商品会在 1C 中重新匹配？

因为 ItGalaxy 插件在同步时按 SKU 匹配商品。如果 SKU 相同且唯一，插件会找到已有的 WooCommerce 商品，并把 1C 中当前的 ID / GUID 写入其中，而不是创建新商品。

### 应该全部清理吗？

不。选择所需的最小操作。如果只有价格类型变化——使用 «Очистить цены»（清除价格）。如果问题只涉及仓库——«Очистить остатки»（清除库存）。«Очистить всё»（全部清除）适合 1C 数据库的完整升级或迁移，此时旧标识符一个都不可信。

### 清理可以撤销吗？

在 HTTP 请求完成之前，错误由事务回滚。成功 `COMMIT` 之后，旧数据只能从数据库备份转储中恢复。

### 是否支持其他 1C 交换插件？

不支持，现成的键方案面向 ItGalaxy WooCommerce Synchronization 1C。对于其他方案，必须单独查明它们在哪里存储 GUID、价格和库存，并调整 `Config` 中的常量。

### 是否支持 WordPress Multisite？

脚本使用 `wp-config.php` 中的单一 `$table_prefix`，默认对主站点工作。对于子站点，前缀必须适配（`wp_2_`、`wp_3_` 等），并且要先备份、在副本上测试。

### 为什么密码与 IP 绑定？

这是额外的便利与保护层：从受信任的工作地址成功登录一次后，不必再次输入密码。当 IP 变化、从另一台计算机工作或 ACL 重置之后，需要已保存的密码。

---

## 路线图

- [ ] `dry-run`：不修改数据库，详细统计即将发生的变化
- [ ] 将调试日志导出为 JSON 和文本文件
- [ ] 按分类、商品和 ID 范围选择性清理
- [ ] 支持 WordPress Multisite 并选择子站点
- [ ] 支持其他 1C 交换插件的关键配置
- [ ] 破坏性操作的二次确认（TOTP）
- [ ] 删除 GUID 前自动检查 SKU 唯一性

---

## 许可证与作者

项目按 [PolyForm Noncommercial 1.0.0](LICENSE) 许可证分发 © 2026 Mikhail Deynekin。程序按「原样」提供，不附带任何担保；用户对备份以及数据操作的后果负全部责任。

**Mikhail Deynekin**

- 网站：[Deynekin.com](https://deynekin.com)
- GitHub：[paulmann (Mikhail Deynekin)](https://github.com/paulmann/)
- 仓库：[woocommerce-1c-data-cleaner](https://github.com/paulmann/woocommerce-1c-data-cleaner)
- 另一个工具：[Clean_BOM_Senior](https://github.com/paulmann/Clean_BOM_Senior) — 从源文件中移除不可见的 UTF-8 BOM 和 CRLF

如果该工具帮助你在 1C 升级后正确重建了目录关联——请给仓库点星。
