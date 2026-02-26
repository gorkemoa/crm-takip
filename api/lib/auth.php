<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';

function request_header(string $name): ?string
{
    $target = strtolower($name);

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower((string) $key) === $target) {
                    return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                }
            }
        }
    }

    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return (string) $_SERVER[$serverKey];
    }

    foreach ($_SERVER as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
            continue;
        }
        $normalized = strtolower(str_replace('_', '-', substr($key, 5)));
        if ($normalized === $target) {
            return (string) $value;
        }
    }

    return null;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function csrf_token(): string
{
    start_secure_session();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }

    return (string) $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    start_secure_session();

    $headerToken = request_header('X-CSRF-Token');
    $sessionToken = $_SESSION['csrf_token'] ?? null;

    if (!$headerToken || !$sessionToken || !hash_equals((string) $sessionToken, (string) $headerToken)) {
        error_response('CSRF_INVALID', 'CSRF doğrulaması başarısız.', 419);
    }
}

function current_user(PDO $db): ?array
{
    start_secure_session();

    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    $stmt = $db->prepare('SELECT id, name, username, title, email, role, avatar_url, created_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function require_auth(PDO $db, bool $releaseSessionLock = true): array
{
    $user = current_user($db);
    if (!$user) {
        error_response('UNAUTHORIZED', 'Oturum gerekli.', 401);
    }

    if ($releaseSessionLock && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    return $user;
}

function require_admin(array $user): void
{
    if (($user['role'] ?? '') !== 'admin') {
        error_response('FORBIDDEN', 'Bu işlem için admin yetkisi gerekli.', 403);
    }
}

function login_user(array $user): void
{
    start_secure_session();
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

function logout_user(): void
{
    start_secure_session();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function login_rate_limit_check(PDO $db, string $ip): void
{
    $stmt = $db->prepare('SELECT failed_count, lock_until FROM login_attempts WHERE ip = :ip LIMIT 1');
    $stmt->execute(['ip' => $ip]);
    $attempt = $stmt->fetch();

    if (!$attempt) {
        return;
    }

    $now = now_utc();
    $lockUntil = $attempt['lock_until'] ?? null;

    if ($lockUntil && strtotime($lockUntil . ' UTC') > strtotime($now . ' UTC')) {
        error_response('RATE_LIMITED', 'Çok fazla başarısız giriş denemesi. Lütfen daha sonra tekrar deneyin.', 429);
    }
}

function login_rate_limit_fail(PDO $db, string $ip): void
{
    $now = now_utc();
    $stmt = $db->prepare('SELECT failed_count, first_failed_at FROM login_attempts WHERE ip = :ip LIMIT 1');
    $stmt->execute(['ip' => $ip]);
    $attempt = $stmt->fetch();

    if (!$attempt) {
        $insert = $db->prepare('INSERT INTO login_attempts(ip, failed_count, first_failed_at, lock_until) VALUES(:ip, 1, :first_failed_at, NULL)');
        $insert->execute([
            'ip' => $ip,
            'first_failed_at' => $now,
        ]);
        return;
    }

    $firstFailedTs = strtotime(($attempt['first_failed_at'] ?? $now) . ' UTC');
    $nowTs = strtotime($now . ' UTC');

    $failedCount = (int) $attempt['failed_count'];
    if (($nowTs - $firstFailedTs) > 900) {
        $failedCount = 0;
        $firstFailedTs = $nowTs;
    }

    $failedCount++;
    $lockUntil = null;
    if ($failedCount >= 5) {
        $lockUntil = gmdate('Y-m-d H:i:s', $nowTs + 900);
        $failedCount = 0;
        $firstFailedTs = $nowTs;
    }

    $update = $db->prepare('UPDATE login_attempts SET failed_count = :failed_count, first_failed_at = :first_failed_at, lock_until = :lock_until WHERE ip = :ip');
    $update->execute([
        'failed_count' => $failedCount,
        'first_failed_at' => gmdate('Y-m-d H:i:s', $firstFailedTs),
        'lock_until' => $lockUntil,
        'ip' => $ip,
    ]);
}

function login_rate_limit_success(PDO $db, string $ip): void
{
    $stmt = $db->prepare('DELETE FROM login_attempts WHERE ip = :ip');
    $stmt->execute(['ip' => $ip]);
}
