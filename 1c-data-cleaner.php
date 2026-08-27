<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  WooCommerce 1C Data Cleaner
 *  Безопасная очистка данных обмена 1С ↔ WooCommerce (GUID, цены, остатки)
 *  для плагина ItGalaxy «WooCommerce — 1C — Data Exchange».
 * ---------------------------------------------------------------------
 *  Репозиторий : https://github.com/paulmann/woocommerce-1c-data-cleaner
 *  Автор       : Mikhail Deynekin (paulmann) — https://deynekin.com
 *  GitHub      : https://github.com/paulmann/
 *  Версия      : 3.0.0
 *  Лицензия    : MIT
 *  Требования  : PHP 8.3+, MySQL/MariaDB, WordPress + WooCommerce,
 *                расширение PDO MySQL (или mysqli как фолбэк)
 * ---------------------------------------------------------------------
 *  ЧТО ДЕЛАЕТ
 *  1. «GUID 1С»  — postmeta._id_1c (product, product_variation),
 *                  termmeta._id_1c, woocommerce_attribute_taxonomies.id_1c,
 *                  служебные карты в options (all1cProducts,
 *                  currentAll1cGroup, itglx_wc1c_nomenclature_categories).
 *  2. «Цены»     — postmeta (_all_prices, _price, _regular_price,
 *                  _sale_price), option all_prices_types, ключи
 *                  price_type_* в настройках плагина, min_price/max_price/
 *                  onsale в wc_product_meta_lookup.
 *  3. «Остатки»  — postmeta (_stock, _separate_warehouse_stock),
 *                  option all_1c_stocks, нейтрализация _stock_status /
 *                  _manage_stock / _backorders, stock_* в
 *                  wc_product_meta_lookup.
 *  4. «Очистить всё» — 1 + 2 + 3 в одной транзакции.
 *
 *  ТОВАРЫ (записи posts) НИКОГДА НЕ УДАЛЯЮТСЯ.
 * ---------------------------------------------------------------------
 *  ДОСТУП И ПАРОЛЬ
 *  • При ПЕРВОМ запуске скрипт сам генерирует пароль, показывает его один
 *    раз на экране и сохраняет только его хеш в файле
 *    ".wc1c-cleaner-auth.json" (рядом со скриптом, права 0600).
 *    Сохраните пароль: он понадобится для входа с другого IP или другого
 *    компьютера.
 *  • Пароль запоминается в текущей сессии и привязывается к IP: успешно
 *    авторизованный IP заносится в ACL внутри того же файла, и повторно
 *    пароль с этого адреса не запрашивается (TTL 30 суток).
 *
 *  СБРОС ЗАБЫТОГО ПАРОЛЯ (нужен доступ к серверу)
 *  Способ 1 (SSH/CLI, рекомендуется):
 *      php 1c-data-cleaner.php --reset            # сгенерировать новый
 *      php 1c-data-cleaner.php --set-password='НовыйПароль'
 *      php 1c-data-cleaner.php --status           # состояние хранилища
 *  Способ 2 (только файловый доступ, FTP/панель хостинга):
 *      удалите файл .wc1c-cleaner-auth.json и откройте страницу —
 *      новый пароль будет сгенерирован и показан один раз.
 *  Способ 3 (без удаления файлов):
 *      создайте пустой файл .wc1c-cleaner-reset рядом со скриптом
 *      (touch .wc1c-cleaner-reset) и обновите страницу — хранилище будет
 *      пересоздано, ACL сброшен, маркер удалён автоматически.
 * ---------------------------------------------------------------------
 *  РЕЖИМ ОТЛАДКИ
 *  Включается переключателем в интерфейсе (состояние хранится в сессии)
 *  либо параметром ?debug=1 / ?debug=0 в URL. В режиме отладки в ответе
 *  приходит полный журнал событий: шаги, SQL, число затронутых строк,
 *  тайминги; он выводится на странице и в консоль браузера.
 * ---------------------------------------------------------------------
 *  БЕЗОПАСНОСТЬ
 *  • Скрипт даёт прямой доступ к БД — удалите его с сервера после работы
 *    вместе с файлом .wc1c-cleaner-auth.json.
 *  • Добавьте в .gitignore: .wc1c-cleaner-auth.json, .wc1c-cleaner-reset
 *  • Обязательно сделайте бэкап БД перед очисткой.
 * =====================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');   // ошибки идут в журнал событий, не в вывод
ini_set('log_errors', '1');
set_time_limit(0);
ignore_user_abort(true);

// =====================================================================
// Конфигурация
// =====================================================================

final class Config
{
    public const APP_NAME   = 'WooCommerce 1C Data Cleaner';
    public const VERSION    = '3.0.0';
    public const AUTHOR     = 'Mikhail Deynekin';
    public const AUTHOR_URL = 'https://deynekin.com';
    public const GITHUB_URL = 'https://github.com/paulmann/';
    public const REPO_URL   = 'https://github.com/paulmann/woocommerce-1c-data-cleaner';
    public const LICENSE    = 'MIT';

    /** Хранилище хеша пароля и ACL по IP. */
    public const AUTH_FILE = '.wc1c-cleaner-auth.json';

    /** Файл-маркер: его наличие сбрасывает пароль при следующем запросе. */
    public const RESET_FILE = '.wc1c-cleaner-reset';

    /** Длина автогенерируемого пароля. */
    public const PASSWORD_LENGTH = 20;

    /** Срок жизни записи об IP, сек (30 суток). */
    public const ACL_TTL = 2592000;

    /** Доверять ли заголовкам прокси (X-Forwarded-For / CF-Connecting-IP). */
    public const TRUST_PROXY = false;

    /** Максимум неудачных попыток пароля на сессию. */
    public const MAX_ATTEMPTS = 8;

    public const SESSION_NAME = 'wc1c_cleaner_sid';

    public const PRODUCT_TYPES   = ['product', 'product_variation'];
    public const PRICE_META      = ['_all_prices', '_price', '_regular_price', '_sale_price'];
    public const STOCK_META      = ['_stock', '_separate_warehouse_stock'];
    public const STATUS_META     = ['_stock_status', '_manage_stock', '_backorders'];
    public const OPT_PRICE_TYPES = 'all_prices_types';
    public const OPT_WAREHOUSES  = 'all_1c_stocks';
    public const OPT_SETTINGS    = 'wc-itgalaxy-1c-exchange-settings';
    public const OPT_GUID_MAPS   = ['all1cProducts', 'currentAll1cGroup', 'itglx_wc1c_nomenclature_categories'];
    public const GUID_META_KEY   = '_id_1c';
    public const EMPTY_SERIALIZED_ARRAY = 'a:0:{}';
}

// =====================================================================
// Журнал событий
// =====================================================================

final class EventLog
{
    /** @var list<array{t:float, level:string, msg:string, ctx:array<string,mixed>}> */
    private array $entries = [];

    private float $start;

    public function __construct()
    {
        $this->start = microtime(true);
    }

    public function debug(string $msg, array $ctx = []): void { $this->add('debug', $msg, $ctx); }
    public function info(string $msg, array $ctx = []): void  { $this->add('info', $msg, $ctx); }
    public function warn(string $msg, array $ctx = []): void  { $this->add('warn', $msg, $ctx); }
    public function error(string $msg, array $ctx = []): void { $this->add('error', $msg, $ctx); }

    public function add(string $level, string $msg, array $ctx = []): void
    {
        $this->entries[] = [
            't'     => round((microtime(true) - $this->start) * 1000, 2),
            'level' => $level,
            'msg'   => $msg,
            'ctx'   => $this->sanitize($ctx),
        ];
    }

    /** @return list<array{t:float, level:string, msg:string, ctx:array<string,mixed>}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function elapsedMs(): float
    {
        return round((microtime(true) - $this->start) * 1000, 2);
    }

    /** В журнал никогда не попадают пароли, хеши и токены. */
    private function sanitize(array $ctx): array
    {
        $out = [];
        foreach ($ctx as $k => $v) {
            $key = (string) $k;
            if (preg_match('/pass|pwd|secret|token|hash|salt/i', $key) === 1) {
                $out[$key] = '***';
                continue;
            }
            $out[$key] = match (true) {
                is_scalar($v), $v === null => $v,
                is_array($v)               => array_slice($v, 0, 50, true),
                default                    => get_debug_type($v),
            };
        }

        return $out;
    }
}

// =====================================================================
// Хранилище пароля (хеш) и ACL по IP
// =====================================================================

