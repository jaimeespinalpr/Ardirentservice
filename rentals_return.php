<?php
declare(strict_types=1);
require_once __DIR__ . '/rentals_common.php';

$adminToken = rental_env('RENTAL_ADMIN_TOKEN', '');
$providedToken = rental_clean_text($_GET['token'] ?? $_POST['token'] ?? '');
if ($adminToken === '' || !hash_equals($adminToken, $providedToken)) {
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Ardi Return Check</title></head><body style="font-family:Arial,sans-serif;padding:32px">'
        . '<h1>Access denied</h1><p>This return confirmation link is protected.</p></body></html>';
    exit;
}

$reservationId = (int) ($_GET['reservation_id'] ?? $_POST['reservation_id'] ?? 0);
$action = rental_clean_text($_GET['action'] ?? $_POST['action'] ?? '');
if ($reservationId <= 0 || !in_array($action, ['returned_ok', 'returned_problem'], true)) {
    http_response_code(422);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Ardi Return Check</title></head><body style="font-family:Arial,sans-serif;padding:32px">'
        . '<h1>Invalid request</h1><p>The return confirmation link is incomplete.</p></body></html>';
    exit;
}

$pdo = rental_db();
$reservation = rental_get_reservation($pdo, $reservationId);
if (!is_array($reservation)) {
    http_response_code(404);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Ardi Return Check</title></head><body style="font-family:Arial,sans-serif;padding:32px">'
        . '<h1>Reservation not found</h1><p>No reservation matched this return confirmation link.</p></body></html>';
    exit;
}

$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
$title = 'Return check saved';
$body = 'The reservation was updated.';
$sentReview = false;

if ($action === 'returned_ok') {
    if (empty($reservation['review_requested_at'])) {
        $items = is_array($reservation['items'] ?? null) ? $reservation['items'] : [];
        $sentReview = rental_send_google_review_request_email($reservation, $items);
        if ($sentReview) {
            $stmt = $pdo->prepare('UPDATE reservations SET fulfillment_status = ?, return_checked_at = ?, review_requested_at = ? WHERE id = ?');
            $stmt->execute(['completed', $now, $now, $reservationId]);
            $body = 'Everything was marked OK and the Google Review request was sent to the customer.';
        } else {
            $stmt = $pdo->prepare('UPDATE reservations SET fulfillment_status = ?, return_checked_at = ? WHERE id = ?');
            $stmt->execute(['completed', $now, $reservationId]);
            $title = 'Review email failed';
            $body = 'The return was marked OK, but the review request email could not be sent. Check SMTP settings.';
        }
    } else {
        $stmt = $pdo->prepare('UPDATE reservations SET fulfillment_status = ?, return_checked_at = COALESCE(return_checked_at, ?) WHERE id = ?');
        $stmt->execute(['completed', $now, $reservationId]);
        $body = 'This customer already received the Google Review request. No duplicate email was sent.';
    }
} else {
    $stmt = $pdo->prepare('UPDATE reservations SET fulfillment_status = ?, return_checked_at = ? WHERE id = ?');
    $stmt->execute(['delivered', $now, $reservationId]);
    $title = 'Return marked with a problem';
    $body = 'The customer was not contacted for a Google Review. Follow up with the customer before closing this rental.';
}

$adminUrl = rental_pay_site_url() . '/rentals_admin.php?' . http_build_query([
    'token' => $providedToken,
    'reservation_id' => $reservationId,
]);

function return_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ardi Return Check</title>
</head>
<body style="margin:0;background:#f5f2eb;font-family:Arial,Helvetica,sans-serif;color:#1e1a17">
  <main style="max-width:720px;margin:0 auto;padding:42px 18px">
    <section style="background:#fffaf2;border:1px solid rgba(30,26,23,.12);border-radius:22px;padding:28px;box-shadow:0 16px 40px rgba(45,28,16,.08)">
      <p style="margin:0 0 8px;color:#9d5c2f;font-weight:bold;letter-spacing:.08em;text-transform:uppercase">Ardi Rent & Service</p>
      <h1 style="margin:0 0 12px;font-size:36px;line-height:1.08"><?php echo return_h($title); ?></h1>
      <p style="margin:0 0 18px;color:#6a6259;font-size:17px;line-height:1.6"><?php echo return_h($body); ?></p>
      <p style="margin:0 0 24px"><strong>Reservation:</strong> #<?php echo (int) $reservationId; ?></p>
      <a href="<?php echo return_h($adminUrl); ?>" style="display:inline-block;background:#1e1a17;color:#fff;text-decoration:none;padding:13px 20px;border-radius:999px;font-weight:bold">Back to admin</a>
    </section>
  </main>
</body>
</html>
