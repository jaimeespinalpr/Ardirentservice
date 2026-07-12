<?php
declare(strict_types=1);

require_once __DIR__ . '/rentals_common.php';

const WELCOME_DISCOUNT_CENTS = 500;

function account_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('ardi_account');
    $cookieDomain = str_ends_with(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), 'ardirentservice.com')
        ? '.ardirentservice.com'
        : '';
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'domain' => $cookieDomain,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function account_prepare_db(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS customer_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE COLLATE NOCASE,
            phone TEXT,
            password_hash TEXT NOT NULL,
            marketing_opt_in INTEGER NOT NULL DEFAULT 0,
            marketing_opt_in_at TEXT,
            welcome_discount_used_at TEXT,
            welcome_discount_reservation_token TEXT,
            welcome_discount_reserved_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_customer_accounts_email ON customer_accounts(email)');
}

function account_csrf_token(): string
{
    account_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['csrf_token'];
}

function account_require_csrf(array $payload): void
{
    $provided = (string) ($payload['csrf_token'] ?? '');
    if ($provided === '' || !hash_equals(account_csrf_token(), $provided)) {
        rental_json(['ok' => false, 'error' => 'invalid_request'], 403);
    }
}

function account_current_user(PDO $pdo): ?array
{
    account_start_session();
    $id = (int) ($_SESSION['customer_account_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    account_prepare_db($pdo);
    $stmt = $pdo->prepare(
        'SELECT id, full_name, email, phone, marketing_opt_in, welcome_discount_used_at,
                welcome_discount_reservation_token, welcome_discount_reserved_at, created_at
         FROM customer_accounts WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function account_public_user(array $user): array
{
    $reservedAt = (string) ($user['welcome_discount_reserved_at'] ?? '');
    $reserved = false;
    if ($reservedAt !== '') {
        try {
            $reserved = (new DateTimeImmutable($reservedAt)) > new DateTimeImmutable('-1 hour');
        } catch (Throwable) {
            $reserved = false;
        }
    }
    return [
        'id' => (int) $user['id'],
        'name' => (string) $user['full_name'],
        'email' => (string) $user['email'],
        'phone' => (string) ($user['phone'] ?? ''),
        'marketing_opt_in' => (bool) $user['marketing_opt_in'],
        'welcome_discount_available' => empty($user['welcome_discount_used_at']) && !$reserved,
        'welcome_discount_reserved' => empty($user['welcome_discount_used_at']) && $reserved,
        'welcome_discount_cents' => WELCOME_DISCOUNT_CENTS,
        'created_at' => (string) $user['created_at'],
    ];
}

function account_release_expired_discount(PDO $pdo, int $accountId): void
{
    $cutoff = (new DateTimeImmutable('-1 hour', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    $stmt = $pdo->prepare(
        'UPDATE customer_accounts
         SET welcome_discount_reservation_token = NULL, welcome_discount_reserved_at = NULL, updated_at = ?
         WHERE id = ? AND welcome_discount_used_at IS NULL
           AND welcome_discount_reserved_at IS NOT NULL AND welcome_discount_reserved_at < ?'
    );
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    $stmt->execute([$now, $accountId, $cutoff]);
}

function account_reserve_welcome_discount(PDO $pdo, int $accountId): ?string
{
    account_release_expired_discount($pdo, $accountId);
    $token = bin2hex(random_bytes(24));
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    $stmt = $pdo->prepare(
        'UPDATE customer_accounts
         SET welcome_discount_reservation_token = ?, welcome_discount_reserved_at = ?, updated_at = ?
         WHERE id = ? AND welcome_discount_used_at IS NULL
           AND welcome_discount_reservation_token IS NULL'
    );
    $stmt->execute([$token, $now, $now, $accountId]);
    return $stmt->rowCount() === 1 ? $token : null;
}

function account_release_discount(PDO $pdo, int $accountId, string $token): void
{
    $stmt = $pdo->prepare(
        'UPDATE customer_accounts
         SET welcome_discount_reservation_token = NULL, welcome_discount_reserved_at = NULL, updated_at = ?
         WHERE id = ? AND welcome_discount_reservation_token = ? AND welcome_discount_used_at IS NULL'
    );
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    $stmt->execute([$now, $accountId, $token]);
}

function account_consume_discount(PDO $pdo, int $accountId, string $token): bool
{
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    $stmt = $pdo->prepare(
        'UPDATE customer_accounts
         SET welcome_discount_used_at = ?, welcome_discount_reservation_token = NULL,
             welcome_discount_reserved_at = NULL, updated_at = ?
         WHERE id = ? AND welcome_discount_used_at IS NULL
           AND welcome_discount_reservation_token = ?'
    );
    $stmt->execute([$now, $now, $accountId, $token]);
    return $stmt->rowCount() === 1;
}

account_start_session();