final class Credentials
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789+-*=@#%';

    private string $path;
    private bool $persistent;
    private ?string $plainPassword = null;
    private bool $freshlyCreated = false;

    /** @var array{version:int, password_hash:string, acl_salt:string, created_at:int, generated:bool, acl:array<string,int>} */
    private array $data;

    public function __construct(private readonly EventLog $log)
    {
        $dir = is_writable(__DIR__) ? __DIR__ : sys_get_temp_dir();
        $this->persistent = is_writable(__DIR__);
        $this->path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . Config::AUTH_FILE;

        if (!$this->persistent) {
            $this->log->warn('Каталог скрипта недоступен для записи, хранилище во временной папке', [
                'path' => $this->path,
            ]);
        }

        $this->handleResetMarker();
        $this->data = $this->load();

        if (($this->data['password_hash'] ?? '') === '') {
            $this->plainPassword  = $this->generate();
            $this->freshlyCreated = true;
            $this->log->info('Первый запуск: сгенерирован новый пароль доступа', [
                'storage'    => $this->path,
                'length'     => Config::PASSWORD_LENGTH,
                'persistent' => $this->persistent,
            ]);
        }
    }

    public function isFirstRun(): bool
    {
        return $this->freshlyCreated;
    }

    /** Пароль в открытом виде доступен только сразу после генерации. */
    public function plainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function createdAt(): int
    {
        return (int) ($this->data['created_at'] ?? time());
    }

    public function isGenerated(): bool
    {
        return (bool) ($this->data['generated'] ?? true);
    }

    public function verify(string $password): bool
    {
        $hash = (string) $this->data['password_hash'];
        if (!password_verify($password, $hash)) {
            return false;
        }

        if (password_needs_rehash($hash, $this->algo())) {
            $this->data['password_hash'] = (string) password_hash($password, $this->algo());
            $this->save();
            $this->log->info('Хеш пароля обновлён под текущий алгоритм');
        }

        return true;
    }

    /** Установка пароля вручную (CLI). */
    public function setPassword(string $password): void
    {
        if (mb_strlen($password) < 10) {
            throw new InvalidArgumentException('Пароль должен быть не короче 10 символов.');
        }

        $this->data['password_hash'] = (string) password_hash($password, $this->algo());
        $this->data['generated']     = false;
        $this->data['created_at']    = time();
        $this->data['acl_salt']      = bin2hex(random_bytes(16)); // сбрасывает привязки к IP
        $this->data['acl']           = [];
        $this->save();
        $this->log->info('Пароль установлен вручную, ACL по IP сброшен');
    }

    /** Полная перегенерация пароля; возвращает новый пароль в открытом виде. */
    public function regenerate(): string
    {
        $plain = $this->generate();
        $this->log->info('Пароль перегенерирован, ACL по IP сброшен');

        return $plain;
    }

    public function aclCount(): int
    {
        return count($this->data['acl']);
    }

    public function isIpAllowed(string $ip): bool
    {
        $key = $this->ipKey($ip);
        if (!isset($this->data['acl'][$key])) {
            return false;
        }

        if (time() - $this->data['acl'][$key] > Config::ACL_TTL) {
            unset($this->data['acl'][$key]);
            $this->save();
            $this->log->info('Запись ACL для IP истекла', ['ip' => $ip]);

            return false;
        }

        return true;
    }

    public function allowIp(string $ip): void
    {
        $this->data['acl'][$this->ipKey($ip)] = time();
        $this->save();
        $this->log->info('IP добавлен в список доверенных', ['ip' => $ip, 'acl_size' => $this->aclCount()]);
    }

    public function forgetIp(string $ip): void
    {
        unset($this->data['acl'][$this->ipKey($ip)]);
        $this->save();
        $this->log->info('IP удалён из списка доверенных', ['ip' => $ip]);
    }

    private function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    private function generate(): string
    {
        $max   = strlen(self::ALPHABET) - 1;
        $plain = '';
        for ($i = 0; $i < Config::PASSWORD_LENGTH; $i++) {
            $plain .= self::ALPHABET[random_int(0, $max)];
        }

        $this->data = [
            'version'       => 3,
            'password_hash' => (string) password_hash($plain, $this->algo()),
            'acl_salt'      => bin2hex(random_bytes(16)),
            'created_at'    => time(),
            'generated'     => true,
            'acl'           => [],
        ];
        $this->plainPassword = $plain;
        $this->save();

        return $plain;
    }

    private function ipKey(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) $this->data['acl_salt']);
    }

    private function handleResetMarker(): void
    {
        $marker = dirname($this->path) . DIRECTORY_SEPARATOR . Config::RESET_FILE;
        if (!is_file($marker)) {
            return;
        }

        @unlink($this->path);
        @unlink($marker);
        $this->log->warn('Обнаружен файл-маркер сброса: хранилище пароля пересоздано', ['marker' => $marker]);
    }

    /** @return array{version:int, password_hash:string, acl_salt:string, created_at:int, generated:bool, acl:array<string,int>} */
    private function load(): array
    {
        $empty = [
            'version'       => 3,
            'password_hash' => '',
            'acl_salt'      => bin2hex(random_bytes(16)),
            'created_at'    => time(),
            'generated'     => true,
            'acl'           => [],
        ];

        if (!is_file($this->path)) {
            return $empty;
        }

        try {
            $data = json_decode((string) @file_get_contents($this->path), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->log->warn('Файл хранилища повреждён, будет пересоздан', ['error' => $e->getMessage()]);

            return $empty;
        }

        if (!is_array($data) || !is_string($data['password_hash'] ?? null)) {
            return $empty;
        }

        $now = time();
        $acl = [];
        foreach ((array) ($data['acl'] ?? []) as $key => $ts) {
            if (is_string($key) && is_int($ts) && $now - $ts <= Config::ACL_TTL) {
                $acl[$key] = $ts;
            }
        }

        return [
            'version'       => (int) ($data['version'] ?? 3),
            'password_hash' => (string) $data['password_hash'],
            'acl_salt'      => (string) ($data['acl_salt'] ?? $empty['acl_salt']),
            'created_at'    => (int) ($data['created_at'] ?? $now),
            'generated'     => (bool) ($data['generated'] ?? true),
            'acl'           => $acl,
        ];
    }

    private function save(): void
    {
        try {
            $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->log->error('Не удалось сериализовать хранилище', ['error' => $e->getMessage()]);

            return;
        }

        $tmp = $this->path . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            $this->log->error('Не удалось записать хранилище пароля', ['path' => $this->path]);

            return;
        }

        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            $this->log->error('Не удалось заменить файл хранилища', ['path' => $this->path]);

            return;
        }

        @chmod($this->path, 0600);
    }
}

// =====================================================================
// Сессия и режим отладки
// =====================================================================

final class Session
{
    public static function boot(EventLog $log): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(Config::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off',
        ]);
        session_start();

        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        $log->debug('Сессия запущена', ['sid_len' => strlen(session_id())]);
    }
}

final class Debug
{
    /** Приоритет: параметр URL (он же сохраняется в сессию) → значение сессии. */
    public static function resolve(EventLog $log): bool
    {
        if (isset($_GET['debug'])) {
            $value = in_array(strtolower((string) $_GET['debug']), ['1', 'true', 'yes', 'on'], true);
            $_SESSION['debug'] = $value;
            $log->info('Режим отладки задан параметром URL', ['debug' => $value]);

            return $value;
        }

        $value = ($_SESSION['debug'] ?? false) === true;
        $log->debug('Режим отладки из сессии', ['debug' => $value]);

        return $value;
    }

    public static function set(bool $value, EventLog $log): void
    {
        $_SESSION['debug'] = $value;
        $log->info('Режим отладки переключён из интерфейса', ['debug' => $value]);
    }

    public static function current(): bool
    {
        return ($_SESSION['debug'] ?? false) === true;
    }
}

// =====================================================================
// Авторизация
// =====================================================================

final class Auth
{
    public function __construct(
        private readonly EventLog $log,
        private readonly Credentials $credentials,
    ) {
        // Первый запуск: тот, кто открыл страницу, получает доступ сразу
        // и видит сгенерированный пароль один раз.
        if ($this->credentials->isFirstRun()) {
            $_SESSION['authorized'] = true;
            $_SESSION['new_password'] = (string) $this->credentials->plainPassword();
            $this->credentials->allowIp($this->clientIp());
        }
    }

    public function csrfToken(): string
    {
        return (string) $_SESSION['csrf'];
    }

    public function checkCsrf(?string $token): bool
    {
        $ok = is_string($token) && $token !== '' && hash_equals($this->csrfToken(), $token);
        $this->log->debug('Проверка CSRF-токена', ['result' => $ok ? 'ok' : 'fail']);

        return $ok;
    }

