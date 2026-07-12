<?php
declare(strict_types=1);
require_once __DIR__ . '/rentals_common.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = rental_db();
$reservation = $pdo->query('SELECT * FROM reservations ORDER BY created_at DESC, id DESC LIMIT 1')->fetch();
if (!is_array($reservation)) {
    fwrite(STDERR, "No reservation found.\n");
    exit(2);
}

$createdAt = new DateTimeImmutable((string) $reservation['created_at']);
if ($createdAt < new DateTimeImmutable('-72 hours')) {
    fwrite(STDERR, "Latest reservation is older than 72 hours; refusing to resend.\n");
    exit(3);
}

$stmt = $pdo->prepare('SELECT * FROM reservation_items WHERE reservation_id = ? ORDER BY id ASC');
$stmt->execute([(int) $reservation['id']]);
$items = $stmt->fetchAll();
$customerSent = rental_send_customer_email($reservation, $items);
$adminSent = rental_send_admin_email($reservation, $items);

printf(
    "reservation_id=%d customer_sent=%s admin_sent=%s admin_recipient=%s\n",
    (int) $reservation['id'],
    $customerSent ? 'yes' : 'no',
    $adminSent ? 'yes' : 'no',
    rental_admin_email()
);
exit($customerSent && $adminSent ? 0 : 1);
