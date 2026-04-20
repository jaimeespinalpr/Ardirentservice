<?php
declare(strict_types=1);

function clean_text(?string $value): string
{
    $value = trim((string) $value);
    $value = strip_tags($value);

    return preg_replace('/\s+/', ' ', $value) ?? '';
}

function fail(string $message, int $statusCode = 400): never
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Stripe Checkout</title>';
    echo '<style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f5f5f5;color:#111;margin:0;min-height:100vh;display:grid;place-items:center;padding:24px}main{max-width:560px;background:#fff;border:1px solid rgba(0,0,0,.1);border-radius:20px;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,.08)}a{color:#111;font-weight:700}</style>';
    echo '</head><body><main><h1>Stripe Checkout is not ready yet</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p><a href="/#cart">Go back to the cart</a></p></main></body></html>';
    exit;
}

function get_env_value(array $names, ?string $default = null): ?string
{
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && trim((string) $value) !== '') {
            return trim((string) $value);
        }

        if (isset($_SERVER[$name]) && trim((string) $_SERVER[$name]) !== '') {
            return trim((string) $_SERVER[$name]);
        }

        if (isset($_ENV[$name]) && trim((string) $_ENV[$name]) !== '') {
            return trim((string) $_ENV[$name]);
        }
    }

    return $default;
}

function base_url(): string
{
    $host = clean_text($_SERVER['HTTP_HOST'] ?? 'ardirentservice.com');
    $scheme = 'https';

    if ((($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') === '443')) {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = clean_text($_SERVER['HTTP_X_FORWARDED_PROTO']);
    }

    return "{$scheme}://{$host}";
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Use the cart checkout button to send the rental list to Stripe.', 405);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$payload = [];

if (str_contains($contentType, 'application/json')) {
    $decoded = json_decode(file_get_contents('php://input') ?: '', true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
} else {
    $payload = $_POST;
}

$cartJson = (string) ($payload['cart'] ?? '');
$cart = json_decode($cartJson, true);

if (!is_array($cart) || $cart === []) {
    fail('Your cart is empty. Add at least one item before starting Stripe Checkout.');
}

$normalizedCart = [];
foreach ($cart as $item) {
    if (!is_array($item)) {
        continue;
    }

    $title = clean_text($item['title'] ?? '');
    if ($title === '') {
        continue;
    }

    $normalizedCart[] = [
        'id' => clean_text($item['id'] ?? ''),
        'section' => clean_text($item['section'] ?? ''),
        'title' => $title,
        'tag' => clean_text($item['tag'] ?? ''),
        'image' => clean_text($item['image'] ?? ''),
        'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
    ];
}

if ($normalizedCart === []) {
    fail('Your cart is empty. Add at least one item before starting Stripe Checkout.');
}

$secretKey = get_env_value(['STRIPE_SECRET_KEY']);
if ($secretKey === null) {
    fail('Set STRIPE_SECRET_KEY on Hostinger before using Stripe Checkout.');
}

$depositAmount = get_env_value(['STRIPE_DEPOSIT_AMOUNT_CENTS', 'STRIPE_DEPOSIT_AMOUNT']);
if ($depositAmount === null || !ctype_digit($depositAmount) || (int) $depositAmount <= 0) {
    fail('Set STRIPE_DEPOSIT_AMOUNT_CENTS in cents before using Stripe Checkout.');
}

$currency = strtolower(get_env_value(['STRIPE_CURRENCY'], 'usd') ?? 'usd');
$label = get_env_value(['STRIPE_DEPOSIT_LABEL'], 'Ardi Rent & Service rental deposit');
$lang = clean_text((string) ($payload['lang'] ?? 'en'));
$lang = $lang === 'es' ? 'es' : 'en';

$summaryParts = array_map(
    static function (array $item): string {
        $line = $item['title'];
        if ($item['tag'] !== '') {
            $line .= " ({$item['tag']})";
        }
        if ($item['quantity'] > 1) {
            $line .= ' x' . $item['quantity'];
        }
        return $line;
    },
    $normalizedCart
);

$summary = implode('; ', $summaryParts);
$summary = substr($summary, 0, 450);

$successUrl = get_env_value(['STRIPE_SUCCESS_URL'], base_url() . '/?checkout=success&session_id={CHECKOUT_SESSION_ID}#cart');
$cancelUrl = get_env_value(['STRIPE_CANCEL_URL'], base_url() . '/?checkout=cancel#cart');

$stripePayload = [
    'mode' => 'payment',
    'success_url' => $successUrl,
    'cancel_url' => $cancelUrl,
    'payment_method_types[0]' => 'card',
    'line_items[0][price_data][currency]' => $currency,
    'line_items[0][price_data][product_data][name]' => $label,
    'line_items[0][price_data][product_data][description]' => $summary,
    'line_items[0][price_data][unit_amount]' => (string) $depositAmount,
    'line_items[0][quantity]' => '1',
    'metadata[cart_count]' => (string) count($normalizedCart),
    'metadata[cart_summary]' => $summary,
    'metadata[language]' => $lang,
    'metadata[source]' => 'ardi-rent-service',
];

if (!function_exists('curl_init')) {
    fail('cURL is not available on this server. Enable it to use Stripe Checkout.');
}

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => http_build_query($stripePayload),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/x-www-form-urlencoded',
    ],
]);

$response = curl_exec($ch);

if ($response === false) {
    $error = curl_error($ch) ?: 'Unknown Stripe transport error.';
    curl_close($ch);
    fail('Stripe request failed: ' . $error, 502);
}

$statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$decoded = json_decode($response, true);
if (!is_array($decoded) || $statusCode < 200 || $statusCode >= 300) {
    $message = 'Stripe could not create a checkout session.';
    if (is_array($decoded) && isset($decoded['error']['message'])) {
        $message = clean_text((string) $decoded['error']['message']);
    }
    fail($message, 502);
}

$checkoutUrl = clean_text((string) ($decoded['url'] ?? ''));
if ($checkoutUrl === '') {
    fail('Stripe did not return a checkout URL.', 502);
}

header('Location: ' . $checkoutUrl, true, 303);
exit;