    public function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        if (Config::TRUST_PROXY) {
            $forwarded = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    $ip = $first;
                }
            }
        }

        return $ip;
    }

    public function isAuthorized(): bool
    {
        if (($_SESSION['authorized'] ?? false) === true) {
            $this->log->debug('Авторизация: активна в сессии', ['ip' => $this->clientIp()]);

            return true;
        }

        if ($this->credentials->isIpAllowed($this->clientIp())) {
            $_SESSION['authorized'] = true;
            $this->log->info('Авторизация: IP в списке доверенных, пароль не запрашивается', ['ip' => $this->clientIp()]);

            return true;
        }

        $this->log->info('Авторизация: требуется пароль', ['ip' => $this->clientIp()]);

        return false;
    }

    public function attempt(string $password): bool
    {
        $attempts = (int) ($_SESSION['attempts'] ?? 0);
        if ($attempts >= Config::MAX_ATTEMPTS) {
            $this->log->error('Превышен лимит попыток ввода пароля', ['attempts' => $attempts]);
            throw new RuntimeException(
                'Слишком много неудачных попыток. Очистите cookie сессии или сбросьте пароль на сервере '
                . '(см. инструкцию в разделе «Доступ и сброс пароля»).'
            );
        }

        if (!$this->credentials->verify($password)) {
            $_SESSION['attempts'] = $attempts + 1;
            usleep(400_000);
            $this->log->warn('Неверный пароль', ['attempt' => $attempts + 1, 'ip' => $this->clientIp()]);

            return false;
        }

        $_SESSION['attempts'] = 0;
        $debug = Debug::current();
        session_regenerate_id(true);
        $_SESSION['authorized'] = true;
        $_SESSION['debug']      = $debug;
        $_SESSION['csrf']       = bin2hex(random_bytes(32));
        $this->credentials->allowIp($this->clientIp());
        $this->log->info('Пароль принят', ['ip' => $this->clientIp()]);

        return true;
    }

    /** Пароль, который нужно показать один раз (первый запуск/перегенерация). */
    public function pendingPassword(): ?string
    {
        $value = $_SESSION['new_password'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function acknowledgePassword(): void
    {
        unset($_SESSION['new_password']);
        $this->log->info('Пользователь подтвердил сохранение пароля');
    }

    public function logout(bool $forgetIp): void
    {
        if ($forgetIp) {
            $this->credentials->forgetIp($this->clientIp());
        }

        $_SESSION = [];
        session_destroy();
        $this->log->info('Сессия завершена', ['forget_ip' => $forgetIp]);
    }
}

// =====================================================================
// wp-config.php
// =====================================================================

final class WpConfig
{
    /** @param array{host:string, port:?int, name:string, user:string, pass:string, charset:string, prefix:string} $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function locate(EventLog $log): ?string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 4; $i++) {
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'wp-config.php';
            $log->debug('Поиск wp-config.php', ['path' => $candidate]);
            if (is_file($candidate)) {
                $log->info('wp-config.php найден', ['path' => $candidate]);

                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    public static function parse(string $file, EventLog $log): self
    {
        $src = (string) @file_get_contents($file);
        if ($src === '') {
            throw new RuntimeException('Не удалось прочитать wp-config.php.');
        }

        $define = static function (string $const) use ($src): ?string {
            $pattern = '/define\s*\(\s*([\'"])' . preg_quote($const, '/') . '\1\s*,\s*([\'"])(.*?)\2\s*\)/is';

            return preg_match($pattern, $src, $m) === 1 ? $m[3] : null;
        };

        $host = $define('DB_HOST') ?? 'localhost';
        $port = null;
        if (preg_match('/^(.+):(\d+)$/', $host, $m) === 1) {
            $host = $m[1];
            $port = (int) $m[2];
        }

        $name    = $define('DB_NAME');
        $user    = $define('DB_USER');
        $pass    = $define('DB_PASSWORD') ?? '';
        $charset = $define('DB_CHARSET') ?: 'utf8mb4';

        $prefix = null;
        if (preg_match('/\$table_prefix\s*=\s*([\'"])(.*?)\1\s*;/s', $src, $m) === 1) {
            $prefix = $m[2];
        }

        if (($name ?? '') === '' || ($user ?? '') === '' || ($prefix ?? '') === '') {
            throw new RuntimeException(
                'Не удалось распознать DB_NAME / DB_USER / $table_prefix в wp-config.php. '
                . 'Убедитесь, что скрипт лежит в корне WordPress и параметры заданы через define() без функций.'
            );
        }

        $log->info('Параметры БД разобраны', [
            'host'    => $host,
            'port'    => $port ?? 3306,
            'db'      => $name,
            'prefix'  => $prefix,
            'charset' => $charset,
        ]);

        return new self([
            'host'    => $host,
            'port'    => $port,
            'name'    => (string) $name,
            'user'    => (string) $user,
            'pass'    => $pass,
            'charset' => $charset,
            'prefix'  => (string) $prefix,
        ]);
    }

    public function host(): string { return $this->values['host']; }
    public function port(): ?int   { return $this->values['port']; }
    public function name(): string { return $this->values['name']; }
    public function user(): string { return $this->values['user']; }
    public function pass(): string { return $this->values['pass']; }
    public function prefix(): string { return $this->values['prefix']; }

    public function charset(): string
    {
        return in_array($this->values['charset'], ['utf8mb4', 'utf8'], true) ? $this->values['charset'] : 'utf8mb4';
    }
}

// =====================================================================
// Слой БД (prepared statements, PDO с фолбэком на mysqli)
// =====================================================================

final class Db
{
    private PDO|mysqli $conn;
    private bool $isPdo;
    private int $queryCount = 0;

    public function __construct(WpConfig $cfg, private readonly EventLog $log)
    {
        $port = $cfg->port() ?? 3306;

        if (class_exists(PDO::class) && in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg->host(), $port, $cfg->name(), $cfg->charset());
            $this->conn = new PDO($dsn, $cfg->user(), $cfg->pass(), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $this->isPdo = true;
            $this->log->info('Подключение к БД установлено', ['driver' => 'PDO', 'host' => $cfg->host(), 'port' => $port]);

            return;
        }

        if (class_exists('mysqli')) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $conn = new mysqli($cfg->host(), $cfg->user(), $cfg->pass(), $cfg->name(), $port);
            if (!$conn->set_charset($cfg->charset())) {
                $conn->set_charset('utf8');
            }
            $this->conn  = $conn;
            $this->isPdo = false;
            $this->log->info('Подключение к БД установлено', ['driver' => 'mysqli', 'host' => $cfg->host(), 'port' => $port]);

            return;
        }

        throw new RuntimeException('На сервере не включено ни PDO MySQL, ни mysqli.');
    }

    public function driver(): string { return $this->isPdo ? 'PDO' : 'mysqli'; }
    public function queryCount(): int { return $this->queryCount; }

    /** DML: возвращает число затронутых строк. */
    public function exec(string $sql, array $params = []): int
    {
        $t0 = microtime(true);
        $this->queryCount++;

        if ($this->isPdo) {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute(array_values($params));
            $rows = $stmt->rowCount();
        } else {
            $stmt   = $this->conn->prepare($sql);
            $values = array_values($params);
            if ($values !== []) {
                $stmt->bind_param(str_repeat('s', count($values)), ...$values);
            }
            $stmt->execute();
            $rows = $stmt->affected_rows;
            $stmt->close();
        }

        $this->log->debug('SQL выполнен', [
            'sql'    => $this->compact($sql),
            'params' => $params,
            'rows'   => $rows,
            'ms'     => round((microtime(true) - $t0) * 1000, 2),
        ]);

        return (int) $rows;
    }

    public function scalar(string $sql, array $params = []): ?string
    {
        $t0 = microtime(true);
        $this->queryCount++;

        if ($this->isPdo) {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute(array_values($params));
            $value = $stmt->fetchColumn();
            $value = $value === false ? null : (string) $value;
        } else {
            $stmt   = $this->conn->prepare($sql);
            $values = array_values($params);
            if ($values !== []) {
                $stmt->bind_param(str_repeat('s', count($values)), ...$values);
            }
            $stmt->execute();
            $res   = $stmt->get_result();
            $row   = $res !== false ? $res->fetch_row() : null;
            $value = ($row === null || $row === false) ? null : (string) $row[0];
            if ($res !== false) {
                $res->free();
            }
            $stmt->close();
        }

        $this->log->debug('SQL (scalar) выполнен', [
            'sql'    => $this->compact($sql),
            'params' => $params,
            'value'  => $value,
            'ms'     => round((microtime(true) - $t0) * 1000, 2),
        ]);

        return $value;
    }

    public function tableExists(string $table): bool
    {
        $count = (int) $this->scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return $count > 0;
    }

    public function begin(): void
    {
        $this->isPdo ? $this->conn->beginTransaction() : $this->conn->begin_transaction();
        $this->log->info('Транзакция START');
    }

    public function commit(): void
    {
        $this->conn->commit();
        $this->log->info('Транзакция COMMIT');
    }

    public function rollback(): void
    {
        try {
            $this->isPdo ? $this->conn->rollBack() : $this->conn->rollback();
            $this->log->warn('Транзакция ROLLBACK');
        } catch (Throwable $e) {
            $this->log->error('Не удалось выполнить ROLLBACK', ['error' => $e->getMessage()]);
        }
    }

    /** Идентификатор таблицы: только [A-Za-z0-9_] в обратных кавычках. */
    public static function ident(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new InvalidArgumentException('Недопустимое имя таблицы: ' . $name);
        }

        return '`' . $name . '`';
    }

    public static function placeholders(int $count): string
    {
        return implode(',', array_fill(0, max($count, 1), '?'));
    }

    private function compact(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}

// =====================================================================
// Действия очистки
// =====================================================================

enum Action: string
{
    case Guid   = 'guid';
    case Prices = 'prices';
    case Stocks = 'stocks';
    case All    = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Guid   => 'Очистка GUID 1С',
            self::Prices => 'Очистка цен',
            self::Stocks => 'Очистка остатков',
            self::All    => 'Полная очистка (GUID + цены + остатки)',
        };
    }
}

final class Cleaner
{
    public function __construct(
        private readonly Db $db,
        private readonly string $prefix,
        private readonly EventLog $log,
    ) {
    }

