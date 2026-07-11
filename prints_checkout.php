<?php
declare(strict_types=1);
require_once __DIR__ . '/rentals_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rental_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

/**
 * Canonical print catalog. Prices and shipping are always recalculated here;
 * values supplied by the browser are never trusted.
 */
const PRINT_CATALOG = [
    'fine' => [
        'label' => 'Fine Art Print (unframed)',
        'prices' => ['small' => 12900, 'medium' => 39900, 'large' => 89900, 'collector' => 229900],
    ],
    'framed' => [
        'label' => 'Framed Print',
        'prices' => ['small' => 24900, 'medium' => 69900, 'large' => 149900, 'collector' => 399900],
    ],
];

const PRINT_SIZES = [
    'small' => 'Small — 20 × 30 cm (8 × 12 in)',
    'medium' => 'Medium — 50 × 75 cm (20 × 30 in)',
    'large' => 'Large — 80 × 120 cm (32 × 48 in)',
    'collector' => 'Collector — 120 × 160 cm (48 × 63 in)',
];

// Zone, speed, finish and size based flat rates. These include protective
// art packaging; framed and oversized work costs more to ship safely.
const PRINT_SHIPPING = [
    'puerto-rico' => [
        'label' => 'Puerto Rico',
        'countries' => ['US'],
        'fine' => [
            'standard' => ['small' => 1800, 'medium' => 2800, 'large' => 4800, 'collector' => 9500],
            'express' => ['small' => 4200, 'medium' => 5800, 'large' => 9500, 'collector' => 18500],
        ],
        'framed' => [
            'standard' => ['small' => 3500, 'medium' => 7500, 'large' => 14500, 'collector' => 29500],
            'express' => ['small' => 7000, 'medium' => 14500, 'large' => 27500, 'collector' => 55000],
        ],
    ],
    'united-states' => [
        'label' => 'United States',
        'countries' => ['US'],
        'fine' => [
            'standard' => ['small' => 2800, 'medium' => 4500, 'large' => 7500, 'collector' => 14500],
            'express' => ['small' => 5800, 'medium' => 8500, 'large' => 14500, 'collector' => 27500],
        ],
        'framed' => [
            'standard' => ['small' => 5500, 'medium' => 11500, 'large' => 22500, 'collector' => 49500],
            'express' => ['small' => 10500, 'medium' => 22000, 'large' => 42500, 'collector' => 89500],
        ],
    ],
    'international' => [
        'label' => 'International',
        'countries' => ['CA','MX','GB','IE','FR','DE','ES','IT','PT','NL','BE','AT','CH','DK','SE','NO','FI','AU','NZ','JP'],
        'fine' => [
            'standard' => ['small' => 7500, 'medium' => 11000, 'large' => 17000, 'collector' => 29500],
            'express' => ['small' => 14500, 'medium' => 21000, 'large' => 32000, 'collector' => 52500],
        ],
        'framed' => [
            'standard' => ['small' => 13500, 'medium' => 25000, 'large' => 47500, 'collector' => 95000],
            'express' => ['small' => 25000, 'medium' => 45000, 'large' => 85000, 'collector' => 165000],
        ],
    ],
];

const SHIPPING_SPEEDS = [
    'standard' => ['label' => 'Standard tracked shipping', 'estimate' => '5–10 business days after production'],
    'express' => ['label' => 'Express priority shipping', 'estimate' => '2–5 business days after production'],
];

$payload = rental_read_json_body();
$printId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($payload['print_id'] ?? '')) ?: '';
$requestedTitle = trim(strip_tags((string) ($payload['title'] ?? '')));
$finish = (string) ($payload['finish'] ?? '');
$size = (string) ($payload['size'] ?? '');
$zone = (string) ($payload['zone'] ?? '');
$speed = (string) ($payload['speed'] ?? '');
$lang = (($payload['lang'] ?? 'en') === 'es') ? 'es' : 'en';

if ($printId === '' || $requestedTitle === '' || mb_strlen($requestedTitle) > 160) {
    rental_json(['ok' => false, 'error' => 'invalid_print'], 422);
}

