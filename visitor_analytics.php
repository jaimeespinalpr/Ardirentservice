<?php
declare(strict_types=1);
define('RENTAL_SKIP_AUTO_CORS', true);
require_once __DIR__ . '/rentals_common.php';
require_once __DIR__ . '/visitor_analytics_common.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$origin = isset($_SERVER['HTTP_ORIGIN']) ? rtrim((string) $_SERVER['HTTP_ORIGIN'], '/') : '';
if (in_array($origin, visitor_allowed_origins(), true)) {
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = strtolower(rental_clean_text($_GET['action'] ?? 'event'));
$now = time();

function visitor_require_monitor_auth(): void
{
    $configured = rental_env('VISITOR_ANALYTICS_TOKEN');
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($configured === '' || !str_starts_with($authorization, 'Bearer ')) {
        rental_json(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    $provided = substr($authorization, 7);
    if (!hash_equals($configured, $provided)) {
        rental_json(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

function visitor_require_public_origin(): void
{
    $origin = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
    if ($origin === '' || !in_array($origin, visitor_allowed_origins(), true)) {
        rental_json(['ok' => false, 'error' => 'origin_not_allowed'], 403);
    }
}

try {
    $pdo = visitor_db();
    visitor_prepare_db($pdo);

    if ($method === 'GET' && $action === 'pending') {
        visitor_require_monitor_auth();
        rental_json(['ok' => true, 'notifications' => visitor_pending_notifications($pdo, $now)]);
    }

    if ($method === 'POST' && $action === 'ack') {
        visitor_require_monitor_auth();
        $payload = rental_read_json_body();
        $updated = visitor_ack_notifications($pdo, $payload, $now);
        rental_json(['ok' => true, 'updated' => $updated]);
    }

    if ($method !== 'POST' || $action !== 'event') {
        rental_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }

    visitor_require_public_origin();
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 8192) {
        rental_json(['ok' => false, 'error' => 'payload_too_large'], 413);
    }
    $result = visitor_record_event($pdo, rental_read_json_body(), $now);
    rental_json(['ok' => true] + $result, 202);
} catch (InvalidArgumentException $error) {
    $status = match ($error->getMessage()) {
        'rate_limited' => 429,
        'unknown_session' => 409,
        default => 422,
    };
    rental_json(['ok' => false, 'error' => $error->getMessage()], $status);
} catch (Throwable $error) {
    error_log('Visitor analytics endpoint failed: ' . $error->getMessage());
    rental_json(['ok' => false, 'error' => 'server_error'], 500);
}