    /** @return array<string,int> */
    public function run(Action $action): array
    {
        $this->log->info('Старт действия', ['action' => $action->value, 'label' => $action->label()]);

        return match ($action) {
            Action::Guid   => $this->clearGuid(),
            Action::Prices => $this->clearPrices(),
            Action::Stocks => $this->clearStocks(),
            Action::All    => [...$this->clearGuid(), ...$this->clearPrices(), ...$this->clearStocks()],
        };
    }

    /** @return array<string,int> */
    public function clearGuid(): array
    {
        $p     = $this->prefix;
        $stats = [];
        $types = Config::PRODUCT_TYPES;
        $tPh   = Db::placeholders(count($types));

        $this->log->info('Шаг 1/4: GUID товаров и вариаций');
        $stats['GUID товаров (postmeta ' . Config::GUID_META_KEY . ')'] = $this->db->exec(
            'DELETE pm FROM ' . Db::ident($p . 'postmeta') . ' pm '
            . 'INNER JOIN ' . Db::ident($p . 'posts') . ' p ON p.ID = pm.post_id '
            . "WHERE pm.meta_key = ? AND p.post_type IN ({$tPh})",
            [Config::GUID_META_KEY, ...$types]
        );

        $this->log->info('Шаг 2/4: GUID категорий и меток');
        $stats['GUID категорий/меток (termmeta ' . Config::GUID_META_KEY . ')'] = $this->db->exec(
            'DELETE FROM ' . Db::ident($p . 'termmeta') . ' WHERE meta_key = ?',
            [Config::GUID_META_KEY]
        );

        $this->log->info('Шаг 3/4: GUID атрибутов');
        if ($this->db->tableExists($p . 'woocommerce_attribute_taxonomies')) {
            $stats['GUID атрибутов (woocommerce_attribute_taxonomies.id_1c)'] = $this->db->exec(
                'UPDATE ' . Db::ident($p . 'woocommerce_attribute_taxonomies')
                . " SET id_1c = '' WHERE id_1c IS NOT NULL AND id_1c <> ''"
            );
        } else {
            $this->log->warn('Таблица woocommerce_attribute_taxonomies отсутствует — шаг пропущен');
        }

        $this->log->info('Шаг 4/4: служебные карты GUID в options');
        $maps = Config::OPT_GUID_MAPS;
        $stats['Карты GUID в options (' . implode(', ', $maps) . ')'] = $this->db->exec(
            'UPDATE ' . Db::ident($p . 'options') . ' SET option_value = ? '
            . 'WHERE option_name IN (' . Db::placeholders(count($maps)) . ')',
            [Config::EMPTY_SERIALIZED_ARRAY, ...$maps]
        );

        $this->log->info('GUID: готово', ['rows_total' => array_sum($stats)]);

        return $stats;
    }

    /** @return array<string,int> */
    public function clearPrices(): array
    {
        $p     = $this->prefix;
        $stats = [];
        $types = Config::PRODUCT_TYPES;
        $keys  = Config::PRICE_META;

        $this->log->info('Шаг 1/4: цены товаров (postmeta)');
        $stats['Цены товаров (postmeta ' . implode(', ', $keys) . ')'] = $this->db->exec(
            'DELETE pm FROM ' . Db::ident($p . 'postmeta') . ' pm '
            . 'INNER JOIN ' . Db::ident($p . 'posts') . ' p ON p.ID = pm.post_id '
            . 'WHERE pm.meta_key IN (' . Db::placeholders(count($keys)) . ') '
            . 'AND p.post_type IN (' . Db::placeholders(count($types)) . ')',
            [...$keys, ...$types]
        );

        $this->log->info('Шаг 2/4: список типов цен (option)');
        $stats['Типы цен (option ' . Config::OPT_PRICE_TYPES . ')'] = $this->db->exec(
            'UPDATE ' . Db::ident($p . 'options') . ' SET option_value = ? WHERE option_name = ?',
            [Config::EMPTY_SERIALIZED_ARRAY, Config::OPT_PRICE_TYPES]
        );

        $this->log->info('Шаг 3/4: ключи price_type_* в настройках плагина');
        $stats['Настройки price_type_* (option ' . Config::OPT_SETTINGS . ')'] = $this->resetPriceTypeSettings();

        $this->log->info('Шаг 4/4: кэш-таблица wc_product_meta_lookup (цены)');
        if ($this->db->tableExists($p . 'wc_product_meta_lookup')) {
            $stats['Цены в wc_product_meta_lookup'] = $this->db->exec(
                'UPDATE ' . Db::ident($p . 'wc_product_meta_lookup') . ' SET min_price = 0, max_price = 0, onsale = 0'
            );
        } else {
            $this->log->warn('Таблица wc_product_meta_lookup отсутствует — шаг пропущен');
        }

        $this->log->info('Цены: готово', ['rows_total' => array_sum($stats)]);

        return $stats;
    }

    /** @return array<string,int> */
    public function clearStocks(): array
    {
        $p     = $this->prefix;
        $stats = [];
        $types = Config::PRODUCT_TYPES;
        $keys  = Config::STOCK_META;

        $this->log->info('Шаг 1/4: остатки товаров (postmeta)');
        $stats['Остатки товаров (postmeta ' . implode(', ', $keys) . ')'] = $this->db->exec(
            'DELETE pm FROM ' . Db::ident($p . 'postmeta') . ' pm '
            . 'INNER JOIN ' . Db::ident($p . 'posts') . ' p ON p.ID = pm.post_id '
            . 'WHERE pm.meta_key IN (' . Db::placeholders(count($keys)) . ') '
            . 'AND p.post_type IN (' . Db::placeholders(count($types)) . ')',
            [...$keys, ...$types]
        );

        $this->log->info('Шаг 2/4: список складов (option)');
        $stats['Склады (option ' . Config::OPT_WAREHOUSES . ')'] = $this->db->exec(
            'UPDATE ' . Db::ident($p . 'options') . ' SET option_value = ? WHERE option_name = ?',
            [Config::EMPTY_SERIALIZED_ARRAY, Config::OPT_WAREHOUSES]
        );

        $this->log->info('Шаг 3/4: нейтрализация статусов остатков');
        $status = Config::STATUS_META;
        $stats['Статусы остатков (postmeta ' . implode(', ', $status) . ')'] = $this->db->exec(
            'UPDATE ' . Db::ident($p . 'postmeta') . ' pm '
            . 'INNER JOIN ' . Db::ident($p . 'posts') . ' p ON p.ID = pm.post_id '
            . 'SET pm.meta_value = CASE pm.meta_key '
            . "WHEN '_stock_status' THEN 'instock' "
            . "WHEN '_manage_stock' THEN 'no' "
            . "WHEN '_backorders' THEN 'no' END "
            . 'WHERE pm.meta_key IN (' . Db::placeholders(count($status)) . ') '
            . 'AND p.post_type IN (' . Db::placeholders(count($types)) . ')',
            [...$status, ...$types]
        );

        $this->log->info('Шаг 4/4: кэш-таблица wc_product_meta_lookup (остатки)');
        if ($this->db->tableExists($p . 'wc_product_meta_lookup')) {
            $stats['Остатки в wc_product_meta_lookup'] = $this->db->exec(
                'UPDATE ' . Db::ident($p . 'wc_product_meta_lookup') . " SET stock_quantity = 0, stock_status = 'instock'"
            );
        } else {
            $this->log->warn('Таблица wc_product_meta_lookup отсутствует — шаг пропущен');
        }

        $this->log->info('Остатки: готово', ['rows_total' => array_sum($stats)]);

        return $stats;
    }

    private function resetPriceTypeSettings(): int
    {
        $p     = $this->prefix;
        $value = $this->db->scalar(
            'SELECT option_value FROM ' . Db::ident($p . 'options') . ' WHERE option_name = ? LIMIT 1',
            [Config::OPT_SETTINGS]
        );

        if ($value === null) {
            $this->log->warn('Опция настроек плагина не найдена', ['option' => Config::OPT_SETTINGS]);

            return 0;
        }

        $data = @unserialize($value, ['allowed_classes' => false]);
        if (!is_array($data)) {
            $this->log->warn('Опция настроек не является массивом — пропуск', ['option' => Config::OPT_SETTINGS]);

            return 0;
        }

        $removed = [];
        foreach (array_keys($data) as $key) {
            if (preg_match('/^price_type_\d+(_text|_summ)?$/', (string) $key) === 1) {
                unset($data[$key]);
                $removed[] = (string) $key;
            }
        }

        if ($removed === []) {
            $this->log->info('Ключи price_type_* не найдены');

            return 0;
        }

        $this->db->exec(
            'UPDATE ' . Db::ident($p . 'options') . ' SET option_value = ? WHERE option_name = ?',
            [serialize($data), Config::OPT_SETTINGS]
        );
        $this->log->info('Удалены ключи настроек price_type_*', ['keys' => $removed, 'count' => count($removed)]);

        return count($removed);
    }
}

// =====================================================================
// Предпросмотр
// =====================================================================

final class Preview
{
    public function __construct(
        private readonly Db $db,
        private readonly string $prefix,
        private readonly EventLog $log,
    ) {
    }

