<?php
declare(strict_types=1);
require_once __DIR__ . '/rentals_common.php';
require_once __DIR__ . '/accounts_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rental_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
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
$totalAmount = 0;
foreach ($items as $item) {
    $totalAmount += ((int) $item['rate_cents']) * $days;
}

$accountUser = account_current_user($pdo);
$discountCents = 0;
$discountToken = '';
$accountId = 0;
if ($accountUser !== null && $totalAmount >= WELCOME_DISCOUNT_CENTS) {
    $accountId = (int) $accountUser['id'];
    $discountToken = account_reserve_welcome_discount($pdo, $accountId) ?? '';
    if ($discountToken !== '') {
        $discountCents = WELCOME_DISCOUNT_CENTS;
    }
}

$form = [
    'mode' => 'payment',
    'payment_method_types[0]' => 'card',
    'success_url' => rental_base_url() . '/rentals_confirm.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => rental_public_url('equipment.html?rental=cancelled'),
    'customer_email' => $customerEmail,
    'metadata[start_date]' => $startDate,
    'metadata[end_date]' => $endDate,
    'metadata[customer_name]' => $customerName,
    'metadata[customer_email]' => $customerEmail,
    'metadata[customer_phone]' => $customerPhone,
    'metadata[item_ids]' => json_encode($itemIds, JSON_UNESCAPED_UNICODE),
    'metadata[item_titles]' => json_encode(array_map(static fn(array $item): string => $item['title'], $items), JSON_UNESCAPED_UNICODE),
    'metadata[item_rates_cents]' => json_encode(array_map(static fn(array $item): int => (int) $item['rate_cents'], $items), JSON_UNESCAPED_UNICODE),
    'metadata[total_amount_cents]' => (string) $totalAmount,
    'metadata[account_id]' => (string) $accountId,
    'metadata[welcome_discount_cents]' => (string) $discountCents,
    'metadata[welcome_discount_token]' => $discountToken,
    'metadata[currency]' => CURRENCY,
];

if ($accountId > 0) {
    $form['client_reference_id'] = 'account-' . $accountId;
}

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
        account_release_discount($pdo, $accountId, $discountToken);
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
    if ($discountToken !== '') {
        account_release_discount($pdo, $accountId, $discountToken);
    }
    rental_json(['ok' => false, 'error' => 'stripe_checkout_failed', 'details' => $stripe['error'] ?? 'unknown'], 502);
}

$session = $stripe['data'];
$checkoutUrl = (string) ($session['url'] ?? '');
if ($checkoutUrl === '') {
    if ($discountToken !== '') {
        account_release_discount($pdo, $accountId, $discountToken);
    }
    rental_json(['ok' => false, 'error' => 'missing_checkout_url'], 502);
}

rental_json([
    'ok' => true,
    'checkout_url' => $checkoutUrl,
    'discount_cents' => $discountCents,
]);
