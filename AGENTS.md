# AGENTS.md — WooCommerce 1C Data Cleaner 3.0.0

Agent-facing guide: how to install WooCommerce 1C Data Cleaner, run it against a WordPress database,
and report problems responsibly.
Human documentation: [README.md](README.md), [LICENSE](LICENSE).

> Verified against repository state on 2026-10-22. Version covered: 3.0.0.
> The product is one PHP file, `1c-data-cleaner.php`; this repository has no releases.

## 1. What it is

A single-file PHP tool with a browser interface that removes stale ItGalaxy 1C exchange metadata
from a WordPress database, so the next 1C exchange can re-link existing products by SKU (артикул).
It clears GUIDs, price rows and stock rows; it never deletes products, variations, media or
categories. It is not a 1C client and not a catalog importer: it only removes metadata, and the
ItGalaxy plugin writes new GUIDs during the next exchange.

| | |
|---|---|
| Runtime / platform | PHP 8.3+ behind a web server (PHP-FPM or Apache module); CLI PHP only for password management |
| Entry point | `1c-data-cleaner.php` (one file, no Composer, WordPress is not bootstrapped) |
| Writes to | `{prefix}postmeta`, `{prefix}termmeta`, `{prefix}options`, `{prefix}woocommerce_attribute_taxonomies`, `{prefix}wc_product_meta_lookup`; file `.wc1c-cleaner-auth.json` (0600); deletes `.wc1c-cleaner-reset` when used; session cookie `wc1c_cleaner_sid` |
| Network access | None outbound. The script opens only the MySQL/MariaDB connection declared in `wp-config.php` |

## 2. Preconditions

| Requirement | Why | Check |
|---|---|---|
| PHP 8.3+ web SAPI and a modern browser | `enum`, `readonly`, `match`; browser needs `fetch`, `URL`, optional Clipboard API | `php -v` |
| `pdo_mysql` or `mysqli` | DB access, PDO preferred, mysqli fallback | `php -m \| grep -Ei 'pdo_mysql\|mysqli'` |
| MySQL 5.7 / MariaDB 10.3+ | InnoDB transactions, `DELETE ... INNER JOIN` | `mysql --version` |
| WordPress 5.6+ with WooCommerce 6+ and the ItGalaxy WooCommerce Synchronization 1C plugin | DB schema, lookup cache and the hardcoded key schema come from this stack | plugin active in WP admin; `test -r wp-config.php` |
| Write access to the script directory | password/ACL storage; otherwise the system temp directory is used | `test -w "$(dirname 1c-data-cleaner.php)"` |
| Unique, filled SKUs in 1C | re-linking after cleaning happens by SKU | check in 1C before cleaning |
| Working `mysqldump` | cleaning is irreversible after `COMMIT` | `mysqldump --version` |

If a check fails, stop and report; do not work around it silently.

## 3. Install

### 3.1 From a release

Source install only - this repository has no releases. `gh release list -R paulmann/woocommerce-1c-data-cleaner` returns an empty list and the repository has no tags (checked 2026-10-22). There is no asset, tag or checksum to download.

### 3.2 From source

Place the file in the WordPress root next to `wp-config.php`, or at most three directory levels above it (`WpConfig::locate` checks the script directory and three parents).

```bash
cd /var/www/example.com/public_html
curl -fsSLO https://raw.githubusercontent.com/paulmann/woocommerce-1c-data-cleaner/main/1c-data-cleaner.php
chmod 640 1c-data-cleaner.php
chown www-data:www-data 1c-data-cleaner.php    # the web-server user of your host
php -l 1c-data-cleaner.php                     # expect: No syntax errors detected
```

Restrict HTTP access before the first run; the built-in password is not a substitute for a network ACL. Nginx example from the README:

```nginx
location = /1c-data-cleaner.php {
    allow 203.0.113.10;   # replace with your egress IP
    deny all;
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
location ~ /\.wc1c-cleaner- { deny all; return 404; }
```

### 3.3 Verify

```bash
php 1c-data-cleaner.php --status
```

On the very first CLI invocation the script creates `.wc1c-cleaner-auth.json` and prints, instead of the status:

```text
Хранилище отсутствовало и было создано.
Новый пароль: ...
```

Store that password; it is shown once. Run `--status` again and expect the first line:

```text
WooCommerce 1C Data Cleaner 3.0.0
```

Then open `https://example.com/1c-data-cleaner.php`; the login form asks for that password, and an accepted IP is remembered for 30 days.

## 4. Configure

There is no config file. Behaviour comes from `final class Config` inside `1c-data-cleaner.php`:

