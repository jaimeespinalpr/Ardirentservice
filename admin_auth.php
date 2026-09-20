<?php
declare(strict_types=1);

require_once __DIR__ . '/rentals_common.php';

const ADMIN_IDLE_TIMEOUT_SECONDS = 1800;
const ADMIN_ABSOLUTE_TIMEOUT_SECONDS = 28800;

function admin_is_local_request(): bool
{
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($remoteAddress, ['127.0.0.1', '::1'], true);
}

function admin_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('ardi_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !admin_is_local_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function admin_csrf_token(): string
{
    admin_start_session();
    if (!isset($_SESSION['admin_csrf']) || !is_string($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}

function admin_require_csrf(): void
{
    $provided = (string) ($_POST['csrf_token'] ?? '');
    if ($provided === '' || !hash_equals(admin_csrf_token(), $provided)) {
        http_response_code(403);
        exit('Invalid request.');
    }
}

function admin_attempt_login(string $providedToken): bool
{
    admin_start_session();
    $configuredToken = rental_env('RENTAL_ADMIN_TOKEN');
    if ($configuredToken === '' || $providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
        usleep(350000);
        return false;
    }
    session_regenerate_id(true);
    $now = time();
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_authenticated_at'] = $now;
    $_SESSION['admin_last_seen_at'] = $now;
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    return true;
}

function admin_require_authenticated(): bool
{
    admin_start_session();
    if (rental_env('RENTAL_ADMIN_TOKEN') === '') {
        return admin_is_local_request();
    }
    if (($_SESSION['admin_authenticated'] ?? false) !== true) {
        return false;
    }
    $now = time();
    $authenticatedAt = (int) ($_SESSION['admin_authenticated_at'] ?? 0);
    $lastSeenAt = (int) ($_SESSION['admin_last_seen_at'] ?? 0);
    if (
        $authenticatedAt <= 0 || $lastSeenAt <= 0
        || $now - $authenticatedAt > ADMIN_ABSOLUTE_TIMEOUT_SECONDS
        || $now - $lastSeenAt > ADMIN_IDLE_TIMEOUT_SECONDS
    ) {
        admin_logout();
        return false;
    }
    $_SESSION['admin_last_seen_at'] = $now;
    return true;
}

function admin_logout(): void
{
    admin_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Strict',
        ]);
    }
    session_destroy();
}
