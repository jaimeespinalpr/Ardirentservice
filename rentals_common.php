<?php
declare(strict_types=1);

const DAILY_RATE_CENTS = 5000;
const CURRENCY = 'usd';

function rental_item_rate_cents(string $itemId): int
{
    $cameraRates = [
        'sony-a7-v' => 7500,
        'sony-a7s-iii' => 7500,
        'sony-a7-iv' => 7500,
        'sony-alpha-1' => 9500,
        'gopro-hero12-black' => 7500,
        'gopro-hero13-black' => 7500,
        'dji-osmo-pocket' => 7500,
        'sony-pxw-z150-4k-xdcam' => 7500,
    ];

    return $cameraRates[$itemId] ?? DAILY_RATE_CENTS;
}

function rental_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function rental_redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function rental_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = __DIR__ . '/data/rentals.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            checkout_session_id TEXT UNIQUE NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            customer_name TEXT NOT NULL,
            customer_email TEXT NOT NULL,
            customer_phone TEXT,
            total_amount_cents INTEGER NOT NULL,
            currency TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reservation_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reservation_id INTEGER NOT NULL,
            item_id TEXT NOT NULL,
            item_title TEXT NOT NULL,
            unit_amount_cents INTEGER NOT NULL,
            FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservation_dates ON reservations(start_date, end_date, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservation_items_item ON reservation_items(item_id)');

    return $pdo;
}

function rental_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function rental_clean_text(mixed $value): string
{
    $text = trim((string) $value);
    $text = strip_tags($text);
    return preg_replace('/\s+/', ' ', $text) ?? '';
}

function rental_validate_date(string $value): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value ? $value : null;
}

function rental_normalize_item_id(string $value): ?string
{
    $id = strtolower(trim($value));
    if ($id === '' || !preg_match('/^[a-z0-9-]{3,120}$/', $id)) {
        return null;
    }
    return $id;
}

function rental_days_between(string $startDate, string $endDate): int
{
    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    $days = (int) $start->diff($end)->format('%a') + 1;
    return max(1, $days);
}

function rental_find_unavailable_items(PDO $pdo, string $startDate, string $endDate, array $itemIds): array
{
    if ($itemIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $sql =
        'SELECT DISTINCT ri.item_id
         FROM reservation_items ri
         INNER JOIN reservations r ON r.id = ri.reservation_id
         WHERE r.status = ?
           AND r.start_date <= ?
           AND r.end_date >= ?
           AND ri.item_id IN (' . $placeholders . ')';

    $stmt = $pdo->prepare($sql);
    $params = array_merge(['paid', $endDate, $startDate], $itemIds);
    $stmt->execute($params);

    return array_values(array_map(static fn(array $row): string => (string) $row['item_id'], $stmt->fetchAll()));
}

function rental_base_url(): string
{
    $https = $_SERVER['HTTPS'] ?? '';
    $scheme = ($https === 'on' || $https === '1') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $prefix = $dir === '' || $dir === '/' ? '' : $dir;
    return $scheme . '://' . $host . $prefix;
}

function stripe_request(string $method, string $path, array $form = []): array
{
    $secret = getenv('STRIPE_SECRET_KEY') ?: '';
    if ($secret === '') {
        return ['ok' => false, 'error' => 'missing_secret_key'];
    }

    $mode = strtolower(trim((string) (getenv('STRIPE_MODE') ?: 'test')));
    $isTestMode = $mode !== 'live';
    $isTestKey = str_starts_with($secret, 'sk_test_');
    $isLiveKey = str_starts_with($secret, 'sk_live_');

    if ($isTestMode && !$isTestKey) {
        return ['ok' => false, 'error' => 'test_mode_requires_sk_test_key'];
    }
    if (!$isTestMode && !$isLiveKey) {
        return ['ok' => false, 'error' => 'live_mode_requires_sk_live_key'];
    }

    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init_failed'];
    }

    $headers = [
        'Authorization: Bearer ' . $secret,
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'status' => $status, 'error' => $error !== '' ? $error : 'empty_response'];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'status' => $status, 'error' => 'invalid_json'];
    }

    if ($status < 200 || $status >= 300) {
        $message = (string) ($json['error']['message'] ?? 'stripe_error');
        return ['ok' => false, 'status' => $status, 'error' => $message, 'raw' => $json];
    }

    return ['ok' => true, 'status' => $status, 'data' => $json];
}
