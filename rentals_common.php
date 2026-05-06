<?php
declare(strict_types=1);

// Load .env from same directory (pay/) so STRIPE_SECRET_KEY and STRIPE_MODE
// are available via getenv() without being committed to the repo.
(static function (): void {
    $envFile = __DIR__ . '/.env';
    if (!is_file($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
})();

const DAILY_RATE_CENTS = 5000;
const CURRENCY = 'usd';

function rental_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
}

function rental_public_site_url(): string
{
    return rtrim(rental_env('PUBLIC_SITE_URL', 'https://www.ardirentservice.com'), '/');
}

function rental_public_url(string $path = ''): string
{
    $normalized = ltrim($path, '/');
    return rental_public_site_url() . ($normalized === '' ? '' : '/' . $normalized);
}

function rental_format_money(int $amountCents, string $currency = CURRENCY): string
{
    $symbol = strtolower($currency) === 'usd' ? '$' : strtoupper($currency) . ' ';
    return $symbol . number_format($amountCents / 100, 2);
}

function rental_email_from(): string
{
    return rental_env('RENTAL_EMAIL_FROM', 'Ardi Rent & Service <noreply@ardirentservice.com>');
}

function rental_email_reply_to(): string
{
    return rental_env('RENTAL_EMAIL_REPLY_TO', 'info@ardirentservice.com');
}

function rental_pickup_details_html(): string
{
    $address = rental_env('RENTAL_PICKUP_ADDRESS', '[Pickup address / meeting location goes here]');
    $hours = rental_env('RENTAL_PICKUP_HOURS', '[Pickup hours go here]');
    $contact = rental_env('RENTAL_PICKUP_CONTACT', '[Pickup contact / phone goes here]');
    $notes = rental_env(
        'RENTAL_PICKUP_NOTES',
        '[Add any ID, deposit, parking, arrival, or return instructions here]'
    );

    return '<ul style="padding-left:20px;margin:10px 0 0;color:#202124;line-height:1.55">'
        . '<li><strong>Pickup location:</strong> ' . htmlspecialchars($address, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
        . '<li><strong>Pickup time:</strong> ' . htmlspecialchars($hours, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
        . '<li><strong>Contact:</strong> ' . htmlspecialchars($contact, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
        . '<li><strong>Notes:</strong> ' . htmlspecialchars($notes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
        . '</ul>';
}

function rental_send_customer_email(array $reservation, array $items): bool
{
    $email = filter_var((string) ($reservation['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        return false;
    }

    $customerName = rental_clean_text($reservation['customer_name'] ?? '');
    $safeName = htmlspecialchars($customerName !== '' ? $customerName : 'there', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $startDate = htmlspecialchars((string) ($reservation['start_date'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $endDate = htmlspecialchars((string) ($reservation['end_date'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $total = rental_format_money((int) ($reservation['total_amount_cents'] ?? 0), (string) ($reservation['currency'] ?? CURRENCY));
    $logoUrl = rental_public_url('assets/logos/logo-black-square.png');

    $itemRows = '';
    foreach ($items as $item) {
        $title = htmlspecialchars((string) ($item['title'] ?? $item['item_title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($title === '') {
            continue;
        }
        $itemRows .= '<li style="margin:6px 0">' . $title . '</li>';
    }
    if ($itemRows === '') {
        $itemRows = '<li style="margin:6px 0">Rental equipment</li>';
    }

    $subject = 'Thank you for your rental — Ardi Rent & Service';
    $html = '<!doctype html><html><body style="margin:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#111">'
        . '<div style="max-width:640px;margin:0 auto;padding:24px">'
        . '<div style="background:#fff;border-radius:18px;padding:26px;border:1px solid #e6e6e6">'
        . '<div style="text-align:center;margin-bottom:20px">'
        . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="Ardi Rent & Service" width="96" height="96" style="border-radius:18px;display:inline-block">'
        . '<h1 style="margin:14px 0 0;font-size:24px;line-height:1.25">Thank you for your rental</h1>'
        . '</div>'
        . '<p style="font-size:16px;line-height:1.55;margin:0 0 14px">Hi ' . $safeName . ',</p>'
        . '<p style="font-size:16px;line-height:1.55;margin:0 0 18px">Thank you for renting with <strong>Ardi Rent & Service</strong>. Your order has been received and your selected equipment is reserved for the dates below.</p>'
        . '<div style="background:#f7f7f7;border-radius:14px;padding:16px;margin:18px 0">'
        . '<p style="margin:0 0 8px"><strong>Rental dates:</strong> ' . $startDate . ' to ' . $endDate . '</p>'
        . '<p style="margin:0"><strong>Total paid:</strong> ' . htmlspecialchars($total, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        . '</div>'
        . '<h2 style="font-size:18px;margin:22px 0 8px">Items reserved</h2>'
        . '<ul style="padding-left:20px;margin:0 0 18px;color:#202124;line-height:1.55">' . $itemRows . '</ul>'
        . '<h2 style="font-size:18px;margin:22px 0 8px">Pickup instructions</h2>'
        . '<p style="font-size:15px;line-height:1.55;margin:0">Please review the pickup details below. Bring a valid ID and your order confirmation when picking up the equipment.</p>'
        . rental_pickup_details_html()
        . '<p style="font-size:15px;line-height:1.55;margin:22px 0 0">If you have any questions before pickup, reply to this email and we will help you.</p>'
        . '<p style="font-size:15px;line-height:1.55;margin:18px 0 0">— Ardi Rent & Service</p>'
        . '</div></div></body></html>';

    $plainItems = implode(', ', array_filter(array_map(
        static fn(array $item): string => (string) ($item['title'] ?? $item['item_title'] ?? ''),
        $items
    )));
    $plain = "Thank you for renting with Ardi Rent & Service.\n\n"
        . "Rental dates: {$startDate} to {$endDate}\n"
        . "Items: " . ($plainItems !== '' ? $plainItems : 'Rental equipment') . "\n"
        . "Total paid: {$total}\n\n"
        . "Pickup instructions:\n"
        . strip_tags(str_replace(['</li>', '<br>', '<br/>', '<br />'], "\n", rental_pickup_details_html()))
        . "\n\nReply to this email if you have questions.\n";

    $boundary = 'ardi_' . bin2hex(random_bytes(12));
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . rental_email_from(),
        'Reply-To: ' . rental_email_reply_to(),
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
        . $plain . "\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
        . $html . "\r\n"
        . '--' . $boundary . "--\r\n";

    return mail($email, $subject, $body, implode("\r\n", $headers));
}

function rental_allowed_origins(): array
{
    $configured = rental_env(
        'ALLOWED_ORIGINS',
        'https://ardirentservice.com,https://www.ardirentservice.com,https://jaimeespinalpr.github.io'
    );

    return array_values(array_filter(array_map(
        static fn(string $origin): string => rtrim(trim($origin), '/'),
        explode(',', $configured)
    )));
}

function rental_apply_cors(): void
{
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? rtrim((string) $_SERVER['HTTP_ORIGIN'], '/') : '';
    if ($origin !== '' && in_array($origin, rental_allowed_origins(), true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

rental_apply_cors();

function rental_item_rate_cents(string $itemId): int
{
    $cameraRates = [
        'sony-a7-v'               => 7500,
        'sony-a7s-iii'            => 7500,
        'sony-a7-iv'              => 7500,
        'sony-alpha-1'            => 9500,
        'gopro-hero12-black'      => 7500,
        'gopro-hero13-black'      => 7500,
        'dji-osmo-pocket'         => 7500,
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
            id                   INTEGER PRIMARY KEY AUTOINCREMENT,
            checkout_session_id  TEXT UNIQUE NOT NULL,
            start_date           TEXT NOT NULL,
            end_date             TEXT NOT NULL,
            customer_name        TEXT NOT NULL,
            customer_email       TEXT NOT NULL,
            customer_phone       TEXT,
            total_amount_cents   INTEGER NOT NULL,
            currency             TEXT NOT NULL,
            status               TEXT NOT NULL,
            created_at           TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reservation_items (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            reservation_id     INTEGER NOT NULL,
            item_id            TEXT NOT NULL,
            item_title         TEXT NOT NULL,
            unit_amount_cents  INTEGER NOT NULL,
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
    $end   = new DateTimeImmutable($endDate);
    $days  = (int) $start->diff($end)->format('%a') + 1;
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
           AND r.end_date   >= ?
           AND ri.item_id IN (' . $placeholders . ')';

    $stmt   = $pdo->prepare($sql);
    $params = array_merge(['paid', $endDate, $startDate], $itemIds);
    $stmt->execute($params);

    return array_values(array_map(static fn(array $row): string => (string) $row['item_id'], $stmt->fetchAll()));
}

function rental_base_url(): string
{
    $https  = $_SERVER['HTTPS'] ?? '';
    $scheme = ($https === 'on' || $https === '1') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $prefix = $dir === '' || $dir === '/' ? '' : $dir;
    return $scheme . '://' . $host . $prefix;
}

function stripe_request(string $method, string $path, array $form = []): array
{
    $secret = getenv('STRIPE_SECRET_KEY') ?: '';
    if ($secret === '') {
        return ['ok' => false, 'error' => 'missing_secret_key'];
    }

    $mode = strtolower(trim((string) (getenv('STRIPE_MODE') ?: '')));
    if (!in_array($mode, ['test', 'live'], true)) {
        return ['ok' => false, 'error' => 'invalid_or_missing_stripe_mode'];
    }

    $isTestMode = $mode === 'test';
    $isTestKey  = str_starts_with($secret, 'sk_test_');
    $isLiveKey  = str_starts_with($secret, 'sk_live_');

    if ($isTestMode && !$isTestKey) {
        return ['ok' => false, 'error' => 'test_mode_requires_sk_test_key'];
    }
    if (!$isTestMode && !$isLiveKey) {
        return ['ok' => false, 'error' => 'live_mode_requires_sk_live_key'];
    }

    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $ch  = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init_failed'];
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secret,
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }

    $body   = curl_exec($ch);
    $error  = curl_error($ch);
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