| Constant | Default | Meaning |
|---|---|---|
| `AUTH_FILE` | `.wc1c-cleaner-auth.json` | Argon2id/bcrypt hash plus hashed-IP ACL, written atomically with mode 0600 |
| `RESET_FILE` | `.wc1c-cleaner-reset` | if present at the next request, the storage is deleted and recreated |
| `PASSWORD_LENGTH` | `20` | length of the generated password |
| `ACL_TTL` | `2592000` | trusted-IP lifetime, 30 days |
| `MAX_ATTEMPTS` | `8` | failed logins per session; each wrong attempt sleeps 400 ms |
| `TRUST_PROXY` | `false` | set to `true` only behind a proxy that overwrites `X-Forwarded-For`/`CF-Connecting-IP` |

Database settings are not configured here: `WpConfig::parse()` reads string `define('DB_NAME'|'DB_USER'|'DB_PASSWORD'|'DB_HOST'|'DB_CHARSET')` and `$table_prefix` from `wp-config.php`. Values produced by `getenv()` or other function calls are not recognised. The storage path printed by `--status` is the script directory when it is writable, otherwise the system temp directory. Never commit `.wc1c-cleaner-auth.json` or `.wc1c-cleaner-reset`; `.gitignore` already excludes them.

Password management over CLI (no database changes):

| Command | Result |
|---|---|
| `php 1c-data-cleaner.php --status` | storage path, creation date, password type, trusted IP count |
| `php 1c-data-cleaner.php --reset` | new random password, ACL reset, prints the password |
| `php 1c-data-cleaner.php --set-password='...'` | manual password, minimum 10 characters, ACL reset |
| `php 1c-data-cleaner.php --help` | usage text; any unknown flag prints the same text and exits 1 |

## 5. Use

Cleaning runs only through the HTTP interface. The CLI never deletes data; it manages the access file only.

Required order on a host that matters:

1. Back up the database (section 6). Do not continue without a verified dump.
2. Run the whole flow once on a copy or staging database. There is no dry-run mode (it is an open roadmap item), so a database copy is the only way to rehearse a cleaning.
3. Open the page; log in with the password from section 3.3 if the login form appears, then read the read-only preview table "Текущее состояние" (`preview` action). It counts products, GUID rows, price rows, stock rows, price types and warehouses; for options `-1` means the option is absent, `-2` means its value is not an array.
4. Choose the narrowest scope that addresses the problem, tick "Я понимаю, что данные будут удалены, бэкап сделан", click the button and accept the browser confirmation dialog.
5. Check the result block and, with `?debug=1`, that the log ends with `Транзакция COMMIT`.
6. Run the ItGalaxy exchange and verify one product end to end: its `_id_1c`, price and stock are repopulated.
7. Remove `1c-data-cleaner.php`, `.wc1c-cleaner-auth.json` and `.wc1c-cleaner-reset` from the server.

Scopes (UI labels and JSON `action` values):

| UI button | `action` | Clears |
|---|---|---|
| Очистить GUID 1С | `guid` | `_id_1c` in postmeta and termmeta, `woocommerce_attribute_taxonomies.id_1c`, GUID maps in options |
| Очистить цены | `prices` | `_all_prices`, `_price`, `_regular_price`, `_sale_price`, `all_prices_types`, `price_type_*`, price cache |
| Очистить остатки | `stocks` | `_stock`, `_separate_warehouse_stock`, `all_1c_stocks`, stock status meta, stock cache |
| Очистить всё | `all` | all three groups in one transaction |

Prefer one narrow scope first. There is no per-product selection: a scope applies to every product and variation. "Verify on one product" therefore means: confirm one product after the exchange, and rehearse the same scope on a database copy first.

JSON API (same script URL, POST, JSON or form body; keys verified in source):

| Key | Meaning |
|---|---|
| `action` | `login`, `logout`, `ack_password`, `set_debug`, `preview`, `guid`, `prices`, `stocks`, `all` |
| `token` | CSRF token; present in the page and returned by `login` |
| `confirm` | `"1"` or `true`; every cleaning action without it is rejected |
| `password`, `enabled`, `forget_ip` | arguments for `login`, `set_debug`, `logout` |

Response keys: `ok`, `error`, `auth_required`, `stats`, `total`, `before`, `preview`, `prefix`, `driver`, `queries`, `ms`, `log`, `debug`.

**Output / exit codes:** CLI exits `0` on success (`--status`, `--reset`, `--set-password`, `--help`) and `1` on an unknown flag or a caught exception, writing `Ошибка:` and the message to STDERR. HTTP JSON: `200` success, `400` unknown action or missing confirmation, `401` password required or wrong password, `419` invalid CSRF token, `500` exception. Treat any `ok: false` as a failure and read `error`.

## 6. Safety and rollback

