<?php
declare(strict_types=1);
require_once __DIR__ . '/rentals_common.php';
require_once __DIR__ . '/accounts_common.php';
require_once __DIR__ . '/supabase_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rental_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

function rental_release_checkout_discount(PDO $pdo, string $backend, ?array $supabaseUser, int $accountId, string $token): void
{
    if ($token === '') {
        return;
    }
    if ($backend === 'supabase' && is_array($supabaseUser) && ($supabaseUser['id'] ?? '') !== '') {
        supabase_release_welcome_discount((string) $supabaseUser['id'], $token);
        return;
    }
    if ($accountId > 0) {
        account_release_discount($pdo, $accountId, $token);
    }
}

$payload = rental_read_json_body();
$startDate = rental_validate_date(rental_clean_text($payload['start_date'] ?? ''));
$endDate = rental_validate_date(rental_clean_text($payload['end_date'] ?? ''));

if ($startDate === null || $endDate === null || $startDate > $endDate) {
    rental_json(['ok' => false, 'error' => 'invalid_dates'], 422);
}

$customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
$customerName = rental_clean_text($customer['name'] ?? '');
$customerEmail = filter_var(rental_clean_text($customer['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';
$customerPhone = rental_clean_text($customer['phone'] ?? '');

if ($customerName === '' || $customerEmail === '') {
    rental_json(['ok' => false, 'error' => 'missing_customer'], 422);
}

$itemsInput = is_array($payload['items'] ?? null) ? $payload['items'] : [];
$items = [];
foreach ($itemsInput as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $id = rental_normalize_item_id((string) ($entry['id'] ?? ''));
    $title = rental_clean_text($entry['title'] ?? '');
    if ($id === null || $title === '') {
        continue;
    }
    $items[$id] = ['id' => $id, 'title' => $title, 'rate_cents' => rental_item_rate_cents($id)];
}
$items = array_values($items);

if ($items === []) {
    rental_json(['ok' => false, 'error' => 'no_items_selected'], 422);
}

$pdo = rental_db();
$itemIds = array_map(static fn(array $item): string => $item['id'], $items);
$unavailable = rental_find_unavailable_items($pdo, $startDate, $endDate, $itemIds);
if ($unavailable !== []) {
    rental_json(['ok' => false, 'error' => 'items_unavailable', 'unavailable' => $unavailable], 409);
}

$days = rental_days_between($startDate, $endDate);
$subtotalCents = 0;
foreach ($items as $item) {
    $subtotalCents += ((int) $item['rate_cents']) * $days;
}

$accountBackend = strtolower(rental_clean_text(rental_env('ACCOUNT_BACKEND', 'sqlite')));
$supabaseUser = null;
$sqliteAccountUser = null;
$accountId = 0;
// guest checkout remains available; account_backend === 'supabase' only adds bearer-authenticated account benefits.
$discountToken = '';
$discountCents = 0;

if ($accountBackend === 'supabase') {
    $supabaseUser = supabase_validate_bearer();
    if (is_array($supabaseUser) && ($supabaseUser['id'] ?? '') !== '' && $subtotalCents >= WELCOME_DISCOUNT_CENTS) {
        $discountToken = supabase_reserve_welcome_discount((string) $supabaseUser['id']) ?? '';
        if ($discountToken !== '') {
            $discountCents = WELCOME_DISCOUNT_CENTS;
        }
    }
}

if ($accountBackend !== 'supabase' && $discountToken === '') {
    $sqliteAccountUser = account_current_user($pdo);
    if ($sqliteAccountUser !== null && $subtotalCents >= WELCOME_DISCOUNT_CENTS) {
        $accountId = (int) $sqliteAccountUser['id'];
        $discountToken = account_reserve_welcome_discount($pdo, $accountId) ?? '';
        if ($discountToken !== '') {
            $discountCents = WELCOME_DISCOUNT_CENTS;
        }
    }
}

$intentBackend = $accountBackend === 'supabase' && is_array($supabaseUser) && ($supabaseUser['id'] ?? '') !== ''
    ? 'supabase'
    : ($accountId > 0 ? 'sqlite' : 'guest');
$checkoutIntentId = rental_create_checkout_intent($pdo, [
    'account_backend' => $intentBackend,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'customer_name' => $customerName,
    'customer_email' => $customerEmail,
    'customer_phone' => $customerPhone,
    'items' => $items,
    'subtotal_amount_cents' => $subtotalCents,
    'discount_cents' => $discountCents,
    'total_amount_cents' => max(0, $subtotalCents - $discountCents),
    'currency' => CURRENCY,
    'supabase_user_id' => $intentBackend === 'supabase' ? (string) $supabaseUser['id'] : '',
    'supabase_reservation_token' => $intentBackend === 'supabase' ? $discountToken : '',
    'account_id' => $accountId,
    'sqlite_discount_token' => $intentBackend === 'sqlite' ? $discountToken : '',
]);

$form = [
    'mode' => 'payment',
    'payment_method_types[0]' => 'card',
    'success_url' => rental_base_url() . '/rentals_confirm.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => rental_public_url('equipment.html?rental=cancelled'),
    'customer_email' => $customerEmail,
    'metadata[checkout_intent_id]' => $checkoutIntentId,
    'metadata[account_backend]' => $intentBackend,
    'client_reference_id' => $checkoutIntentId,
];

if ($discountCents > 0) {
    $couponId = 'ardi-welcome-5';
    $coupon = stripe_request('GET', 'coupons/' . rawurlencode($couponId));
    if (!$coupon['ok']) {
        $coupon = stripe_request('POST', 'coupons', [
            'id' => $couponId,
            'amount_off' => (string) WELCOME_DISCOUNT_CENTS,
            'currency' => CURRENCY,
            'duration' => 'once',
            'name' => 'Ardi account welcome discount',
        ]);
    }
    if (!$coupon['ok']) {
        rental_release_checkout_intent($pdo, $checkoutIntentId, 'discount_setup_failed');
        rental_json(['ok' => false, 'error' => 'discount_setup_failed'], 502);
    }
    $form['discounts[0][coupon]'] = $couponId;
}

foreach (array_values($items) as $index => $item) {
    $unitAmount = ((int) $item['rate_cents']) * $days;
    $form["line_items[{$index}][price_data][currency]"] = CURRENCY;
    $form["line_items[{$index}][price_data][unit_amount]"] = (string) $unitAmount;
    $form["line_items[{$index}][price_data][product_data][name]"] = $item['title'];
    $form["line_items[{$index}][quantity]"] = '1';
}

$stripe = stripe_request('POST', 'checkout/sessions', $form);
if (!$stripe['ok']) {
    rental_release_checkout_intent($pdo, $checkoutIntentId, 'stripe_checkout_failed');
    rental_json(['ok' => false, 'error' => 'stripe_checkout_failed', 'details' => $stripe['error'] ?? 'unknown'], 502);
}

$session = $stripe['data'];
$checkoutSessionId = rental_clean_text((string) ($session['id'] ?? ''));
$checkoutUrl = (string) ($session['url'] ?? '');
if ($checkoutSessionId === '' || $checkoutUrl === '') {
    rental_release_checkout_intent($pdo, $checkoutIntentId, 'missing_checkout_url');
    rental_json(['ok' => false, 'error' => 'missing_checkout_url'], 502);
}
rental_attach_checkout_session($pdo, $checkoutIntentId, $checkoutSessionId, rental_clean_text((string) ($session['payment_status'] ?? 'unpaid')));

rental_json([
    'ok' => true,
    'checkout_url' => $checkoutUrl,
    'discount_cents' => $discountCents,
]);
