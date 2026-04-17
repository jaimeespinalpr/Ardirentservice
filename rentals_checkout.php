<?php
declare(strict_types=1);
require_once __DIR__ . '/rentals_common.php';

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
    $items[$id] = ['id' => $id, 'title' => $title];
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
$unitAmount = DAILY_RATE_CENTS * $days;
$totalAmount = $unitAmount * count($items);

$form = [
    'mode' => 'payment',
    'success_url' => rental_base_url() . '/rentals_confirm.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => rental_base_url() . '/equipment.html?rental=cancelled',
    'customer_email' => $customerEmail,
    'metadata[start_date]' => $startDate,
    'metadata[end_date]' => $endDate,
    'metadata[customer_name]' => $customerName,
    'metadata[customer_email]' => $customerEmail,
    'metadata[customer_phone]' => $customerPhone,
    'metadata[item_ids]' => json_encode($itemIds, JSON_UNESCAPED_UNICODE),
    'metadata[item_titles]' => json_encode(array_map(static fn(array $item): string => $item['title'], $items), JSON_UNESCAPED_UNICODE),
    'metadata[total_amount_cents]' => (string) $totalAmount,
    'metadata[currency]' => CURRENCY,
];

foreach (array_values($items) as $index => $item) {
    $form["line_items[{$index}][price_data][currency]"] = CURRENCY;
    $form["line_items[{$index}][price_data][unit_amount]"] = (string) $unitAmount;
    $form["line_items[{$index}][price_data][product_data][name]"] = $item['title'];
    $form["line_items[{$index}][quantity]"] = '1';
}

$stripe = stripe_request('POST', 'checkout/sessions', $form);
if (!$stripe['ok']) {
    rental_json(['ok' => false, 'error' => 'stripe_checkout_failed', 'details' => $stripe['error'] ?? 'unknown'], 502);
}

$session = $stripe['data'];
$checkoutUrl = (string) ($session['url'] ?? '');
if ($checkoutUrl === '') {
    rental_json(['ok' => false, 'error' => 'missing_checkout_url'], 502);
}

rental_json([
    'ok' => true,
    'checkout_url' => $checkoutUrl,
]);