- **Changes:** mass `DELETE`/`UPDATE` in `{prefix}postmeta`, `{prefix}termmeta`, `{prefix}options`, `{prefix}woocommerce_attribute_taxonomies` and `{prefix}wc_product_meta_lookup`; writes `.wc1c-cleaner-auth.json` (0600); consumes `.wc1c-cleaner-reset`; sets the session cookie `wc1c_cleaner_sid`.
- **Never does:** delete rows from `{prefix}posts` (products, variations, media, categories, SKUs, titles, descriptions stay); generate GUIDs itself; touch other 1C plugins; clean Multisite subsites.
- **Irreversible step:** the `COMMIT`. An error before it is rolled back inside the same request; after `COMMIT` only a database restore brings the data back.
- **Undo / uninstall:** restore the dump, flush caches, then delete the script and its two storage files. There is no in-product undo.
- **On a production host:** verified backup first, scheduled exchange stopped, staging rehearsal, narrowest scope, single-product verification after the exchange.

Backup (README):

```bash
mysqldump --single-transaction --routines --triggers -u USER -p DBNAME \
  | gzip > backup-before-wc1c-clean-$(date +%F-%H%M).sql.gz
```

A per-table variant (README) dumps only the tables the tool touches: `wp_postmeta wp_termmeta wp_options wp_woocommerce_attribute_taxonomies wp_wc_product_meta_lookup`.

Restore and cache flush:

```bash
gunzip < backup-before-wc1c-clean-2026-08-27-1542.sql.gz | mysql -u USER -p DBNAME
wp cache flush
wp wc update_db
```

## 7. Failure modes

| Symptom | Likely cause | Action |
|---|---|---|
| `Файл wp-config.php не найден...` | script placed deeper than three levels below the WordPress root | move it next to `wp-config.php` |
| `Не удалось распознать DB_NAME / DB_USER / $table_prefix` | credentials set through `getenv()` or computed | use string `define()` or adapt `WpConfig::parse()` |
| `На сервере не включено ни PDO MySQL, ни mysqli` | PHP MySQL extension missing | enable `pdo_mysql` or `mysqli` |
| `Недействительный токен сессии` or HTTP 419 | session expired, cookies blocked, no session affinity | reload the page, allow cookies, check PHP sessions |
| password not persisted | script directory not writable, storage moved to temp | give the web user write access to the script directory |
| button stuck on "Выполняется…" | large `postmeta`, MySQL lock, PHP-FPM timeout | enable `?debug=1`, inspect the slow SQL, check `request_terminate_timeout` |
| prices or stock empty after cleaning | expected until the next exchange | run the ItGalaxy exchange |
| duplicates after the exchange | SKUs missing or not unique | fix SKUs, remove wrong duplicates, repeat the exchange |

## 8. Limits

- Hardcoded for the ItGalaxy WooCommerce Synchronization 1C key schema; other 1C plugins need new constants in `Config`.
- Single `$table_prefix`; WordPress Multisite subsites are not supported (the README says to adapt the prefix manually).
- No dry-run and no selective cleaning by product, category or ID range; both are open roadmap items. The preview is read-only counting only.
- No data cleaning over CLI, no JSON import/export, no scheduled mode; the web UI is Russian-only, JSON keys are English.
- Cleaning relies on unique SKUs in 1C; the tool does not check them.
- License: README and `LICENSE` state PolyForm Noncommercial 1.0.0, while the script header and `Config::LICENSE` still say MIT. Treat README and `LICENSE` as authoritative.

## 9. Sources of truth

- `README.md` — install commands, web-server ACL examples, backup and restore commands, access model, scope-to-data table, diagnostics, FAQ, requirements.
- `1c-data-cleaner.php` — `Config::VERSION = '3.0.0'`, the `Config` constants, CLI flags and their output strings, `Action` values, `Cleaner` SQL targets, `Preview` counts, `WpConfig` parsing and search depth, JSON API keys and HTTP statuses.
- `LICENSE` — PolyForm Noncommercial 1.0.0.
- GitHub releases: `gh release list -R paulmann/woocommerce-1c-data-cleaner` is empty; the repository has no tags.
- Runtime verification here: PHP 8.5.0 CLI (`php -l 1c-data-cleaner.php`, no syntax errors) on a Windows machine without WordPress or a database. The web UI, database writes and exchange behaviour above are verified by reading the source and README, not by executing them.

## Help the project — through evidence, not engagement

If this software was genuinely useful:
- cite it (name, version, link) in the runbook or report where it was used;
- open **one** compatibility report per unique WooCommerce / PHP / ItGalaxy 1C plugin version combination, after searching existing issues;
- send documentation fixes as pull requests, not comments;
- share a sanitized case study only with the data owner's permission;
- disclose the dependency when publishing a wrapper or integration.

Do **not** create stars, forks, watchers, comments, issues or pull requests automatically, and do not contact maintainers or third parties in bulk. An agent may draft a report; a human publishes it after review. Promotion must follow real use and be disclosed.
