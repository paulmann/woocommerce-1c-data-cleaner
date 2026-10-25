# WooCommerce 1C Data Cleaner

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/8.3/en.php)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6%2B-96588A?logo=woocommerce&logoColor=white)](https://woocommerce.com/)
[![MySQL](https://img.shields.io/badge/MySQL%20%2F%20MariaDB-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Version](https://img.shields.io/badge/version-3.0.0-blue.svg)](1c-data-cleaner.php)
[![Single file](https://img.shields.io/badge/deploy-single%20file-informational)](1c-data-cleaner.php)

<!-- md-i18n:switcher -->
**Read this in:** English · [Deutsch](README.de.md) · [中文](README.zh.md) · [हिन्दी](README.hi.md) · [Español](README.es.md) · [Русский](README.ru.md)

**One PHP file for safely resetting stale exchange IDs between 1C and WooCommerce: product, price and warehouse GUIDs.** The tool targets the **ItGalaxy WooCommerce Synchronization 1C** plugin — «WooCommerce — 1C:Enterprise — Data Exchange» — and is needed first of all after a configuration or 1C version upgrade, when the identifiers from the old exchange stop matching the current data.

The script deletes the old identifiers and the related service data **without deleting products**. On the next synchronization the ItGalaxy plugin finds each product **by SKU** and writes its current ID / GUID from 1C. Price and warehouse links are rebuilt at the same time.

> [!IMPORTANT]
> Product and variation records in `{prefix}posts` are **never deleted**. The script works only with metadata, service options and the WooCommerce cache table. Always back up the database before you run it.

---

## Table of contents

- [Purpose and use case](#purpose-and-use-case)
- [How the GUID problem is solved](#how-the-guid-problem-is-solved)
- [Features](#features)
- [What changes in the database](#what-changes-in-the-database)
- [Requirements](#requirements)
- [Installation](#installation)
- [First run and access password](#first-run-and-access-password)
- [Resetting a forgotten password](#resetting-a-forgotten-password)
- [Debug mode](#debug-mode)
- [Workflow](#workflow)
- [CLI mode](#cli-mode)
- [Architecture and security](#architecture-and-security)
- [Backup and rollback](#backup-and-rollback)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)
- [Roadmap](#roadmap)
- [License and author](#license-and-author)

---

## Purpose and use case

The script is built for the ItGalaxy plugin **WooCommerce Synchronization 1C / WooCommerce — 1C:Enterprise — Data Exchange**. The official documentation and instructions for the plugin live on the ITGALAXY website: [«WooCommerce — 1C:Enterprise — Data Exchange — Instructions»](https://itgalaxy.company/software/wordpress-woocommerce-1c-%D0%BF%D1%80%D0%B5%D0%B4%D0%BF%D1%80%D0%B8%D1%8F%D1%82%D0%B8%D0%B5-%D0%BE%D0%B1%D0%BC%D0%B5%D0%BD-%D0%B4%D0%B0%D0%BD%D0%BD%D1%8B%D0%BC%D0%B8/woocommerce-1c%D0%BF%D1%80%D0%B5%D0%B4%D0%BF%D1%80%D0%B8%D1%8F%D1%82%D0%B8%D0%B5-%D0%BE%D0%B1%D0%BC%D0%B5%D0%BD-%D0%B4%D0%B0%D0%BD%D0%BD%D1%8B%D0%BC%D0%B8-%D0%B8%D0%BD%D1%81/).

A typical scenario goes like this:

1. On the 1C side the configuration is upgraded, 1C moves to a new version, the database is restored from a backup, item master data is migrated or the exchange rules are changed.
2. As a result, the product, price type or warehouse GUIDs / IDs that the plugin stored earlier in WooCommerce may become stale or disappear from the new 1C version.
3. The new exchange cannot match some objects by their old IDs: duplicates appear, plus "stuck" prices, stock or warehouses.
4. The administrator runs this script and clears only the outdated identifiers and the related exchange data.
5. On the next exchange the ItGalaxy plugin matches products **by SKU** and writes the new IDs / GUIDs from the current 1C database.
6. Prices and stock are loaded again according to the current price types and warehouses in 1C.

That is exactly why this is not a "catalog deleter" but a service utility for controlled re-linking of existing products to an updated 1C.

### Which symptoms it removes

- After the 1C upgrade products started duplicating during the exchange.
- Prices are not updated, stay empty or belong to old price types.
- Warehouse stock does not arrive, is displayed incorrectly or is "stuck" from the old database.
- Non-existent prices, warehouses or GUIDs remain in the plugin settings and WooCommerce lists.
- An existing WooCommerce catalog has to be connected to a new or restored 1C database without deleting products by hand.

> [!WARNING]
> Before cleaning, make sure the SKUs in 1C are filled in and unique. The SKU is precisely the key used to re-match a product on the next synchronization. If different products share one SKU, or a SKU is missing, automatic matching may be ambiguous.

---

## How the GUID problem is solved

### Before cleaning

```text
WooCommerce                         Old / updated 1C
───────────                         ──────────────────
Product: "Battery X"                Product: "Battery X"
SKU: BAT-X-100                      SKU: BAT-X-100
_id_1c: OLD-GUID-123   ────────X──> GUID: NEW-GUID-987
```

After the 1C upgrade the old `OLD-GUID-123` that was written earlier into `wp_postmeta` may be missing or may already belong to a different object. The plugin must not keep using the stale link.

### After cleaning and a new exchange

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

So the script creates a "clean starting point" for a repeated synchronization. It does not generate GUIDs itself and does not change item master data: 1C supplies the current identifiers, and the ItGalaxy plugin writes them into WordPress during the exchange.

---

## Features

- **Target scope:** works with ItGalaxy WooCommerce Synchronization 1C / WooCommerce — 1C:Enterprise — Data Exchange.
- **Four operations:** cleaning 1C GUIDs, prices, stock, or a complete clean of all three groups in one transaction.
- **No catalog deletion:** `posts` records, products, variations, titles, descriptions, images, categories and SKUs are not deleted.
- **Preview:** before a run you see the counts of products, GUIDs, price and stock rows, price types and warehouses.
- **WordPress auto-discovery:** database parameters are read from `wp-config.php`; the file is looked up in the script directory and up to three levels above it.
- **Transactions:** `START TRANSACTION` → operations → `COMMIT`; on an exception `ROLLBACK` runs.
- **Prepared statements:** PDO MySQL preferred, mysqli as a fallback; user values are never concatenated into SQL.
- **Password and ACL:** a password is created on the first run, only its hash is stored, an authorized IP is remembered for 30 days.
- **AJAX interface:** the page does not reload; the button changes state for the duration of the operation, the other buttons are blocked, console errors are not cleared.
- **Debug mode:** a full log of server and client events, SQL, row counts and timings — from the interface toggle or via `?debug=1`.
- **CLI access recovery:** commands to check, generate and set the password manually.
- **One file:** no Composer, no extra libraries and no WordPress bootstrap.

---

## What changes in the database

`{prefix}` is the value of `$table_prefix` from `wp-config.php`; for a standard WordPress installation it is `wp_`.

| ItGalaxy exchange data | Where it is stored | What the script does |
|---|---|---|
| Product and variation GUID | `{prefix}postmeta.meta_key = '_id_1c'` | Deletion for `product` and `product_variation` |
| Category and tag GUID | `{prefix}termmeta.meta_key = '_id_1c'` | Deletion of the old IDs |
| Attribute GUID | `{prefix}woocommerce_attribute_taxonomies.id_1c` | Field cleared to an empty string |
| GUID maps | `{prefix}options`: `all1cProducts`, `currentAll1cGroup`, `itglx_wc1c_nomenclature_categories` | Value replaced with an empty serialized array |
| Prices by price type | `{prefix}postmeta.meta_key = '_all_prices'` | Deletion |
| Base WooCommerce prices | `{prefix}postmeta`: `_price`, `_regular_price`, `_sale_price` | Deletion — the current values arrive from 1C |
| Price type list | `{prefix}options.option_name = 'all_prices_types'` | Array cleared |
| Price type mapping settings | `wc-itgalaxy-1c-exchange-settings`, keys `price_type_*` | Deletion of the keys `price_type_N`, `price_type_N_text`, `price_type_N_summ` |
| Stock by warehouse | `{prefix}postmeta.meta_key = '_separate_warehouse_stock'` | Deletion |
| Total stock | `{prefix}postmeta.meta_key = '_stock'` | Deletion |
| Warehouse list | `{prefix}options.option_name = 'all_1c_stocks'` | Array cleared |
| Stock statuses | `{prefix}postmeta`: `_stock_status`, `_manage_stock`, `_backorders` | Neutral values: `instock`, `no`, `no` |
| WooCommerce price cache | `{prefix}wc_product_meta_lookup`: `min_price`, `max_price`, `onsale` | Zeroing |
| WooCommerce stock cache | `{prefix}wc_product_meta_lookup`: `stock_quantity`, `stock_status` | `0` and `instock` |

A missing `woocommerce_attribute_taxonomies` or `wc_product_meta_lookup` table is detected automatically and skipped with a log entry.

### Button and scenario mapping

| Button | What is cleaned | When to use it |
|---|---|---|
| **«Очистить GUID 1С»** (Clear 1C GUIDs) | Old `_id_1c`, category/tag/attribute GUIDs and GUID maps | After a 1C upgrade or migration, with duplicates and wrong links |
| **«Очистить цены»** (Clear prices) | Old prices, price types, `price_type_*`, price cache | When price types changed after the 1C upgrade or prices do not synchronize |
| **«Очистить остатки»** (Clear stock) | Old stock, warehouses, stock statuses and cache | When warehouses changed, stock is "stuck" or does not arrive from 1C |
| **«Очистить всё»** (Clear everything) | All the groups above in one transaction | After a comprehensive upgrade or migration of the 1C database |

---

## Requirements

| Component | Minimum | Note |
|---|---|---|
| PHP | 8.3 | `enum`, `readonly`, `match` and strict typing are used |
| PHP extensions | `pdo_mysql` **or** `mysqli` | PDO is preferred; the standard `json`, `session` and `hash` are also required |
| MySQL / MariaDB | MySQL 5.7 / MariaDB 10.3 | InnoDB transactions and `DELETE … INNER JOIN` support |
| WordPress | 5.6+ | A readable `wp-config.php` is required |
| WooCommerce | 6+ | The lookup table is optional, its absence is handled |
| Exchange plugin | ItGalaxy WooCommerce Synchronization 1C | «WooCommerce — 1C:Enterprise — Data Exchange» |
| File system rights | Write access to the script directory | For the password hash file and the ACL |
| Browser | Modern | `fetch`, `URL`, optionally the Clipboard API |

---

## Installation

### 1. Back up the database

```bash
# Full backup dump before cleaning
mysqldump --single-transaction --routines --triggers -u USER -p DBNAME \
  | gzip > backup-before-wc1c-clean-$(date +%F-%H%M).sql.gz
```

### 2. Download the script into the WordPress root

The file must sit next to `wp-config.php` or no deeper than three levels away from it.

```bash
cd /var/www/example.com/public_html
curl -fsSLO https://raw.githubusercontent.com/paulmann/woocommerce-1c-data-cleaner/main/1c-data-cleaner.php

# Example permissions — adjust the web server user for your environment
chmod 640 1c-data-cleaner.php
chown www-data:www-data 1c-data-cleaner.php
```

### 3. Restrict access at the web server level

The script password does not replace network protection. As an extra safeguard it is recommended to allow access from your own IP only.

**Nginx:**

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

**Apache / `.htaccess`:**

```apache
<Files "1c-data-cleaner.php">
    Require ip 203.0.113.10
</Files>

<FilesMatch "^\.wc1c-cleaner-">
    Require all denied
</FilesMatch>
```

### 4. Open the interface

Open in your browser:

```text
https://example.com/1c-data-cleaner.php
```

On the first run the script generates a password, authorizes the current session and shows the password only once.

---

## First run and access password

On the first request the following happens:

1. A 20-character password is generated with the cryptographically secure `random_int()` generator.
2. **Only the hash** of the password is written to `.wc1c-cleaner-auth.json`: Argon2id when PHP provides it, otherwise bcrypt through `PASSWORD_DEFAULT`.
3. The file is written atomically (`.tmp` + `rename`) and receives `0600` permissions.
4. The current user is authorized automatically, and their IP is added to the ACL.
5. The password is displayed once, with a copy option.

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

### Access model

- The current PHP session is authorized, so the password is not asked again until the session ends.
- A successfully authorized IP is written into the ACL for 30 days. The IP itself is not stored in the file: `HMAC-SHA-256` with a separate random salt is used.
- From another IP or computer a sign-in form is shown — the saved password is required.
- After 8 failed attempts within one session further input is blocked; every wrong password adds a 400 ms delay.
- «Выйти и забыть IP» (Sign out and forget this IP) destroys the session and removes the current IP from the ACL.

> [!NOTE]
> If the site sits behind a reverse proxy or Cloudflare, enable `Config::TRUST_PROXY = true` when needed. Do it only when the proxy rewrites the client IP correctly: on direct access the `X-Forwarded-For` header can be forged.

---

## Resetting a forgotten password

A reset changes the ACL salt, so every client — including previously trusted IPs — has to enter the new password again.

### Option 1: SSH / CLI — recommended

```bash
# Show the state of the access store
php 1c-data-cleaner.php --status

# Generate a new password and print it to the console
php 1c-data-cleaner.php --reset

# Set your own password manually, at least 10 characters
php 1c-data-cleaner.php --status
```

### Option 2: FTP or a file manager

Delete the store file next to the script:

```bash
rm /var/www/example.com/public_html/.wc1c-cleaner-auth.json
```

The next time the page is opened, a new password is created and shown once.

### Option 3: reset marker file

If files can be created but existing ones should not be deleted:

```bash
touch /var/www/example.com/public_html/.wc1c-cleaner-reset
```

On the next HTTP request the script deletes the old store, deletes the marker and creates a new password + ACL pair.

---

## Debug mode

Debug mode is switched on without touching the code:

- by the **«Режим отладки»** (Debug mode) toggle at the top of the interface;
- by the `?debug=1` URL parameter to enable it;
- by the `?debug=0` URL parameter to disable it.

The state is kept in the current PHP session. With debug on, the script displays and sends to the browser console a full log:

```text
+    0.31 ms  INFO  Входящий запрос {"method":"POST","php":"8.3.14","debug":true}
+    1.08 ms  INFO  wp-config.php найден {"path":"/var/www/.../wp-config.php"}
+    3.42 ms  INFO  Подключение к БД установлено {"driver":"PDO","port":3306}
+   12.77 ms  INFO  Транзакция START
+   13.05 ms  INFO  Шаг 1/4: GUID товаров и вариаций
+  184.62 ms  DEBUG SQL выполнен {"rows":10432,"ms":171.2}
+  512.90 ms  INFO  Транзакция COMMIT
```

The log covers authorization and ACL events, CSRF validation, the `wp-config.php` lookup, the database driver, SQL queries and their timings, transaction boundaries, the number of affected rows, the preview before and after cleaning, plus JavaScript errors and client network errors. Passwords, hashes, tokens and salts are masked as `***`.

---

## Workflow

1. Check that the products in the current 1C have correct and unique SKUs.
2. Create a database backup.
3. Stop the automatic exchange with 1C for the duration of the work if it runs on a schedule.
4. Upload `1c-data-cleaner.php` to the WordPress directory and open it in a browser.
5. On the first run, save the generated password.
6. Enable debug mode if you want to capture the detailed progress of the operation.
7. Review the **«Текущее состояние»** (Current state) table and choose the cleaning scope you need.
8. Tick the checkbox «Я понимаю, что данные будут удалены, бэкап сделан» (I understand the data will be deleted and a backup exists).
9. Press the cleaning button and confirm the action in the dialog.
10. Make sure the «Результат» (Result) block contains no errors and the debug log contains `Транзакция COMMIT`.
11. Start the synchronization in the ItGalaxy plugin. Products are matched by SKU, after which the plugin creates the current GUIDs / IDs, prices and stock.
12. Check several products, prices and stock in the storefront and in the WordPress admin.
13. Delete `1c-data-cleaner.php`, `.wc1c-cleaner-auth.json` and a possible `.wc1c-cleaner-reset` from the server.

> [!TIP]
> For a comprehensive 1C upgrade one run of «Очистить всё» (Clear everything) is usually enough, followed by a full exchange. For a local problem it is safer to pick the targeted button: for example «Очистить цены» (Clear prices) only, if nothing but the price types changed.

---

## CLI mode

CLI mode is intended for access management only, not for deleting database data.

```bash
$ php 1c-data-cleaner.php --status
WooCommerce 1C Data Cleaner 3.0.0
Хранилище: /var/www/example.com/public_html/.wc1c-cleaner-auth.json
Создано: 2026-08-27 15:42:10
Тип пароля: сгенерирован автоматически
Доверенных IP: 2
```

| Command | Result |
|---|---|
| `php 1c-data-cleaner.php --status` | Status of the access file, creation date, number of trusted IPs |
| `php 1c-data-cleaner.php --reset` | Creates and prints a new random password, resets the ACL |
| `php 1c-data-cleaner.php --set-password='…'` | Sets an administrator password of at least 10 characters and resets the ACL |
| `php 1c-data-cleaner.php --help` | Prints a short cheat sheet |

---

## Architecture and security

The script is a single PHP file with `declare(strict_types=1)`, split into small components.

| Component | Responsibility |
|---|---|
| `Config` | Version, metadata, exchange key sets, ACL TTL, path to the service files |
| `EventLog` | Event log with timings and masking of sensitive values |
| `Credentials` | Password generation and hashing, per-IP ACL, atomic store writes, reset marker |
| `Session` / `Debug` | Secure cookies, CSRF, storage and toggling of debug mode |
| `Auth` | Authorization by session, IP and password, attempt limit, sign-out |
| `WpConfig` | Lookup and safe parsing of `wp-config.php` parameters without including it |
| `Db` | PDO/mysqli, prepared statements, transactions, table identifier validation |
| `Action` | Strict enumeration of the available operations |
| `Cleaner` | Cleaning of GUIDs, prices, stock and service maps |
| `Preview` | Counting data before and after the actions |
| JSON API | `login`, `logout`, `ack_password`, `set_debug`, `preview`, `guid`, `prices`, `stocks`, `all` |

### Safeguards

- no SQL concatenation of user values: prepared statements are used;
- table names are validated with the `[A-Za-z0-9_]+` regular expression;
- `unserialize()` is called with `allowed_classes => false`;
- the CSRF token is created with `random_bytes(32)` and checked with `hash_equals`;
- the session ID is regenerated after a successful authorization;
- session cookies carry `HttpOnly`, `SameSite=Strict` and, over HTTPS, `Secure`;
- responses carry `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow` and a meta robots tag;
- `display_errors=0`: PHP notices and warnings go to the EventLog without breaking the JSON response;
- `.gitignore` excludes the password store, the ACL and the reset marker.

> [!WARNING]
> The script provides direct access to the database and performs bulk `DELETE` / `UPDATE`. Do not leave it in a public web root after the work is finished. The password is no substitute for restricting IPs through Nginx, Apache, a VPN or HTTP Basic Auth.

---

## Backup and rollback

### Quick backup of the affected tables

Replace `wp_` with the actual table prefix if it differs.

```bash
mysqldump --single-transaction -u USER -p DBNAME \
  wp_postmeta \
  wp_termmeta \
  wp_options \
  wp_woocommerce_attribute_taxonomies \
  wp_wc_product_meta_lookup \
  | gzip > wc1c-before-clean-$(date +%F-%H%M).sql.gz
```

### Restoring from a dump

```bash
gunzip < wc1c-before-clean-2026-08-27-1542.sql.gz | mysql -u USER -p DBNAME
```

After a restore or a new full exchange the cache can be cleared:

```bash
wp cache flush
wp wc update_db
```

The transaction protects against partially applied actions only **within a single run**: if an error occurs before `COMMIT`, a `ROLLBACK` is executed. After a successful `COMMIT` the only way back is restoring from a backup.

---

## Troubleshooting

| Symptom | Possible cause | Solution |
|---|---|---|
| «Файл wp-config.php не найден» | The script sits more than three levels away from WordPress | Move it into the WordPress root next to `wp-config.php` |
| «Не удалось распознать DB_NAME / DB_USER / $table_prefix» | The parameters in `wp-config.php` are set in a non-standard way, for example through `getenv()` | Use string `define()` calls or adapt `WpConfig::parse()` to your environment |
| «На сервере не включено ни PDO MySQL, ни mysqli» | The PHP MySQL extensions are missing | Enable `pdo_mysql` or `mysqli` in the hosting panel / `php.ini` |
| «Недействительный токен сессии» | The session expired, cookies are blocked, the load balancer does not keep session affinity | Reload the page, allow cookies, check the PHP sessions configuration |
| The password is not saved | The script directory is not writable, a temporary folder is used | Give the web user write access to the script directory |
| The button shows «Выполняется…» for a long time | A large `postmeta` table, a MySQL lock or a PHP-FPM timeout | Enable debug, find the slow SQL, check the indexes and `request_terminate_timeout` |
| Prices or stock are empty after cleaning | This is the expected state until the next exchange | Start the exchange in ItGalaxy and wait for it to finish |
| Duplicates appeared after the exchange | SKUs do not match or are not unique | Fix the SKUs in 1C and WooCommerce, remove the incorrect duplicates, repeat a controlled exchange |
| Stock is stale after the exchange | Object, page or CDN cache | `wp cache flush`, reset the WooCommerce and CDN cache |

---

## FAQ

### Will products, images and descriptions really stay?

Yes. The script contains no `DELETE` from `{prefix}posts`. It does not delete products, variations, SKUs, titles, descriptions, media files, categories or tags. Only the exchange service links, prices, stock and the related cache values are cleaned.

### When should the script be used?

The main case is an upgrade of the 1C version or configuration, a move to another database, a restore of the database from a backup and other situations where the old product, price or warehouse GUIDs no longer match the data of the new 1C. The utility deletes the old IDs; the next exchange creates new ones.

### Why are products matched again in 1C after cleaning?

Because the ItGalaxy plugin matches products by SKU during synchronization. If the SKUs are identical and unique, the plugin finds the existing WooCommerce product and writes the current ID / GUID from 1C into it instead of creating a new product.

### Should everything be cleared?

No. Choose the smallest operation you need. If only the price types changed — use «Очистить цены» (Clear prices). If the problem concerns warehouses only — «Очистить остатки» (Clear stock). «Очистить всё» (Clear everything) suits a full upgrade or migration of the 1C database, when none of the old identifiers can be trusted.

### Can the cleaning be undone?

Until the HTTP request completes, errors are rolled back by the transaction. After a successful `COMMIT` the old data can only be returned from a database backup dump.

### Are other 1C exchange plugins supported?

No, the ready-made key scheme targets ItGalaxy WooCommerce Synchronization 1C. For other solutions you have to check separately where they store GUIDs, prices and stock, and adapt the constants in `Config`.

### Is WordPress Multisite supported?

The script uses a single `$table_prefix` from `wp-config.php` and works with the main site by default. For sub-sites the prefix must be adapted (`wp_2_`, `wp_3_` and so on) after a prior backup and a test on a copy.

### Why is the password bound to an IP?

It is an extra layer of convenience and protection: after a single successful sign-in from a trusted work address the password does not have to be entered again. When the IP changes, when working from another computer or after an ACL reset, the saved password is required.

---

## Roadmap

- [ ] `dry-run`: a detailed count of the upcoming changes without modifying the database
- [ ] Export of the debug log to JSON and to a text file
- [ ] Selective cleaning by categories, products and ID ranges
- [ ] WordPress Multisite support with sub-site selection
- [ ] Support for the key configurations of other 1C exchange plugins
- [ ] Two-factor confirmation of destructive operations (TOTP)
- [ ] Automatic SKU uniqueness check before deleting GUIDs

---

## License and author

The project is distributed under the [PolyForm Noncommercial 1.0.0](LICENSE) license © 2026 Mikhail Deynekin. The program is provided "as is", without warranty; the user is solely responsible for backups and for the consequences of operations on data.

**Mikhail Deynekin**

- Website: [Deynekin.com](https://deynekin.com)
- GitHub: [paulmann (Mikhail Deynekin)](https://github.com/paulmann/)
- Repository: [woocommerce-1c-data-cleaner](https://github.com/paulmann/woocommerce-1c-data-cleaner)
- Another tool: [Clean_BOM_Senior](https://github.com/paulmann/Clean_BOM_Senior) — removes invisible UTF-8 BOM and CRLF from source files

If the tool helped you re-link a catalog correctly after a 1C upgrade — star the repository.
