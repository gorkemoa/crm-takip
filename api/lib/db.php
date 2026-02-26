<?php
declare(strict_types=1);

function app_debug_enabled(): bool
{
    $value = getenv('APP_DEBUG');
    if ($value === false || $value === '') {
        $value = getenv('DEBUG');
    }
    if ($value === false || $value === '') {
        return false;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $fileConfig = [];
    $configFile = __DIR__ . '/../config/database.php';
    if (is_file($configFile)) {
        $loaded = require $configFile;
        if (is_array($loaded)) {
            $fileConfig = $loaded;
        }
    }

    $host = getenv('DB_HOST') ?: getenv('MYSQL_HOST') ?: ($_SERVER['DB_HOST'] ?? '') ?: ($fileConfig['host'] ?? '');
    $dbname = getenv('DB_NAME') ?: getenv('MYSQL_DATABASE') ?: ($_SERVER['DB_NAME'] ?? '') ?: ($fileConfig['dbname'] ?? '');
    $dbUser = getenv('DB_USER') ?: getenv('MYSQL_USER') ?: ($_SERVER['DB_USER'] ?? '') ?: ($fileConfig['user'] ?? '');
    $dbPass = getenv('DB_PASS') ?: getenv('MYSQL_PASSWORD') ?: ($_SERVER['DB_PASS'] ?? '') ?: ($fileConfig['pass'] ?? '');
    $port = getenv('DB_PORT') ?: getenv('MYSQL_PORT') ?: ($_SERVER['DB_PORT'] ?? '') ?: (string) ($fileConfig['port'] ?? '');

    if ($host === '' || $dbname === '' || $dbUser === '') {
        error_log('DB config missing. Provide api/config/database.php or env: DB_HOST, DB_NAME, DB_USER, DB_PASS.');
        throw new RuntimeException('Database connection configuration is missing.');
    }

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    if ($port !== '' && ctype_digit((string) $port)) {
        $dsn .= ';port=' . $port;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
    ];

    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    } catch (Throwable $e) {
        error_log('DB connection failed [' . $host . '/' . $dbname . ']: ' . $e->getMessage());
        if (app_debug_enabled()) {
            throw $e;
        }
        throw new RuntimeException('Database connection failed.');
    }

    return $pdo;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function add_minutes(string $from, int $minutes): string
{
    $timestamp = strtotime($from . ' UTC');
    return gmdate('Y-m-d H:i:s', $timestamp + ($minutes * 60));
}

function add_days(string $from, int $days): string
{
    $timestamp = strtotime($from . ' UTC');
    return gmdate('Y-m-d H:i:s', $timestamp + ($days * 86400));
}

function decode_json(?string $json, mixed $fallback = []): mixed
{
    if ($json === null || $json === '') {
        return $fallback;
    }

    $decoded = json_decode($json, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
}

function encode_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
}
