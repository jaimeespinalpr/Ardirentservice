<?php
declare(strict_types=1);
require_once __DIR__ . '/rentals_common.php';

rental_send_security_headers("default-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

function return_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$input = $method === 'POST' ? $_POST : $_GET;
$reservationId = (int) ($input['reservation_id'] ?? 0);
$action = rental_clean_text($input['action'] ?? '');
$expires = (int) ($input['expires'] ?? 0);
$signature = strtolower(rental_clean_text(
    $method === 'POST' ? ($_POST['signature'] ?? '') : ($_GET['signature'] ?? '')
));

if (
    $reservationId <= 0
    || !in_array($action, ['returned_ok', 'returned_problem'], true)
    || !rental_verify_return_action($reservationId, $action, $expires, $signature)
) {
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Ardi Return Check</title></head><body><h1>Access denied</h1><p>This link is invalid or expired.</p></body></html>';
    exit;
}

$pdo = rental_db();
$reservation = rental_get_reservation($pdo, $reservationId);
if (!is_array($reservation)) {
    http_response_code(404);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Ardi Return Check</title></head><body><h1>Reservation not found</h1></body></html>';
    exit;
}

if ($method === 'GET') {
    $label = $action === 'returned_ok' ? 'Confirmar que todo está bien' : 'Confirmar que hay un problema';
    ?>
    <!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confirmar devolución</title></head>
    <body style="margin:0;background:#f5f2eb;font-family:Arial,sans-serif;color:#1e1a17"><main style="max-width:680px;margin:10vh auto;padding:28px;background:#fffaf2;border:1px solid #d8cec2;border-radius:18px">
      <h1>Confirma esta acción</h1><p>Reservación #<?php echo $reservationId; ?>. Ningún estado cambiará hasta que presiones el botón.</p>
      <form method="post">
        <input type="hidden" name="reservation_id" value="<?php echo $reservationId; ?>">
        <input type="hidden" name="action" value="<?php echo return_h($action); ?>">
        <input type="hidden" name="expires" value="<?php echo $expires; ?>">
        <input type="hidden" name="signature" value="<?php echo return_h($signature); ?>">
        <button type="submit" style="padding:13px 20px;border:0;border-radius:10px;background:#1e1a17;color:#fff;font:inherit;cursor:pointer"><?php echo return_h($label); ?></button>
      </form>
    </main></body></html>
    <?php
    exit;
}

if ($method !== 'POST') {
    rental_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
$title = 'Señal recibida';
$body = 'La reservación fue actualizada.';

if ($action === 'returned_ok') {
    if (empty($reservation['review_requested_at'])) {
        $items = is_array($reservation['items'] ?? null) ? $reservation['items'] : [];
        if (rental_send_google_review_request_email($reservation, $items)) {
            $stmt = $pdo->prepare('UPDATE reservations SET fulfillment_status = ?, return_checked_at = ?, review_requested_at = ? WHERE id = ? AND review_requested_at IS NULL');
            $stmt->execute(['completed', $now, $now, $reservationId]);
            $body = 'Todo fue marcado como correcto y el correo de agradecimiento fue enviado al cliente.';
        } else {
            $stmt = $pdo->prepare('UPDATE reservations SET fulfillment_status = ?, return_checked_at = ? WHERE id = ?');
            $stmt->execute(['completed', $now, $reservationId]);
            $title = 'No se pudo enviar el correo';
            $body = 'La devolución fue marcada como correcta, pero el correo de review no se pudo enviar.';
        }
    } else {
        $stmt = $pdo->prepare('UPDATE reservations SET fulfillment_status = ?, return_checked_at = COALESCE(return_checked_at, ?) WHERE id = ?');
        $stmt->execute(['completed', $now, $reservationId]);
        $body = 'Este cliente ya recibió el correo de review. No se envió un duplicado.';
    }
} else {
    $stmt = $pdo->prepare('UPDATE reservations SET fulfillment_status = ?, return_checked_at = ? WHERE id = ?');
    $stmt->execute(['delivered', $now, $reservationId]);
    $title = 'Problema registrado';
    $body = 'No se envió el correo de review. Dale seguimiento antes de cerrar este alquiler.';
}

$adminUrl = rental_pay_site_url() . '/rentals_admin.php?reservation_id=' . $reservationId;
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ardi Return Check</title></head>
<body style="margin:0;background:#f5f2eb;font-family:Arial,sans-serif;color:#1e1a17"><main style="max-width:720px;margin:0 auto;padding:42px 18px"><section style="background:#fffaf2;border:1px solid #d8cec2;border-radius:22px;padding:28px"><h1><?php echo return_h($title); ?></h1><p><?php echo return_h($body); ?></p><p><strong>Reservación:</strong> #<?php echo $reservationId; ?></p><a href="<?php echo return_h($adminUrl); ?>">Volver al admin</a></section></main></body></html>
