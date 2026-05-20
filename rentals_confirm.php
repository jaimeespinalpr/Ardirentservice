<?php
declare(strict_types=1);
require_once __DIR__ . '/rentals_common.php';

$sessionId = rental_clean_text($_GET['session_id'] ?? '');
if ($sessionId === '') {
    rental_redirect(rental_public_url('equipment.html?rental=error'));
}

$stripe = stripe_request('GET', 'checkout/sessions/' . rawurlencode($sessionId));
if (!$stripe['ok']) {
    rental_redirect(rental_public_url('equipment.html?rental=error'));
}

$session = $stripe['data'];
$paymentStatus = (string) ($session['payment_status'] ?? '');
if ($paymentStatus !== 'paid') {
    rental_redirect(rental_public_url('equipment.html?rental=unpaid'));
}

$metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
$startDate = rental_validate_date(rental_clean_text($metadata['start_date'] ?? ''));
$endDate = rental_validate_date(rental_clean_text($metadata['end_date'] ?? ''));
$customerName = rental_clean_text($metadata['customer_name'] ?? '');
$customerEmail = rental_clean_text($metadata['customer_email'] ?? '');
$customerPhone = rental_clean_text($metadata['customer_phone'] ?? '');
$totalAmount = (int) ($metadata['total_amount_cents'] ?? 0);
$currency = rental_clean_text($metadata['currency'] ?? CURRENCY);

$itemIds = json_decode((string) ($metadata['item_ids'] ?? '[]'), true);
$itemTitles = json_decode((string) ($metadata['item_titles'] ?? '[]'), true);
$itemRates = json_decode((string) ($metadata['item_rates_cents'] ?? '[]'), true);

if (!is_array($itemIds) || !is_array($itemTitles) || $startDate === null || $endDate === null || $startDate > $endDate) {
    rental_redirect(rental_public_url('equipment.html?rental=error'));
}

$cleanItemIds = [];
foreach ($itemIds as $rawId) {
    $id = rental_normalize_item_id((string) $rawId);
    if ($id !== null) {
        $cleanItemIds[] = $id;
    }
}
$cleanItemIds = array_values(array_unique($cleanItemIds));

if ($cleanItemIds === []) {
    rental_redirect(rental_public_url('equipment.html?rental=error'));
}

$cleanTitles = [];
foreach ($itemTitles as $rawTitle) {
    $title = rental_clean_text((string) $rawTitle);
    if ($title !== '') {
        $cleanTitles[] = $title;
    }
}

$cleanRates = [];
if (is_array($itemRates)) {
    foreach ($itemRates as $rawRate) {
        $rate = (int) $rawRate;
        if ($rate > 0) {
            $cleanRates[] = $rate;
        }
    }
}

$pdo = rental_db();

$existsStmt = $pdo->prepare('SELECT id FROM reservations WHERE checkout_session_id = ? LIMIT 1');
$existsStmt->execute([$sessionId]);
$existing = $existsStmt->fetch();
if (is_array($existing)) {
    rental_redirect(rental_public_url('equipment.html?rental=confirmed&start=' . rawurlencode($startDate) . '&end=' . rawurlencode($endDate)));
}

$pdo->beginTransaction();
try {
    $stillUnavailable = rental_find_unavailable_items($pdo, $startDate, $endDate, $cleanItemIds);
    if ($stillUnavailable !== []) {
        $pdo->rollBack();
        rental_redirect(rental_public_url('equipment.html?rental=conflict'));
    }

    $insertReservation = $pdo->prepare(
        'INSERT INTO reservations (
            checkout_session_id, start_date, end_date, customer_name, customer_email, customer_phone,
            total_amount_cents, currency, status, fulfillment_status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $insertReservation->execute([
        $sessionId,
        $startDate,
        $endDate,
        $customerName !== '' ? $customerName : 'Unknown',
        $customerEmail !== '' ? $customerEmail : 'unknown@example.com',
        $customerPhone,
        $totalAmount,
        $currency !== '' ? $currency : CURRENCY,
        'paid',
        'pending',
        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
    ]);

    $reservationId = (int) $pdo->lastInsertId();
    $insertItem = $pdo->prepare(
        'INSERT INTO reservation_items (reservation_id, item_id, item_title, unit_amount_cents) VALUES (?, ?, ?, ?)'
    );

    foreach ($cleanItemIds as $index => $itemId) {
        $title = $cleanTitles[$index] ?? $itemId;
        $unitAmount = (int) ($cleanRates[$index] ?? rental_item_rate_cents($itemId));
        $insertItem->execute([$reservationId, $itemId, $title, $unitAmount]);
    }

    $pdo->commit();

    rental_send_customer_email(
        [
            'customer_name' => $customerName !== '' ? $customerName : 'Unknown',
            'customer_email' => $customerEmail !== '' ? $customerEmail : 'unknown@example.com',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_amount_cents' => $totalAmount,
            'currency' => $currency !== '' ? $currency : CURRENCY,
        ],
        array_map(
            static fn(string $itemId, int $index): array => [
                'item_id' => $itemId,
                'title' => $cleanTitles[$index] ?? $itemId,
            ],
            $cleanItemIds,
            array_keys($cleanItemIds)
        )
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    rental_redirect(rental_public_url('equipment.html?rental=error'));
}

rental_redirect(rental_public_url('equipment.html?rental=confirmed&start=' . rawurlencode($startDate) . '&end=' . rawurlencode($endDate)));
