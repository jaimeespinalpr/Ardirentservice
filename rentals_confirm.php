<?php
declare(strict_types=1);
require_once __DIR__ . '/rentals_common.php';
require_once __DIR__ . '/accounts_common.php';
require_once __DIR__ . '/supabase_common.php';

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
$checkoutIntentId = rental_clean_text((string) ($metadata['checkout_intent_id'] ?? ''));
if ($checkoutIntentId !== '') {
    $pdo = rental_db();
    $finalized = rental_finalize_checkout_intent($pdo, $checkoutIntentId, $session);
    if (!($finalized['ok'] ?? false)) {
        rental_redirect(rental_public_url('equipment.html?rental=error'));
    }
    rental_deliver_checkout_intent_emails($pdo, $checkoutIntentId);
    $intent = is_array($finalized['intent'] ?? null) ? $finalized['intent'] : [];
    $confirmedStart = rental_clean_text((string) ($intent['start_date'] ?? ''));
    $confirmedEnd = rental_clean_text((string) ($intent['end_date'] ?? ''));
    rental_redirect(rental_public_url(
        'equipment.html?rental=confirmed&start=' . rawurlencode($confirmedStart) . '&end=' . rawurlencode($confirmedEnd)
    ));
}

// Compatibility path for Stripe sessions created before checkout intents were deployed.
$accountBackend = rental_clean_text((string) ($metadata['account_backend'] ?? ''));
$isSupabase = $accountBackend === 'supabase';

$startDate = rental_validate_date(rental_clean_text($metadata['start_date'] ?? ''));
$endDate = rental_validate_date(rental_clean_text($metadata['end_date'] ?? ''));
$totalAmount = max(0, (int) ($metadata['total_amount_cents'] ?? 0));
$discountCents = max(0, (int) ($isSupabase ? ($metadata['discount_cents'] ?? 0) : ($metadata['welcome_discount_cents'] ?? 0)));
$paidTotal = max(0, $totalAmount - $discountCents);
$currency = rental_clean_text($metadata['currency'] ?? CURRENCY);

$customerDetails = is_array($session['customer_details'] ?? null) ? $session['customer_details'] : [];
if ($isSupabase) {
    $customerName = rental_clean_text((string) ($customerDetails['name'] ?? $metadata['customer_name'] ?? ''));
    $customerEmail = rental_clean_text((string) ($customerDetails['email'] ?? $session['customer_email'] ?? ''));
    $customerPhone = rental_clean_text((string) ($customerDetails['phone'] ?? ''));
} else {
    $customerName = rental_clean_text($metadata['customer_name'] ?? '');
    $customerEmail = rental_clean_text($metadata['customer_email'] ?? '');
    $customerPhone = rental_clean_text($metadata['customer_phone'] ?? '');
}

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
        $paidTotal,
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

    if ($isSupabase) {
        $supabaseUserId = rental_clean_text((string) ($metadata['supabase_user_id'] ?? ''));
        $supabaseReservationToken = rental_clean_text((string) ($metadata['supabase_reservation_token'] ?? ''));
        if ($supabaseUserId !== '' && $supabaseReservationToken !== '') {
            supabase_consume_welcome_discount($supabaseUserId, $supabaseReservationToken);
        }
    } else {
        $accountId = (int) ($metadata['account_id'] ?? 0);
        $discountToken = rental_clean_text($metadata['welcome_discount_token'] ?? '');
        if ($accountId > 0 && $discountCents === WELCOME_DISCOUNT_CENTS && $discountToken !== '') {
            if (!account_consume_discount($pdo, $accountId, $discountToken)) {
                throw new RuntimeException('Welcome discount could not be finalized.');
            }
        }
    }

    $pdo->commit();

    $emailReservation = [
        'id' => $reservationId,
        'customer_name' => $customerName !== '' ? $customerName : 'Unknown',
        'customer_email' => $customerEmail !== '' ? $customerEmail : 'unknown@example.com',
        'customer_phone' => $customerPhone,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'total_amount_cents' => $paidTotal,
        'currency' => $currency !== '' ? $currency : CURRENCY,
    ];
    $emailItems = array_map(
        static fn(string $itemId, int $index): array => [
            'item_id' => $itemId,
            'title' => $cleanTitles[$index] ?? $itemId,
        ],
        $cleanItemIds,
        array_keys($cleanItemIds)
    );
    rental_send_customer_email($emailReservation, $emailItems);
    rental_send_admin_email($emailReservation, $emailItems);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    rental_redirect(rental_public_url('equipment.html?rental=error'));
}

rental_redirect(rental_public_url('equipment.html?rental=confirmed&start=' . rawurlencode($startDate) . '&end=' . rawurlencode($endDate)));
