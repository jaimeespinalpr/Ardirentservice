<?php
declare(strict_types=1);
require_once __DIR__ . '/accounts_common.php';
require_once __DIR__ . '/supabase_common.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = rental_clean_text($_GET['action'] ?? 'status');

if ($method === 'GET' && $action === 'config') {
    $backend = strtolower(rental_env('ACCOUNT_BACKEND', 'sqlite'));
    if ($backend !== 'supabase') {
        rental_json(['ok' => true, 'account_backend' => 'sqlite']);
    }
    $url = supabase_base_url();
    $publishableKey = supabase_publishable_key();
    if ($url === '' || $publishableKey === '') {
        rental_json(['ok' => false, 'error' => 'supabase_not_configured'], 503);
    }
    rental_json([
        'ok' => true,
        'account_backend' => 'supabase',
        'supabase_url' => $url,
        'supabase_publishable_key' => $publishableKey,
    ]);
}

if (strtolower(rental_env('ACCOUNT_BACKEND', 'sqlite')) === 'supabase') {
    rental_json(['ok' => false, 'error' => 'legacy_accounts_disabled'], 410);
}

$pdo = rental_db();
account_prepare_db($pdo);

if ($method === 'GET' && $action === 'status') {
    $user = account_current_user($pdo);
    rental_json([
        'ok' => true,
        'authenticated' => $user !== null,
        'user' => $user ? account_public_user($user) : null,
        'csrf_token' => account_csrf_token(),
    ]);
}

if ($method !== 'POST') {
    rental_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$payload = rental_read_json_body();
account_require_csrf($payload);

if ($action === 'register') {
    $name = rental_clean_text($payload['name'] ?? '');
    $email = strtolower(rental_clean_text($payload['email'] ?? ''));
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) ?: '';
    $phone = rental_clean_text($payload['phone'] ?? '');
    $password = (string) ($payload['password'] ?? '');
    $marketing = !empty($payload['marketing_opt_in']);
    $terms = !empty($payload['accept_terms']);
    if ($name === '' || mb_strlen($name) > 120 || $email === '' || strlen($password) < 10 || !$terms) {
        rental_json(['ok' => false, 'error' => 'invalid_registration'], 422);
    }
    if ($phone !== '' && mb_strlen($phone) > 40) {
        rental_json(['ok' => false, 'error' => 'invalid_phone'], 422);
    }
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO customer_accounts
             (full_name, email, phone, password_hash, marketing_opt_in, marketing_opt_in_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name, $email, $phone,
            password_hash($password, PASSWORD_DEFAULT),
            $marketing ? 1 : 0,
            $marketing ? $now : null,
            $now, $now,
        ]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'unique')) {
            rental_json(['ok' => false, 'error' => 'email_exists'], 409);
        }
        rental_json(['ok' => false, 'error' => 'registration_failed'], 500);
    }
    session_regenerate_id(true);
    $_SESSION['customer_account_id'] = (int) $pdo->lastInsertId();
    $user = account_current_user($pdo);
    rental_json(['ok' => true, 'authenticated' => true, 'user' => account_public_user($user)]);
}

if ($action === 'login') {
    $email = strtolower(rental_clean_text($payload['email'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM customer_accounts WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!is_array($user) || !password_verify($password, (string) $user['password_hash'])) {
        usleep(350000);
        rental_json(['ok' => false, 'error' => 'invalid_login'], 401);
    }
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE customer_accounts SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['customer_account_id'] = (int) $user['id'];
    $fresh = account_current_user($pdo);
    rental_json(['ok' => true, 'authenticated' => true, 'user' => account_public_user($fresh)]);
}

if ($action === 'logout') {
    $_SESSION = [];
    session_regenerate_id(true);
    rental_json(['ok' => true, 'authenticated' => false]);
}

rental_json(['ok' => false, 'error' => 'unknown_action'], 404);

