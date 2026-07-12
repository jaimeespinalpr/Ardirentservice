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

function rental_admin_email(): string
{
    return rental_env('RENTAL_ADMIN_EMAIL', 'ardirentservice@gmail.com');
}

function rental_admin_emails(): array
{
    $configured = rental_env(
        'RENTAL_ADMIN_EMAILS',
        rental_admin_email() . ',jaimeespinalpr@gmail.com'
    );
    $emails = array_filter(array_map(
        static fn(string $email): string => filter_var(trim($email), FILTER_VALIDATE_EMAIL) ?: '',
        explode(',', $configured)
    ));
    return array_values(array_unique($emails));
}

function rental_pickup_details_html(): string
{
    $address = rental_env('RENTAL_PICKUP_ADDRESS', 'Park Boulevard condominium');
    $hours = rental_env('RENTAL_PICKUP_HOURS', 'Same day, the afternoon before, or by 1:00 p.m. the next day when the rental ends very late.');
    $contact = rental_env('RENTAL_PICKUP_CONTACT', 'Reply to this email or contact Ardi Rent & Service by WhatsApp.');
    $notes = rental_env(
        'RENTAL_PICKUP_NOTES',
        'Deliveries and pickups are handled at Park Boulevard. Bring a valid ID and your order confirmation.'
    );

    return '<ul style="padding-left:20px;margin:10px 0 0;color:#202124;line-height:1.55">'
        . '<li><strong>Delivery / pickup location:</strong> ' . htmlspecialchars($address, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
        . '<li><strong>Timing:</strong> ' . htmlspecialchars($hours, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
        . '<li><strong>Contact:</strong> ' . htmlspecialchars($contact, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
        . '<li><strong>Notes:</strong> ' . htmlspecialchars($notes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
        . '</ul>';
}

function rental_delivery_policy_html(): string
{
    return '<div style="background:#f7f7f7;border:1px solid #e5e5e5;border-radius:14px;padding:16px;margin:18px 0">'
        . '<h2 style="font-size:18px;margin:0 0 8px">Delivery and pickup system</h2>'
        . '<p style="font-size:15px;line-height:1.55;margin:0 0 10px">Ardi Rent & Service handles equipment delivery and pickup at the <strong>Park Boulevard</strong> condominium.</p>'
        . '<ul style="padding-left:20px;margin:0;color:#202124;line-height:1.55">'
        . '<li><strong>Delivery:</strong> You can receive the equipment the same day you will use it or, if preferred, the afternoon before.</li>'
        . '<li><strong>Pickup:</strong> If you finish using the equipment the same day, we can pick it up that same day. If you finish very late, you have until 1:00 p.m. the next day to return it.</li>'
        . '</ul>'
        . '</div>';
}

function rental_delivery_policy_plain(): string
{
    return "Delivery and pickup system:\n"
        . "Ardi Rent & Service handles equipment delivery and pickup at the Park Boulevard condominium.\n"
        . "Delivery: You can receive the equipment the same day you will use it or, if preferred, the afternoon before.\n"
        . "Pickup: If you finish using the equipment the same day, we can pick it up that same day. If you finish very late, you have until 1:00 p.m. the next day to return it.\n";
}

function rental_send_customer_email(array $reservation, array $items): bool
{
    $email = filter_var((string) ($reservation['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        error_log('Ardi rental email skipped: invalid customer email');
        return false;
    }

    $message = rental_build_customer_email_message($reservation, $items);
    if (rental_smtp_configured()) {
        $sent = rental_send_smtp_message($email, $message);
        if (!$sent) {
            error_log('Ardi rental SMTP email failed for ' . $email);
        }
        return $sent;
    }

    $sent = mail($email, $message['subject'], $message['body'], implode("\r\n", $message['headers']));
    if (!$sent) {
        error_log('Ardi rental email failed for ' . $email);
    }
    return $sent;
}

function rental_send_admin_email(array $reservation, array $items): bool
{
    $emails = rental_admin_emails();
    if ($emails === []) {
        error_log('Ardi rental admin email skipped: no valid admin emails');
        return false;
    }

    $message = rental_build_admin_email_message($reservation, $items);
    $allSent = true;
    foreach ($emails as $email) {
        $sent = rental_smtp_configured()
            ? rental_send_smtp_message($email, $message)
            : mail($email, $message['subject'], $message['body'], implode("\r\n", $message['headers']));
        if (!$sent) {
            error_log('Ardi rental admin email failed for ' . $email);
            $allSent = false;
        }
    }
    return $allSent;
}

function rental_build_admin_email_message(array $reservation, array $items): array
{
    $reservationId = (int) ($reservation['id'] ?? 0);
    $name = rental_clean_text($reservation['customer_name'] ?? 'Unknown');
    $customerEmail = rental_clean_text($reservation['customer_email'] ?? '');
    $phone = rental_clean_text($reservation['customer_phone'] ?? '');
    $startDate = rental_clean_text($reservation['start_date'] ?? '');
    $endDate = rental_clean_text($reservation['end_date'] ?? '');
    $total = rental_format_money((int) ($reservation['total_amount_cents'] ?? 0), (string) ($reservation['currency'] ?? CURRENCY));
    $titles = array_values(array_filter(array_map(
        static fn(array $item): string => rental_clean_text($item['title'] ?? $item['item_title'] ?? ''),
        $items
    )));
    $subject = 'New paid rental' . ($reservationId > 0 ? ' #' . $reservationId : '') . ' - Ardi Rent & Service';
    $plain = "New paid equipment rental" . ($reservationId > 0 ? " #{$reservationId}" : '') . "\n\n"
        . "Customer: {$name}\nEmail: {$customerEmail}\nPhone: " . ($phone !== '' ? $phone : 'Not provided') . "\n"
        . "Dates: {$startDate} to {$endDate}\nEquipment: " . ($titles !== [] ? implode(', ', $titles) : 'Rental equipment') . "\n"
        . "Total paid: {$total}\n";
    return [
        'subject' => $subject,
        'headers' => [
            'MIME-Version: 1.0',
            'From: ' . rental_email_from(),
            'Reply-To: ' . ($customerEmail !== '' ? $customerEmail : rental_email_reply_to()),
            'Content-Type: text/plain; charset=UTF-8',
        ],
        'body' => $plain,
        'html' => '<pre>' . htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>',
        'plain' => $plain,
    ];
}

function rental_build_customer_email_message(array $reservation, array $items): array
{
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

    $subject = 'Thank you for your rental - Ardi Rent & Service';
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
        . rental_delivery_policy_html()
        . '<h2 style="font-size:18px;margin:22px 0 8px">Pickup instructions</h2>'
        . '<p style="font-size:15px;line-height:1.55;margin:0">Please review the pickup and delivery details below. Bring a valid ID and your order confirmation when receiving or returning the equipment.</p>'
        . rental_pickup_details_html()
        . '<p style="font-size:15px;line-height:1.55;margin:22px 0 0">If you have any questions before pickup, reply to this email and we will help you.</p>'
        . '<p style="font-size:15px;line-height:1.55;margin:18px 0 0">- Ardi Rent & Service</p>'
        . '</div></div></body></html>';

    $plainItems = implode(', ', array_filter(array_map(
        static fn(array $item): string => (string) ($item['title'] ?? $item['item_title'] ?? ''),
        $items
    )));
    $plain = "Thank you for renting with Ardi Rent & Service.\n\n"
        . "Rental dates: {$startDate} to {$endDate}\n"
        . "Items: " . ($plainItems !== '' ? $plainItems : 'Rental equipment') . "\n"
        . "Total paid: {$total}\n\n"
        . rental_delivery_policy_plain()
        . "\nPickup instructions:\n"
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

    return [
        'subject' => $subject,
        'headers' => $headers,
        'body' => $body,
        'html' => $html,
        'plain' => $plain,
    ];
}

function rental_smtp_configured(): bool
{
    return rental_env('RENTAL_SMTP_HOST') !== ''
        && rental_env('RENTAL_SMTP_USERNAME') !== ''
        && rental_env('RENTAL_SMTP_PASSWORD') !== '';
}

function rental_extract_email_address(string $headerValue): string
{
    if (preg_match('/<([^>]+)>/', $headerValue, $matches)) {
        return filter_var($matches[1], FILTER_VALIDATE_EMAIL) ?: '';
    }

    return filter_var($headerValue, FILTER_VALIDATE_EMAIL) ?: '';
}

function rental_smtp_read_response($socket): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function rental_smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    $response = rental_smtp_read_response($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Unexpected SMTP response: ' . trim($response));
    }
    return $response;
}

function rental_send_smtp_message(string $recipientEmail, array $message): bool
{
    $host = rental_env('RENTAL_SMTP_HOST');
    $port = (int) rental_env('RENTAL_SMTP_PORT', '587');
    $username = rental_env('RENTAL_SMTP_USERNAME');
    $password = rental_env('RENTAL_SMTP_PASSWORD');
    $encryption = strtolower(rental_env('RENTAL_SMTP_ENCRYPTION', 'starttls'));
    $fromHeader = rental_email_from();
    $fromEmail = rental_extract_email_address($fromHeader);

    if ($host === '' || $port <= 0 || $username === '' || $password === '' || $fromEmail === '') {
        return false;
    }

    $remote = $encryption === 'ssl' ? 'ssl://' . $host . ':' . $port : $host . ':' . $port;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ],
    ]);

    $socket = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!is_resource($socket)) {
        error_log("SMTP connection failed: {$errstr} ({$errno})");
        return false;
    }

    stream_set_timeout($socket, 30);

    try {
        $greeting = rental_smtp_read_response($socket);
        if ((int) substr($greeting, 0, 3) !== 220) {
            throw new RuntimeException('Unexpected SMTP greeting: ' . trim($greeting));
        }

        $hostname = $_SERVER['SERVER_NAME'] ?? 'ardirentservice.com';
        rental_smtp_command($socket, 'EHLO ' . $hostname, [250]);

        if (in_array($encryption, ['tls', 'starttls'], true)) {
            rental_smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Unable to enable SMTP TLS');
            }
            rental_smtp_command($socket, 'EHLO ' . $hostname, [250]);
        }

        rental_smtp_command($socket, 'AUTH LOGIN', [334]);
        rental_smtp_command($socket, base64_encode($username), [334]);
        rental_smtp_command($socket, base64_encode($password), [235]);
        rental_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        rental_smtp_command($socket, 'RCPT TO:<' . $recipientEmail . '>', [250, 251]);
        rental_smtp_command($socket, 'DATA', [354]);

        $headers = array_merge(
            [
                'To: ' . $recipientEmail,
                'Subject: ' . (string) ($message['subject'] ?? ''),
            ],
            $message['headers'] ?? []
        );
        $raw = implode("\r\n", $headers) . "\r\n\r\n" . (string) ($message['body'] ?? '');
        $raw = str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $raw);
        fwrite($socket, $raw . "\r\n.\r\n");
        $dataResponse = rental_smtp_read_response($socket);
        if ((int) substr($dataResponse, 0, 3) !== 250) {
            throw new RuntimeException('Unexpected SMTP DATA response: ' . trim($dataResponse));
        }

        rental_smtp_command($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        error_log('SMTP send failed: ' . $e->getMessage());
        rental_smtp_command_safely($socket, 'QUIT');
        fclose($socket);
        return false;
    }
}

