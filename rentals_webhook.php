<?php
declare(strict_types=1);
require_once __DIR__ . '/rentals_common.php';
require_once __DIR__ . '/accounts_common.php';
require_once __DIR__ . '/supabase_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rental_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$webhookSecret = rental_env('STRIPE_WEBHOOK_SECRET');
$signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? $_SERVER['Stripe-Signature'] ?? '');
$payload = file_get_contents('php://input');
if (!is_string($payload) || !rental_verify_stripe_webhook_signature($payload, $signature, $webhookSecret)) {
    rental_json(['ok' => false, 'error' => 'invalid_signature'], 400);
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    rental_json(['ok' => false, 'error' => 'invalid_payload'], 400);
}

$eventId = rental_clean_text((string) ($event['id'] ?? ''));
$eventType = rental_clean_text((string) ($event['type'] ?? ''));
$session = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
$metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
$intentId = rental_clean_text((string) ($metadata['checkout_intent_id'] ?? ''));
if ($eventId === '' || $eventType === '' || $intentId === '') {
    rental_json(['ok' => false, 'error' => 'missing_event_context'], 400);
}

$pdo = rental_db();
$processed = false;
try {
    if ($eventType === 'checkout.session.completed') {
        $result = rental_finalize_checkout_intent($pdo, $intentId, $session);
        if (!($result['ok'] ?? false)) {
            rental_json(['ok' => false, 'error' => $result['error'] ?? 'finalization_failed'], 500);
        }
        if (!rental_deliver_checkout_intent_emails($pdo, $intentId)) {
            rental_json(['ok' => false, 'error' => 'email_delivery_failed'], 500);
        }
        $processed = true;
    } elseif ($eventType === 'checkout.session.expired') {
        $result = rental_release_checkout_intent($pdo, $intentId, 'checkout_session_expired');
        if (!($result['ok'] ?? false)) {
            rental_json(['ok' => false, 'error' => $result['error'] ?? 'release_failed'], 500);
        }
        $processed = true;
    } else {
        $processed = true;
    }

    if ($processed) {
        rental_record_checkout_webhook_event($pdo, $eventId, $eventType, $intentId);
    }
} catch (Throwable $e) {
    // Do not include webhook payloads, signatures, tokens, or customer data in logs.
    error_log('Stripe webhook processing failed for event type ' . $eventType);
    rental_json(['ok' => false, 'error' => 'webhook_processing_failed'], 500);
}

rental_json(['ok' => true]);
