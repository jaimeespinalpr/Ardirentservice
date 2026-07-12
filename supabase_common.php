<?php
declare(strict_types=1);

function supabase_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return is_string($value) && $value !== '' ? trim($value) : $default;
}

function supabase_base_url(): string
{
    $value = getenv('SUPABASE_URL');
    return rtrim(is_string($value) ? trim($value) : '', '/');
}

function supabase_publishable_key(): string
{
    $value = getenv('SUPABASE_PUBLISHABLE_KEY');
    return is_string($value) ? trim($value) : '';
}

function supabase_server_key(): string
{
    $secretValue = getenv('SUPABASE_SECRET_KEY');
    $secret = is_string($secretValue) ? trim($secretValue) : '';
    if ($secret !== '') {
        return $secret;
    }
    $legacyValue = getenv('SUPABASE_SERVICE_ROLE_KEY');
    return is_string($legacyValue) ? trim($legacyValue) : '';
}

function supabase_allowed_origins(): array
{
    return [
        'https://ardirentservice.com',
        'https://www.ardirentservice.com',
    ];
}

function supabase_apply_cors(): void
{
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? rtrim((string) $_SERVER['HTTP_ORIGIN'], '/') : '';
    if ($origin !== '' && in_array($origin, supabase_allowed_origins(), true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, apikey, x-client-info');
        header('Access-Control-Max-Age: 86400');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function supabase_extract_bearer_token(?string $header = null): string
{
    $header ??= (string) (
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? $_SERVER['Authorization']
        ?? ''
    );

    if ($header === '' || !preg_match('/^\s*Bearer\s+(.+)\s*$/i', $header, $matches)) {
        return '';
    }

    return trim($matches[1]);
}

function supabase_http_request(string $method, string $path, array $headers = [], ?array $jsonBody = null): array
{
    $baseUrl = supabase_base_url();
    if ($baseUrl === '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'supabase_not_configured'];
    }

    $url = $baseUrl . '/' . ltrim($path, '/');
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    $body = null;

    if ($jsonBody !== null) {
        $body = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'supabase_encode_failed'];
        }
        $requestHeaders[] = 'Content-Type: application/json';
    }

    $responseBody = '';
    $status = 0;
    $transportError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'supabase_request_failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADER => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $transportError = curl_error($ch);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $requestHeaders),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 15,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            $transportError = 'stream_error';
        }
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            $status = (int) $matches[1];
        }
    }

    if ($transportError !== '') {
        error_log('Supabase request failed: ' . $method . ' ' . $path);
        return ['ok' => false, 'status' => $status, 'data' => null, 'error' => 'supabase_request_failed'];
    }

    $decoded = null;
    if (is_string($responseBody) && $responseBody !== '') {
        $decoded = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $decoded = $responseBody;
        }
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'data' => $decoded,
        'error' => $status >= 200 && $status < 300 ? null : 'supabase_http_' . $status,
    ];
}

function supabase_validate_bearer(?string $authorizationHeader = null): ?array
{
    $token = supabase_extract_bearer_token($authorizationHeader);
    if ($token === '') {
        return null;
    }

    $publishableKey = supabase_publishable_key();
    if ($publishableKey === '') {
        error_log('Supabase auth unavailable');
        return null;
    }

    $response = supabase_http_request('GET', '/auth/v1/user', [
        'Authorization: Bearer ' . $token,
        'apikey: ' . $publishableKey,
    ]);

    if (!$response['ok'] || !is_array($response['data']) || !isset($response['data']['id'])) {
        error_log('Supabase bearer validation failed');
        return null;
    }

    $confirmedAt = trim((string) ($response['data']['email_confirmed_at'] ?? $response['data']['confirmed_at'] ?? ''));
    if ($confirmedAt === '') {
        error_log('Supabase bearer email is not confirmed');
        return null;
    }

    return $response['data'];
}

function supabase_reserve_welcome_discount(string $userId): ?string
{
    $serverKey = supabase_server_key();
    if ($serverKey === '') {
        error_log('Supabase discount reservation unavailable');
        return null;
    }

    $response = supabase_http_request('POST', '/rest/v1/rpc/reserve_welcome_discount', [
        'Authorization: Bearer ' . $serverKey,
        'apikey: ' . $serverKey,
        'Prefer: params=single-object',
    ], [
        'p_user_id' => $userId,
    ]);

    if (!$response['ok']) {
        error_log('Supabase discount reservation failed');
        return null;
    }

    $token = $response['data'];
    if (is_string($token) && $token !== '') {
        return $token;
    }

    return null;
}

function supabase_release_welcome_discount(string $userId, string $reservationToken): bool
{
    $serverKey = supabase_server_key();
    if ($serverKey === '') {
        return false;
    }

    $response = supabase_http_request('POST', '/rest/v1/rpc/release_welcome_discount', [
        'Authorization: Bearer ' . $serverKey,
        'apikey: ' . $serverKey,
        'Prefer: params=single-object',
    ], [
        'p_user_id' => $userId,
        'p_reservation_token' => $reservationToken,
    ]);

    if (!$response['ok']) {
        error_log('Supabase discount release failed');
        return false;
    }

    return true;
}

function supabase_consume_welcome_discount(string $userId, string $reservationToken): bool
{
    $serverKey = supabase_server_key();
    if ($serverKey === '') {
        return false;
    }

    $response = supabase_http_request('POST', '/rest/v1/rpc/consume_welcome_discount', [
        'Authorization: Bearer ' . $serverKey,
        'apikey: ' . $serverKey,
        'Prefer: params=single-object',
    ], [
        'p_user_id' => $userId,
        'p_reservation_token' => $reservationToken,
    ]);

    if (!$response['ok']) {
        error_log('Supabase discount consume failed');
        return false;
    }

    return true;
}