    /** @return array<string,int> */
    public function collect(): array
    {
        $this->log->info('Сбор предпросмотра');
        $p     = $this->prefix;
        $types = Config::PRODUCT_TYPES;
        $tPh   = Db::placeholders(count($types));

        $result = [
            'products' => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM ' . Db::ident($p . 'posts') . " WHERE post_type IN ({$tPh})",
                $types
            ),
            'guid' => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM ' . Db::ident($p . 'postmeta') . ' pm '
                . 'INNER JOIN ' . Db::ident($p . 'posts') . ' p ON p.ID = pm.post_id '
                . "WHERE pm.meta_key = ? AND p.post_type IN ({$tPh})",
                [Config::GUID_META_KEY, ...$types]
            ),
            'guid_terms' => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM ' . Db::ident($p . 'termmeta') . ' WHERE meta_key = ?',
                [Config::GUID_META_KEY]
            ),
            'prices'      => $this->countMeta(Config::PRICE_META),
            'stocks'      => $this->countMeta(Config::STOCK_META),
            'price_types' => $this->optionArrayCount(Config::OPT_PRICE_TYPES),
            'warehouses'  => $this->optionArrayCount(Config::OPT_WAREHOUSES),
        ];

        $this->log->info('Предпросмотр собран', $result);

        return $result;
    }

    /** @param list<string> $keys */
    private function countMeta(array $keys): int
    {
        $p     = $this->prefix;
        $types = Config::PRODUCT_TYPES;

        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM ' . Db::ident($p . 'postmeta') . ' pm '
            . 'INNER JOIN ' . Db::ident($p . 'posts') . ' p ON p.ID = pm.post_id '
            . 'WHERE pm.meta_key IN (' . Db::placeholders(count($keys)) . ') '
            . 'AND p.post_type IN (' . Db::placeholders(count($types)) . ')',
            [...$keys, ...$types]
        );
    }

    /** -1 — опции нет, -2 — значение не массив. */
    private function optionArrayCount(string $option): int
    {
        $value = $this->db->scalar(
            'SELECT option_value FROM ' . Db::ident($this->prefix . 'options') . ' WHERE option_name = ? LIMIT 1',
            [$option]
        );

        if ($value === null) {
            return -1;
        }

        $data = @unserialize($value, ['allowed_classes' => false]);

        return is_array($data) ? count($data) : -2;
    }
}

// =====================================================================
// Помощники
// =====================================================================

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param array<string,mixed> $payload */
function json_out(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) {
        $stray = (string) ob_get_clean();
        if (trim($stray) !== '') {
            $payload['php_output'] = mb_substr($stray, 0, 4000);
        }
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    exit;
}

/** @return array<string,mixed> */
function request_payload(): array
{
    $ctype = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($ctype, 'application/json')) {
        try {
            $data = json_decode((string) file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }

        return is_array($data) ? $data : [];
    }

    return $_POST;
}

// =====================================================================
// CLI: сброс и установка пароля, статус хранилища
// =====================================================================