$metadataPath = __DIR__ . '/data/prints_metadata.json';
$metadata = is_file($metadataPath) ? json_decode((string) file_get_contents($metadataPath), true) : null;
$title = '';
foreach (($metadata['prints'] ?? []) as $print) {
    if (is_array($print) && hash_equals((string) ($print['id'] ?? ''), $printId)) {
        $title = trim((string) ($print['displayTitle'] ?? $print['title'] ?? $printId));
        break;
    }
}
if ($title === '') {
    rental_json(['ok' => false, 'error' => 'unknown_print'], 422);
}
if (!isset(PRINT_CATALOG[$finish]['prices'][$size], PRINT_SIZES[$size])) {
    rental_json(['ok' => false, 'error' => 'invalid_configuration'], 422);
}
if (!isset(PRINT_SHIPPING[$zone][$finish][$speed][$size], SHIPPING_SPEEDS[$speed])) {
    rental_json(['ok' => false, 'error' => 'invalid_shipping'], 422);
}

$productCents = PRINT_CATALOG[$finish]['prices'][$size];
$shippingCents = PRINT_SHIPPING[$zone][$finish][$speed][$size];
$zoneData = PRINT_SHIPPING[$zone];
$speedData = SHIPPING_SPEEDS[$speed];
$productName = $title . ' — ' . PRINT_CATALOG[$finish]['label'];
$shippingName = $speedData['label'] . ' — ' . $zoneData['label'];
$description = PRINT_SIZES[$size];
$successCopy = $lang === 'es' ? 'print=success' : 'print=success&lang=en';

$form = [
    'mode' => 'payment',
    'payment_method_types[0]' => 'card',
    'success_url' => rental_public_url('prints.html?' . $successCopy . '&session_id={CHECKOUT_SESSION_ID}'),
    'cancel_url' => rental_public_url('prints.html?print=cancelled'),
    'billing_address_collection' => 'required',
    'shipping_address_collection[allowed_countries]' => $zoneData['countries'],
    'phone_number_collection[enabled]' => 'true',
    'customer_creation' => 'always',
    'locale' => $lang === 'es' ? 'es' : 'en',
    'metadata[order_type]' => 'fine_art_print',
    'metadata[print_id]' => $printId,
    'metadata[print_title]' => $title,
    'metadata[finish]' => $finish,
    'metadata[size]' => $size,
    'metadata[shipping_zone]' => $zone,
    'metadata[shipping_speed]' => $speed,
    'metadata[product_amount_cents]' => (string) $productCents,
    'metadata[shipping_amount_cents]' => (string) $shippingCents,
    'line_items[0][price_data][currency]' => CURRENCY,
    'line_items[0][price_data][unit_amount]' => (string) $productCents,
    'line_items[0][price_data][product_data][name]' => $productName,
    'line_items[0][price_data][product_data][description]' => $description,
    'line_items[0][quantity]' => '1',
    'shipping_options[0][shipping_rate_data][type]' => 'fixed_amount',
    'shipping_options[0][shipping_rate_data][fixed_amount][amount]' => (string) $shippingCents,
    'shipping_options[0][shipping_rate_data][fixed_amount][currency]' => CURRENCY,
    'shipping_options[0][shipping_rate_data][display_name]' => $shippingName,
    'shipping_options[0][shipping_rate_data][delivery_estimate][minimum][unit]' => 'business_day',
    'shipping_options[0][shipping_rate_data][delivery_estimate][minimum][value]' => $speed === 'express' ? '2' : '5',
    'shipping_options[0][shipping_rate_data][delivery_estimate][maximum][unit]' => 'business_day',
    'shipping_options[0][shipping_rate_data][delivery_estimate][maximum][value]' => $speed === 'express' ? '5' : '10',
];

$stripe = stripe_request('POST', 'checkout/sessions', $form);
if (!$stripe['ok']) {
    error_log('Print checkout failed: ' . (string) ($stripe['error'] ?? 'unknown'));
    rental_json(['ok' => false, 'error' => 'stripe_checkout_failed'], 502);
}

$checkoutUrl = (string) ($stripe['data']['url'] ?? '');
if ($checkoutUrl === '') {
    rental_json(['ok' => false, 'error' => 'missing_checkout_url'], 502);
}

rental_json([
    'ok' => true,
    'checkout_url' => $checkoutUrl,
    'product_amount_cents' => $productCents,
    'shipping_amount_cents' => $shippingCents,
]);