function rental_smtp_command_safely($socket, string $command): void
{
    try {
        if (is_resource($socket)) {
            fwrite($socket, $command . "\r\n");
        }
    } catch (Throwable) {
        // Best-effort cleanup only.
    }
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
        'cable-hdmi'              => 100,
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
            fulfillment_status   TEXT NOT NULL DEFAULT "pending",
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

    $columnsStmt = $pdo->query('PRAGMA table_info(reservations)');
    $columns = $columnsStmt ? $columnsStmt->fetchAll() : [];
    $columnNames = array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $columns);
    if (!in_array('fulfillment_status', $columnNames, true)) {
        $pdo->exec("ALTER TABLE reservations ADD COLUMN fulfillment_status TEXT NOT NULL DEFAULT 'pending'");
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservation_dates ON reservations(start_date, end_date, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservation_items_item ON reservation_items(item_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservation_fulfillment ON reservations(fulfillment_status, created_at)');

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
        'SELECT ri.item_id, COUNT(*) AS reserved_count
         FROM reservation_items ri
         INNER JOIN reservations r ON r.id = ri.reservation_id
         WHERE r.status = ?
           AND r.start_date <= ?
           AND r.end_date   >= ?
           AND ri.item_id IN (' . $placeholders . ')
         GROUP BY ri.item_id';

    $stmt   = $pdo->prepare($sql);
    $params = array_merge(['paid', $endDate, $startDate], $itemIds);
    $stmt->execute($params);

    $rows = $stmt->fetchAll();
    $unavailable = [];
    foreach ($rows as $row) {
        $itemId = (string) ($row['item_id'] ?? '');
        if ($itemId === '') {
            continue;
        }
        $reservedCount = (int) ($row['reserved_count'] ?? 0);
        if ($reservedCount >= rental_item_inventory($itemId)) {
            $unavailable[] = $itemId;
        }
    }

    return array_values(array_unique($unavailable));
}

function rental_item_inventory(string $itemId): int
{
    // Default inventory per item is 1 until additional units are configured.
    $inventory = [
        // 'sony-a7-v' => 2,
    ];

    $value = (int) ($inventory[$itemId] ?? 1);
    return max(1, $value);
}

function rental_reservation_statuses(): array
{
    return ['pending', 'confirmed', 'ready', 'delivered', 'completed', 'cancelled'];
}

function rental_get_reservation(PDO $pdo, int $reservationId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? LIMIT 1');
    $stmt->execute([$reservationId]);
    $reservation = $stmt->fetch();
    if (!is_array($reservation)) {
        return null;
    }

    $itemsStmt = $pdo->prepare(
        'SELECT item_id, item_title, unit_amount_cents
         FROM reservation_items
         WHERE reservation_id = ?
         ORDER BY id ASC'
    );
    $itemsStmt->execute([$reservationId]);
    $reservation['items'] = $itemsStmt->fetchAll();

    return $reservation;
}

function rental_update_reservation_fulfillment_status(PDO $pdo, int $reservationId, string $status): bool
{
    if (!in_array($status, rental_reservation_statuses(), true)) {
        return false;
    }

    $stmt = $pdo->prepare('UPDATE reservations SET fulfillment_status = ? WHERE id = ?');
    return $stmt->execute([$status, $reservationId]);
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