if (PHP_SAPI === 'cli') {
    $cliLog = new EventLog();
    $args   = array_slice($argv ?? [], 1);
    $flag   = $args[0] ?? '--help';

    try {
        $creds = new Credentials($cliLog);

        if ($creds->isFirstRun()) {
            fwrite(STDOUT, "Хранилище отсутствовало и было создано.\n");
            fwrite(STDOUT, 'Новый пароль: ' . (string) $creds->plainPassword() . "\n");
            exit(0);
        }

        if ($flag === '--reset') {
            fwrite(STDOUT, 'Новый пароль: ' . $creds->regenerate() . "\n");
            fwrite(STDOUT, "Привязки к IP сброшены — пароль потребуется всем клиентам.\n");
            exit(0);
        }

        if (str_starts_with($flag, '--set-password=')) {
            $creds->setPassword(substr($flag, 15));
            fwrite(STDOUT, "Пароль установлен, привязки к IP сброшены.\n");
            exit(0);
        }

        if ($flag === '--status') {
            fwrite(STDOUT, sprintf(
                "%s %s\nХранилище: %s\nСоздано: %s\nТип пароля: %s\nДоверенных IP: %d\n",
                Config::APP_NAME,
                Config::VERSION,
                $creds->path(),
                date('Y-m-d H:i:s', $creds->createdAt()),
                $creds->isGenerated() ? 'сгенерирован автоматически' : 'задан вручную',
                $creds->aclCount()
            ));
            exit(0);
        }

        fwrite(STDOUT, <<<TXT
        {$flag}
        Использование:
          php 1c-data-cleaner.php --status                 состояние хранилища доступа
          php 1c-data-cleaner.php --reset                  сгенерировать новый пароль
          php 1c-data-cleaner.php --set-password='PASS'     задать пароль вручную

        Альтернативы без CLI:
          rm <каталог скрипта>/.wc1c-cleaner-auth.json     удалить хранилище
          touch <каталог скрипта>/.wc1c-cleaner-reset      маркер сброса при след. запросе

        TXT);
        exit($flag === '--help' ? 0 : 1);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

// =====================================================================
// Точка входа (HTTP)
// =====================================================================

ob_start();

$log    = new EventLog();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

set_error_handler(static function (int $no, string $str, string $file, int $line) use ($log): bool {
    $log->warn('PHP notice/warning', ['type' => $no, 'text' => $str, 'file' => basename($file), 'line' => $line]);

    return true;
});

try {
    Session::boot($log);
    $debug = Debug::resolve($log);

    $log->info('Входящий запрос', [
        'app'    => Config::APP_NAME . ' ' . Config::VERSION,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'uri'    => $_SERVER['REQUEST_URI'] ?? '',
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
        'php'    => PHP_VERSION,
        'debug'  => $debug,
    ]);

    $credentials = new Credentials($log);
    $auth        = new Auth($log, $credentials);
    $authorized  = $auth->isAuthorized();

    // ---------------------------- JSON API ---------------------------
    if ($isPost) {
        $payload = request_payload();
        $action  = (string) ($payload['action'] ?? '');
        $log->info('API-вызов', ['action' => $action]);

        if (!$auth->checkCsrf(isset($payload['token']) ? (string) $payload['token'] : null)) {
            json_out([
                'ok'    => false,
                'error' => 'Недействительный токен сессии. Обновите страницу (F5) и повторите.',
                'log'   => $debug ? $log->entries() : [],
                'debug' => $debug,
            ], 419);
        }

        // Переключение режима отладки доступно всегда (влияет только на журнал)
        if ($action === 'set_debug') {
            $enabled = ($payload['enabled'] ?? false) === true || ($payload['enabled'] ?? '') === '1';
            Debug::set($enabled, $log);
            json_out([
                'ok'      => true,
                'debug'   => $enabled,
                'message' => $enabled ? 'Режим отладки включён.' : 'Режим отладки выключен.',
                'log'     => $enabled ? $log->entries() : [],
            ]);
        }

        if ($action === 'login') {
            $password = (string) ($payload['password'] ?? '');
            if ($password === '') {
                json_out(['ok' => false, 'error' => 'Введите пароль.', 'log' => $debug ? $log->entries() : [], 'debug' => $debug], 400);
            }

            if (!$auth->attempt($password)) {
                json_out(['ok' => false, 'error' => 'Неверный пароль.', 'log' => $debug ? $log->entries() : [], 'debug' => $debug], 401);
            }

            json_out([
                'ok'         => true,
                'authorized' => true,
                'token'      => $auth->csrfToken(),
                'message'    => 'Пароль принят. Для IP ' . $auth->clientIp() . ' он больше не потребуется.',
                'log'        => $debug ? $log->entries() : [],
                'debug'      => $debug,
            ]);
        }

        if ($action === 'ack_password') {
            $auth->acknowledgePassword();
            json_out(['ok' => true, 'message' => 'Пароль скрыт. Убедитесь, что он сохранён в менеджере паролей.',
                      'log' => $debug ? $log->entries() : [], 'debug' => $debug]);
        }

        if ($action === 'logout') {
            $auth->logout((bool) ($payload['forget_ip'] ?? false));
            json_out(['ok' => true, 'authorized' => false, 'message' => 'Доступ сброшен.',
                      'log' => $debug ? $log->entries() : [], 'debug' => $debug]);
        }

        if (!$authorized) {
            $log->warn('Действие отклонено: нет авторизации', ['action' => $action]);
            json_out([
                'ok'            => false,
                'auth_required' => true,
                'error'         => 'Требуется пароль.',
                'log'           => $debug ? $log->entries() : [],
                'debug'         => $debug,
            ], 401);
        }

        $file = WpConfig::locate($log);
        if ($file === null) {
            throw new RuntimeException('Файл wp-config.php не найден рядом со скриптом и до 3 уровней выше.');
        }

        $cfg     = WpConfig::parse($file, $log);
        $db      = new Db($cfg, $log);
        $prefix  = $cfg->prefix();
        $preview = new Preview($db, $prefix, $log);

        if ($action === 'preview') {
            json_out([
                'ok'      => true,
                'preview' => $preview->collect(),
                'prefix'  => $prefix,
                'driver'  => $db->driver(),
                'queries' => $db->queryCount(),
                'ms'      => $log->elapsedMs(),
                'log'     => $debug ? $log->entries() : [],
                'debug'   => $debug,
            ]);
        }

        $enum = Action::tryFrom($action);
        if ($enum === null) {
            $log->error('Неизвестное действие', ['action' => $action]);
            json_out(['ok' => false, 'error' => 'Неизвестное действие: ' . $action,
                      'log' => $debug ? $log->entries() : [], 'debug' => $debug], 400);
        }

        if (($payload['confirm'] ?? '') !== '1' && ($payload['confirm'] ?? null) !== true) {
            json_out(['ok' => false, 'error' => 'Не подтверждено: отметьте галочку подтверждения.',
                      'log' => $debug ? $log->entries() : [], 'debug' => $debug], 400);
        }

        $before  = $preview->collect();
        $cleaner = new Cleaner($db, $prefix, $log);

        $db->begin();
        try {
            $stats = $cleaner->run($enum);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        json_out([
            'ok'      => true,
            'action'  => $enum->value,
            'label'   => $enum->label(),
            'stats'   => $stats,
            'total'   => array_sum($stats),
            'before'  => $before,
            'preview' => $preview->collect(),
            'prefix'  => $prefix,
            'driver'  => $db->driver(),
            'queries' => $db->queryCount(),
            'ms'      => $log->elapsedMs(),
            'log'     => $debug ? $log->entries() : [],
            'debug'   => $debug,
        ]);
    }

    // ------------------------- HTML-страница -------------------------
    $preview   = null;
    $prefix    = '';
    $driver    = '';
    $bootError = null;

    if ($authorized) {
        try {
            $file = WpConfig::locate($log);
            if ($file === null) {
                throw new RuntimeException(
                    'Файл wp-config.php не найден. Положите скрипт в корень WordPress (рядом с wp-config.php) '
                    . 'или не выше 3 уровней от него.'
                );
            }
            $cfg     = WpConfig::parse($file, $log);
            $db      = new Db($cfg, $log);
            $prefix  = $cfg->prefix();
            $driver  = $db->driver();
            $preview = (new Preview($db, $prefix, $log))->collect();
        } catch (Throwable $e) {
            $bootError = $e->getMessage();
            $log->error('Ошибка инициализации', ['error' => $e->getMessage(), 'class' => $e::class]);
        }
    }

    $newPassword = $auth->pendingPassword();

    $boot = [
        'endpoint'   => (string) ($_SERVER['REQUEST_URI'] ?? '?'),
        'token'      => $auth->csrfToken(),
        'debug'      => $debug,
        'authorized' => $authorized,
        'ip'         => $auth->clientIp(),
        'aclCount'   => $credentials->aclCount(),
        'prefix'     => $prefix,
        'driver'     => $driver,
        'version'    => Config::VERSION,
        'serverLog'  => $debug ? $log->entries() : [],
    ];

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');

    $fmt = static fn (int $n): string => number_format($n, 0, '.', ' ');
    $opt = static fn (int $n): string => match (true) {
        $n === -1 => '— (опции нет)',
        $n === -2 => '⚠ значение не массив',
        default   => number_format($n, 0, '.', ' '),
    };
    $storageDir  = dirname($credentials->path());
    $storageName = basename($credentials->path());

    ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e(Config::APP_NAME) ?> <?= e(Config::VERSION) ?></title>
<style>
:root{--bd:#d9dce1;--mut:#6b7280}
*{box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f4f5f7;margin:0;padding:24px;color:#222}
.wrap{max-width:1020px;margin:0 auto;background:#fff;border:1px solid var(--bd);border-radius:8px;padding:24px}
h1{font-size:20px;margin:0 0 4px}
h2{font-size:15px;margin:22px 0 8px}
.sub{color:var(--mut);font-size:13px;margin-bottom:14px}
.row{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}
table{border-collapse:collapse;width:100%;font-size:13px;margin:8px 0}
th,td{border:1px solid #dfe2e7;padding:6px 10px;text-align:left}
th{background:#f0f2f5}
td.num{text-align:right;font-variant-numeric:tabular-nums}
.btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:6px;padding:10px 16px;font-size:14px;
     cursor:pointer;color:#fff;margin:6px 6px 0 0}
.btn-all{background:#b42318}.btn-guid{background:#175cd3}.btn-prices{background:#b54708}.btn-stocks{background:#067647}
.btn-ghost{background:#4b5563}.btn-sm{padding:6px 12px;font-size:13px}
.btn:hover:not(:disabled){opacity:.9}
.btn:disabled{opacity:.55;cursor:not-allowed}
.btn[data-busy="1"]{cursor:progress}
.spin{width:14px;height:14px;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;
      display:none;animation:sp .7s linear infinite}
.btn[data-busy="1"] .spin{display:inline-block}
@keyframes sp{to{transform:rotate(360deg)}}
.warn{background:#fff7e6;border:1px solid #ffd591;border-radius:6px;padding:10px 14px;font-size:13px;margin:12px 0}
.ok{background:#f0f9f1;border:1px solid #b7ebc5;border-radius:6px;padding:12px 16px;font-size:13px;margin:12px 0}
.err{background:#fdecec;border:1px solid #f5b5b5;border-radius:6px;padding:12px 16px;font-size:13px;margin:12px 0}
.pwd{background:#eef4ff;border:1px solid #a6c8ff;border-radius:8px;padding:16px;margin:12px 0}
.pwd code{font-size:20px;letter-spacing:1px;background:#fff;border:1px dashed #6b93d6;padding:6px 12px;display:inline-block}
.hidden{display:none!important}
label.ck{display:inline-flex;align-items:center;gap:8px;font-size:13px;margin:10px 0}
code{background:#f0f2f5;padding:1px 5px;border-radius:4px;font-size:12px}
pre.cmd{background:#0f172a;color:#d7e3f4;border-radius:6px;padding:12px;font:12px/1.6 Consolas,monospace;overflow:auto}
input[type=password]{padding:9px 12px;font-size:14px;border:1px solid var(--bd);border-radius:6px;min-width:260px}
.switch{display:inline-flex;align-items:center;gap:8px;font-size:13px;background:#f0f2f5;border:1px solid var(--bd);
        border-radius:20px;padding:6px 12px}
#evlog{background:#0f172a;color:#d7e3f4;border-radius:6px;padding:12px;font:12px/1.5 Consolas,monospace;
       max-height:420px;overflow:auto;white-space:pre-wrap;margin:8px 0}
#evlog .l-error{color:#ff9c9c}#evlog .l-warn{color:#ffd48a}#evlog .l-info{color:#9fd0ff}#evlog .l-debug{color:#9fb3c8}
.badge{display:inline-block;background:#eef2ff;color:#3538cd;border-radius:4px;padding:2px 8px;font-size:12px;margin-left:6px}
.author{border-top:1px solid var(--bd);margin-top:20px;padding-top:12px;font-size:13px;color:#374151}
.author a{color:#175cd3;text-decoration:none}.author a:hover{text-decoration:underline}
.foot{color:#9aa0a6;font-size:11px;margin-top:10px}
</style>
</head>
<body>
<div class="wrap">
  <div class="row">
    <div>
      <h1><?= e(Config::APP_NAME) ?><span class="badge">v<?= e(Config::VERSION) ?></span><span id="debugBadge" class="badge <?= $debug ? '' : 'hidden' ?>">debug</span></h1>
      <div class="sub">
        Очистка данных обмена 1С ↔ WooCommerce (плагин ItGalaxy «WooCommerce — 1C — Data Exchange»).
        Префикс: <code id="prefixBox"><?= e($prefix !== '' ? $prefix : '—') ?></code>,
        драйвер: <code><?= e($driver !== '' ? $driver : '—') ?></code>,
        ваш IP: <code><?= e($auth->clientIp()) ?></code>
      </div>
    </div>
    <label class="switch" title="Полный журнал событий: шаги, SQL, тайминги">
      <input type="checkbox" id="debugToggle" <?= $debug ? 'checked' : '' ?>> Режим отладки
    </label>
  </div>

  <div id="errBox" class="err hidden"></div>
  <div id="okBox" class="ok hidden"></div>

  <?php if ($bootError !== null) { ?>
    <div class="err"><?= e($bootError) ?></div>
  <?php } ?>

  <?php if (!$credentials->isPersistent()) { ?>
    <div class="warn">
      Каталог скрипта недоступен для записи, хранилище доступа создано во временной папке
      (<code><?= e($storageDir) ?></code>). Пароль может сброситься при очистке temp — сделайте каталог скрипта
      доступным для записи.
    </div>
  <?php } ?>

  <!-- --------------- Пароль первого запуска (один раз) --------------- -->
  <?php if ($newPassword !== null) { ?>
  <section id="pwdBanner" class="pwd">
    <h2 style="margin-top:0">Пароль доступа сгенерирован — сохраните его сейчас</h2>
    <p style="margin:6px 0 10px">
      Это единственный показ пароля. Он нужен для входа <strong>с другого IP-адреса или другого компьютера</strong>:
      на текущем адресе (<code><?= e($auth->clientIp()) ?></code>) вход уже разрешён и пароль спрашиваться не будет.
    </p>
    <div><code id="pwdValue"><?= e($newPassword) ?></code>
      <button class="btn btn-ghost btn-sm" type="button" id="copyPwd"><span class="spin"></span><span class="txt">Скопировать</span></button>
    </div>
    <p style="margin:12px 0 6px">Хранится только хеш пароля (Argon2id/bcrypt) в файле
      <code><?= e($storageName) ?></code>, права 0600. Запишите пароль в менеджер паролей.</p>
    <button class="btn btn-ghost btn-sm" type="button" data-action="ack_password"><span class="spin"></span><span class="txt">Я сохранил пароль — скрыть</span></button>
  </section>
  <?php } ?>

  <!-- ------------------------- Авторизация ------------------------- -->
  <section id="authSection" class="<?= $authorized ? 'hidden' : '' ?>">
    <h2>Вход</h2>
    <p class="sub">Введите пароль, выданный при первом запуске. Он запоминается в текущей сессии, а ваш IP
      добавляется в список доверенных — повторно пароль запрашиваться не будет.</p>
    <form id="loginForm" autocomplete="off">
      <input type="password" id="password" name="password" placeholder="Пароль доступа" autocomplete="current-password">
      <button class="btn btn-ghost" type="submit" id="loginBtn"><span class="spin"></span><span class="txt">Войти</span></button>
    </form>
  </section>

  <!-- --------------------------- Панель ---------------------------- -->
  <section id="panel" class="<?= $authorized ? '' : 'hidden' ?>">
    <div class="warn">
      <strong>Товары не удаляются.</strong> Записи <code>posts</code> остаются на месте — очищаются только привязки
      GUID, цены, остатки и связанные опции. Перед запуском сделайте бэкап БД.
    </div>

    <h2>Текущее состояние</h2>
    <table id="previewTable">
      <tr><th>Что</th><th>Кол-во</th></tr>
      <tr><td>Товары и вариации (не будут удалены)</td><td class="num" data-k="products"><?= $preview ? $fmt($preview['products']) : '—' ?></td></tr>
      <tr><td>GUID у товаров (postmeta <code><?= e(Config::GUID_META_KEY) ?></code>)</td><td class="num" data-k="guid"><?= $preview ? $fmt($preview['guid']) : '—' ?></td></tr>
      <tr><td>GUID у категорий/меток (termmeta <code><?= e(Config::GUID_META_KEY) ?></code>)</td><td class="num" data-k="guid_terms"><?= $preview ? $fmt($preview['guid_terms']) : '—' ?></td></tr>
      <tr><td>Записей цен у товаров (<?= e(implode(', ', Config::PRICE_META)) ?>)</td><td class="num" data-k="prices"><?= $preview ? $fmt($preview['prices']) : '—' ?></td></tr>
      <tr><td>Типов цен в option <code><?= e(Config::OPT_PRICE_TYPES) ?></code></td><td class="num" data-k="price_types"><?= $preview ? $opt($preview['price_types']) : '—' ?></td></tr>
      <tr><td>Записей остатков у товаров (<?= e(implode(', ', Config::STOCK_META)) ?>)</td><td class="num" data-k="stocks"><?= $preview ? $fmt($preview['stocks']) : '—' ?></td></tr>
      <tr><td>Складов в option <code><?= e(Config::OPT_WAREHOUSES) ?></code></td><td class="num" data-k="warehouses"><?= $preview ? $opt($preview['warehouses']) : '—' ?></td></tr>
    </table>

    <h2>Действия</h2>
    <label class="ck"><input type="checkbox" id="confirm"> Я понимаю, что данные будут удалены, бэкап сделан</label>
    <div>
      <button class="btn btn-all"    type="button" data-action="all"><span class="spin"></span><span class="txt">Очистить всё</span></button>
      <button class="btn btn-guid"   type="button" data-action="guid"><span class="spin"></span><span class="txt">Очистить GUID 1С</span></button>
      <button class="btn btn-prices" type="button" data-action="prices"><span class="spin"></span><span class="txt">Очистить цены</span></button>
      <button class="btn btn-stocks" type="button" data-action="stocks"><span class="spin"></span><span class="txt">Очистить остатки</span></button>
      <button class="btn btn-ghost"  type="button" data-action="preview"><span class="spin"></span><span class="txt">Обновить данные</span></button>
      <button class="btn btn-ghost"  type="button" data-action="logout"><span class="spin"></span><span class="txt">Выйти и забыть IP</span></button>
    </div>

    <h2>Результат</h2>
    <div id="resultBox" class="sub">Действий пока не выполнялось.</div>

    <h2>Доступ и сброс пароля</h2>
    <p class="sub">Доверенных IP: <?= (int) $credentials->aclCount() ?>, хранилище:
      <code><?= e($storageName) ?></code> в <code><?= e($storageDir) ?></code>,
      создано <?= e(date('d.m.Y H:i', $credentials->createdAt())) ?>.
      Если пароль забыт, восстановите доступ с сервера одним из способов:</p>
    <pre class="cmd"># 1) через CLI (рекомендуется)
php <?= e(basename(__FILE__)) ?> --status
php <?= e(basename(__FILE__)) ?> --reset
php <?= e(basename(__FILE__)) ?> --set-password='НовыйСложныйПароль'

# 2) удалить хранилище — новый пароль сгенерируется и покажется один раз
rm <?= e($storageDir) ?>/<?= e($storageName) ?>

# 3) маркер сброса (если есть только файловый доступ, FTP/панель)
touch <?= e($storageDir) ?>/<?= e(Config::RESET_FILE) ?></pre>
    <p class="sub">Любой сброс очищает привязки к IP: пароль потребуется заново всем клиентам.</p>
  </section>

  <!-- ------------------------ Журнал событий ----------------------- -->
  <section id="logSection" class="<?= $debug ? '' : 'hidden' ?>">
    <h2>Журнал событий</h2>
    <p class="sub">Клиентские события + серверные шаги, SQL, число затронутых строк и тайминги. Дублируется в консоль
      браузера и не стирается: страница не перезагружается.</p>
    <div id="evlog"></div>
    <button class="btn btn-ghost btn-sm" type="button" id="clearLog"><span class="spin"></span><span class="txt">Очистить журнал</span></button>
  </section>

  <div class="author">
    Автор: <strong><?= e(Config::AUTHOR) ?></strong> —
    <a href="<?= e(Config::AUTHOR_URL) ?>" target="_blank" rel="noopener noreferrer">Deynekin.com</a> ·
    GitHub: <a href="<?= e(Config::GITHUB_URL) ?>" target="_blank" rel="noopener noreferrer">paulmann (Mikhail Deynekin)</a> ·
    Репозиторий: <a href="<?= e(Config::REPO_URL) ?>" target="_blank" rel="noopener noreferrer">woocommerce-1c-data-cleaner</a> ·
    Лицензия <?= e(Config::LICENSE) ?>
  </div>
  <div class="foot">
    Затрагиваются: GUID <code><?= e(Config::GUID_META_KEY) ?></code>, опции
    <code><?= e(Config::OPT_PRICE_TYPES) ?></code>, <code><?= e(Config::OPT_WAREHOUSES) ?></code>,
    <code><?= e(Config::OPT_SETTINGS) ?></code>. Удалите скрипт и файл
    <code><?= e($storageName) ?></code> с сервера после использования.
  </div>
</div>

<script type="application/json" id="bootData"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(() => {
  'use strict';

  const boot  = JSON.parse(document.getElementById('bootData').textContent);
  const state = { token: boot.token, debug: !!boot.debug, busy: false };

  const $  = (sel) => document.querySelector(sel);
  const $$ = (sel) => Array.from(document.querySelectorAll(sel));

  const evlog     = $('#evlog');
  const errBox    = $('#errBox');
  const okBox     = $('#okBox');
  const resultBox = $('#resultBox');
  const nf        = new Intl.NumberFormat('ru-RU');

  // ------------------------------ Лог ------------------------------
  function log(level, msg, ctx) {
    const now  = new Date();
    const line = `[${now.toLocaleTimeString('ru-RU', { hour12: false })}.` +
                 `${String(now.getMilliseconds()).padStart(3, '0')}] ` +
                 `${level.toUpperCase().padEnd(5)} ${msg}` +
                 (ctx && Object.keys(ctx).length ? ' ' + JSON.stringify(ctx) : '');

    if (state.debug) appendLine(line, level);
    (level === 'error' ? console.error : level === 'warn' ? console.warn : console.log)('[1c-cleaner]', line);
  }

  function appendLine(text, level) {
    const div = document.createElement('div');
    div.className = 'l-' + level;
    div.textContent = text;
    evlog.appendChild(div);
    evlog.scrollTop = evlog.scrollHeight;
  }

  function renderServerLog(entries) {
    if (!Array.isArray(entries) || !entries.length) return;
    console.groupCollapsed(`[1c-cleaner] серверный лог: ${entries.length} событий`);
    entries.forEach((it) => {
      const line = `+${String(it.t).padStart(8)} ms  ${String(it.level).toUpperCase().padEnd(5)} ${it.msg}` +
                   (it.ctx && Object.keys(it.ctx).length ? '  ' + JSON.stringify(it.ctx) : '');
      if (state.debug) appendLine(line, it.level);
      console.log(line);
    });
    console.groupEnd();
  }

  // ------------------------- Сообщения UI --------------------------
  const showError = (t) => { errBox.textContent = t; errBox.classList.remove('hidden'); okBox.classList.add('hidden'); log('error', 'UI: ' + t); };
  const showOk    = (t) => { okBox.textContent = t; okBox.classList.remove('hidden'); errBox.classList.add('hidden'); };
  const clearBoxes = () => { errBox.classList.add('hidden'); okBox.classList.add('hidden'); };

  // --------------------- Состояние кнопок --------------------------
  function setBusy(btn, busy) {
    state.busy = busy;
    if (btn) {
      btn.dataset.busy = busy ? '1' : '0';
      btn.setAttribute('aria-busy', busy ? 'true' : 'false');
      const txt = btn.querySelector('.txt');
      if (txt) {
        if (busy) { txt.dataset.orig = txt.dataset.orig || txt.textContent; txt.textContent = 'Выполняется…'; }
        else if (txt.dataset.orig) { txt.textContent = txt.dataset.orig; }
      }
    }
    $$('.btn').forEach((b) => { b.disabled = busy; });
    log('debug', `UI: кнопки ${busy ? 'заблокированы' : 'разблокированы'}`,
        { action: btn ? (btn.dataset.action || btn.id) : null });
  }

  // ---------------------------- Транспорт --------------------------
  async function api(payload, btn) {
    if (state.busy) { log('warn', 'Запрос проигнорирован: предыдущий ещё выполняется'); return null; }
    setBusy(btn, true);
    clearBoxes();
    const started = performance.now();
    log('info', 'POST → сервер', { action: payload.action });

    try {
      const res = await fetch(boot.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
        body: JSON.stringify({ ...payload, token: state.token }),
      });

      const raw = await res.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch {
        log('error', 'Ответ не является JSON', { status: res.status, head: raw.slice(0, 300) });
        showError(`Сервер вернул не JSON (HTTP ${res.status}): ${raw.slice(0, 200)}`);
        return null;
      }

      log('info', 'Ответ получен', {
        status: res.status, ok: data.ok === true, server_ms: data.ms ?? null,
        queries: data.queries ?? null, client_ms: Math.round(performance.now() - started),
      });
      if (typeof data.debug === 'boolean') state.debug = data.debug;
      renderServerLog(data.log);
      if (data.php_output) log('warn', 'Посторонний вывод PHP', { output: data.php_output });
      if (data.token) state.token = data.token;

      if (data.ok !== true) {
        showError(data.error || `Ошибка HTTP ${res.status}`);
        if (data.auth_required) toggleAuth(false);
        return null;
      }
      return data;
    } catch (err) {
      log('error', 'Сбой сети/запроса', { name: err.name, message: err.message });
      showError('Сбой запроса: ' + err.message);
      return null;
    } finally {
      setBusy(btn, false);
    }
  }

  // ----------------------------- Рендер ----------------------------
  const fmtOpt = (n) => (n === -1 ? '— (опции нет)' : n === -2 ? '⚠ значение не массив' : nf.format(n));

  function renderPreview(p) {
    if (!p) return;
    Object.entries(p).forEach(([k, v]) => {
      const cell = document.querySelector(`#previewTable [data-k="${k}"]`);
      if (cell) cell.textContent = (k === 'price_types' || k === 'warehouses') ? fmtOpt(v) : nf.format(v);
    });
    log('debug', 'UI: таблица состояния обновлена', p);
  }

  function renderStats(data) {
    const rows = Object.entries(data.stats || {})
      .map(([k, v]) => `<tr><td>${k}</td><td class="num">${nf.format(v)}</td></tr>`).join('');
    resultBox.innerHTML =
      `<div class="ok"><strong>${data.label}</strong> — выполнено за ${data.ms} мс, запросов: ${data.queries},
        затронуто строк: ${nf.format(data.total)}.</div>
       <table><tr><th>Операция</th><th>Строк</th></tr>${rows}</table>`;
  }

  function toggleAuth(authorized) {
    $('#panel').classList.toggle('hidden', !authorized);
    $('#authSection').classList.toggle('hidden', authorized);
  }

  function applyDebugUi(enabled) {
    state.debug = enabled;
    $('#logSection').classList.toggle('hidden', !enabled);
    $('#debugBadge').classList.toggle('hidden', !enabled);
    const url = new URL(window.location.href);
    url.searchParams.set('debug', enabled ? '1' : '0');
    window.history.replaceState(null, '', url.toString());
  }

  // --------------------------- Обработчики -------------------------
  $('#debugToggle').addEventListener('change', async (ev) => {
    const enabled = ev.target.checked;
    applyDebugUi(enabled);
    log('info', 'UI: переключение режима отладки', { enabled });
    const data = await api({ action: 'set_debug', enabled }, null);
    if (data) showOk(data.message);
    else ev.target.checked = state.debug;
  });

  $('#loginForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = $('#loginBtn');
    const data = await api({ action: 'login', password: $('#password').value }, btn);
    if (!data) return;
    $('#password').value = '';
    toggleAuth(true);
    showOk(data.message);
    const fresh = await api({ action: 'preview' }, btn);
    if (fresh) { renderPreview(fresh.preview); $('#prefixBox').textContent = fresh.prefix; }
  });

  const copyBtn = $('#copyPwd');
  if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
      const value = $('#pwdValue').textContent.trim();
      try {
        await navigator.clipboard.writeText(value);
        showOk('Пароль скопирован в буфер обмена. Сохраните его в менеджере паролей.');
      } catch (err) {
        log('warn', 'Clipboard API недоступен', { message: err.message });
        showError('Не удалось скопировать автоматически — выделите пароль и скопируйте вручную.');
      }
    });
  }

  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('.btn[data-action]');
    if (!btn) return;
    ev.preventDefault();
    const action = btn.dataset.action;

    if (action === 'ack_password') {
      const data = await api({ action: 'ack_password' }, btn);
      if (data) { const b = $('#pwdBanner'); if (b) b.remove(); showOk(data.message); }
      return;
    }

    if (action === 'logout') {
      const data = await api({ action: 'logout', forget_ip: true }, btn);
      if (data) { toggleAuth(false); showOk(data.message); }
      return;
    }

    if (action === 'preview') {
      const data = await api({ action: 'preview' }, btn);
      if (data) { renderPreview(data.preview); showOk('Данные обновлены.'); }
      return;
    }

    if (!$('#confirm').checked) { showError('Отметьте галочку подтверждения перед запуском очистки.'); return; }
    if (!window.confirm('Данные будут удалены безвозвратно. Продолжить?')) {
      log('warn', 'Пользователь отменил подтверждение', { action });
      return;
    }

    const data = await api({ action, confirm: '1' }, btn);
    if (data) { renderStats(data); renderPreview(data.preview); }
  });

  $('#clearLog').addEventListener('click', () => { evlog.innerHTML = ''; log('info', 'Журнал очищен'); });

  window.addEventListener('error', (ev) =>
    log('error', 'JS-исключение: ' + ev.message, { file: ev.filename, line: ev.lineno, col: ev.colno }));
  window.addEventListener('unhandledrejection', (ev) =>
    log('error', 'Необработанный reject: ' + (ev.reason?.message ?? String(ev.reason))));

  log('info', `${'WooCommerce 1C Data Cleaner'} v${boot.version} загружен`, {
    debug: state.debug, authorized: boot.authorized, ip: boot.ip,
    prefix: boot.prefix, driver: boot.driver, trusted_ips: boot.aclCount,
  });
  renderServerLog(boot.serverLog);
})();
</script>
</body>
</html><?php
    exit;
} catch (Throwable $e) {
    $log->error('Необработанное исключение', [
        'class' => $e::class,
        'text'  => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
    ]);

    $debugNow = isset($_SESSION) && ($_SESSION['debug'] ?? false) === true;

    if ($isPost) {
        json_out(['ok' => false, 'error' => $e->getMessage(),
                  'log' => $debugNow ? $log->entries() : [], 'debug' => $debugNow], 500);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Ошибка</title></head><body>'
        . '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:820px;margin:40px auto;'
        . 'background:#fdecec;border:1px solid #f5b5b5;border-radius:8px;padding:20px">'
        . '<h2 style="margin-top:0">Ошибка</h2><p>' . e($e->getMessage()) . '</p>'
        . ($debugNow
            ? '<pre style="white-space:pre-wrap;font:12px/1.5 Consolas,monospace">' . e(print_r($log->entries(), true)) . '</pre>'
            : '<p style="color:#666;font-size:13px">Добавьте <code>?debug=1</code> к URL, чтобы увидеть журнал событий.</p>')
        . '<p style="font-size:12px;color:#666">' . e(Config::APP_NAME) . ' ' . e(Config::VERSION)
        . ' — <a href="' . e(Config::AUTHOR_URL) . '">Deynekin.com</a>, '
        . '<a href="' . e(Config::GITHUB_URL) . '">github.com/paulmann</a></p>'
        . '</div></body></html>';
    exit;
}
