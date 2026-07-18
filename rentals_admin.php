<?php
declare(strict_types=1);
require_once __DIR__ . '/rentals_common.php';

$adminToken = rental_env('RENTAL_ADMIN_TOKEN', '');
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_contains($host, '.local');
$providedToken = rental_clean_text($_GET['token'] ?? $_POST['token'] ?? '');

if ($adminToken !== '') {
    if (!hash_equals($adminToken, $providedToken)) {
        http_response_code(403);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Ardi Admin</title></head><body style="font-family:Arial,sans-serif;padding:32px">'
            . '<h1>Access denied</h1>'
            . '<p>This page is protected. Set <code>RENTAL_ADMIN_TOKEN</code> on the server and pass it as <code>?token=...</code>.</p>'
            . '</body></html>';
        exit;
    }
} elseif (!$isLocalHost) {
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Ardi Admin</title></head><body style="font-family:Arial,sans-serif;padding:32px">'
        . '<h1>Admin token not configured</h1>'
        . '<p>Set <code>RENTAL_ADMIN_TOKEN</code> to protect this page before exposing it publicly.</p>'
        . '</body></html>';
    exit;
}

$pdo = rental_db();
$validStatuses = rental_reservation_statuses();
$message = '';
$messageType = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = rental_clean_text($_POST['action'] ?? '');
    $reservationId = (int) ($_POST['reservation_id'] ?? 0);
    $status = rental_clean_text($_POST['fulfillment_status'] ?? '');

    if ($action === 'update_status' && $reservationId > 0 && in_array($status, $validStatuses, true)) {
        if (rental_update_reservation_fulfillment_status($pdo, $reservationId, $status)) {
            $message = 'Reservation updated.';
            $messageType = 'success';
            if ($status === 'completed') {
                $completedReservation = rental_get_reservation($pdo, $reservationId);
                if (is_array($completedReservation)) {
                    $items = is_array($completedReservation['items'] ?? null) ? $completedReservation['items'] : [];
                    rental_send_return_inspection_email($completedReservation, $items);
                    $message = 'Reservation completed. Return inspection email sent.';
                }
            }
        } else {
            $message = 'Could not update that reservation.';
            $messageType = 'error';
        }
    } else {
        $message = 'Invalid request.';
        $messageType = 'error';
    }
}

$statusFilter = rental_clean_text($_GET['status'] ?? '');
if ($statusFilter !== '' && !in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$previewId = (int) ($_GET['reservation_id'] ?? $_GET['preview_id'] ?? 0);

$sql = 'SELECT * FROM reservations';
$params = [];
if ($statusFilter !== '') {
    $sql .= ' WHERE fulfillment_status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY created_at DESC, id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

$previewReservation = null;
if ($previewId > 0) {
    $previewReservation = rental_get_reservation($pdo, $previewId);
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ardi Rent & Service Admin</title>
  <style>
    :root {
      --bg: #f5f2eb;
      --panel: #fffaf2;
      --ink: #1e1a17;
      --muted: #6a6259;
      --line: rgba(30, 26, 23, 0.12);
      --accent: #9d5c2f;
      --success: #1f7a45;
      --error: #9a2d20;
      --pill: #efe4d7;
      --shadow: 0 16px 40px rgba(45, 28, 16, 0.08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Arial, Helvetica, sans-serif;
      color: var(--ink);
      background:
        radial-gradient(circle at top left, rgba(157, 92, 47, 0.16), transparent 35%),
        linear-gradient(180deg, #faf7f2 0%, #f3ede4 100%);
    }
    .wrap {
      max-width: 1280px;
      margin: 0 auto;
      padding: 28px 18px 56px;
    }
    .hero, .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 22px;
      box-shadow: var(--shadow);
    }
    .hero {
      padding: 24px;
      margin-bottom: 18px;
    }
    .hero h1 {
      margin: 0 0 8px;
      font-size: clamp(28px, 5vw, 44px);
      line-height: 1.04;
    }
    .hero p {
      margin: 0;
      color: var(--muted);
      line-height: 1.55;
    }
    .chips {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 18px;
    }
    .chip, .chip a {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 999px;
      padding: 8px 12px;
      background: var(--pill);
      color: var(--ink);
      text-decoration: none;
      font-size: 14px;
    }
    .layout {
      display: grid;
      grid-template-columns: minmax(0, 1.6fr) minmax(340px, 0.9fr);
      gap: 18px;
      align-items: start;
    }
    .panel {
      padding: 18px;
    }
    .toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin: 0 0 16px;
    }
    .toolbar a {
      text-decoration: none;
      color: var(--ink);
      background: #f2e7db;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid var(--line);
    }
    .message {
      padding: 12px 14px;
      border-radius: 14px;
      margin-bottom: 16px;
      border: 1px solid var(--line);
    }
    .message.success { background: rgba(31, 122, 69, 0.08); color: var(--success); }
    .message.error { background: rgba(154, 45, 32, 0.08); color: var(--error); }
    .table-wrap { overflow-x: auto; }
    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 980px;
    }
    th, td {
      border-bottom: 1px solid var(--line);
      text-align: left;
      vertical-align: top;
      padding: 12px 10px;
      font-size: 14px;
    }
    th {
      color: var(--muted);
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      font-size: 12px;
    }
    .badge {
      display: inline-flex;
      padding: 6px 10px;
      border-radius: 999px;
      background: #efe4d7;
      border: 1px solid var(--line);
      margin: 0 6px 6px 0;
      font-size: 12px;
    }
    .badge.pending { background: rgba(157, 92, 47, 0.12); }
    .badge.success { background: rgba(31, 122, 69, 0.12); }
    .badge.error { background: rgba(154, 45, 32, 0.12); }
    .status-form {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 8px;
    }
    select, button, input[type="text"] {
      font: inherit;
      border-radius: 12px;
      border: 1px solid var(--line);
      padding: 10px 12px;
      background: #fff;
    }
    button {
      background: var(--accent);
      color: white;
      border-color: transparent;
      cursor: pointer;
    }
    .preview iframe {
      width: 100%;
      min-height: 760px;
      border: 1px solid var(--line);
      border-radius: 16px;
      background: white;
    }
    pre {
      white-space: pre-wrap;
      word-break: break-word;
      background: #101010;
      color: #f4f4f4;
      border-radius: 16px;
      padding: 16px;
      overflow: auto;
    }
    .muted { color: var(--muted); }
    .grid-2 {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 12px;
    }
    .stat {
      padding: 14px;
      border-radius: 16px;
      background: #f7efe6;
      border: 1px solid var(--line);
    }
    .stat strong { display: block; font-size: 22px; margin-bottom: 4px; }
    @media (max-width: 960px) {
      .layout { grid-template-columns: 1fr; }
      table { min-width: 760px; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <section class="hero">
      <h1>Ardi Rent & Service Admin</h1>
      <p>View reservations, see what is still pending, and preview the confirmation email exactly as it will be generated.</p>
      <div class="chips">
        <span class="chip">Database: SQLite</span>
        <span class="chip">Pending fulfillment: <strong>reservation status = pending</strong></span>
        <span class="chip">Email source: <strong>rentals_common.php</strong></span>
      </div>
    </section>

    <div class="layout">
      <section class="panel">
        <div class="toolbar">
          <a href="?<?php echo $providedToken !== '' ? 'token=' . rawurlencode($providedToken) . '&' : ''; ?>status=">All</a>
          <?php foreach ($validStatuses as $status): ?>
            <a href="?<?php echo $providedToken !== '' ? 'token=' . rawurlencode($providedToken) . '&' : ''; ?>status=<?php echo rawurlencode($status); ?>"><?php echo h(ucfirst($status)); ?></a>
          <?php endforeach; ?>
        </div>

        <?php if ($message !== ''): ?>
          <div class="message <?php echo h($messageType); ?>"><?php echo h($message); ?></div>
        <?php endif; ?>

        <div class="grid-2">
          <div class="stat">
            <strong><?php echo count($reservations); ?></strong>
            <span class="muted">Reservations shown</span>
          </div>
          <div class="stat">
            <strong><?php echo count(array_values(array_filter($reservations, static fn(array $row): bool => (string) ($row['fulfillment_status'] ?? '') === 'pending'))); ?></strong>
            <span class="muted">Pending to fulfill</span>
          </div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Dates</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Statuses</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($reservations === []): ?>
                <tr>
                  <td colspan="6" class="muted">No reservations yet.</td>
                </tr>
              <?php endif; ?>
              <?php foreach ($reservations as $reservation): ?>
                <?php
                  $itemsStmt = $pdo->prepare(
                      'SELECT item_id, item_title, unit_amount_cents
                       FROM reservation_items
                       WHERE reservation_id = ?
                       ORDER BY id ASC'
                  );
                  $itemsStmt->execute([(int) $reservation['id']]);
                  $items = $itemsStmt->fetchAll();
                ?>
                <tr>
                  <td>#<?php echo (int) $reservation['id']; ?></td>
                  <td>
                    <?php echo h((string) $reservation['start_date']); ?> to <?php echo h((string) $reservation['end_date']); ?><br>
                    <span class="muted"><?php echo h((string) $reservation['created_at']); ?></span>
                  </td>
                  <td>
                    <strong><?php echo h((string) $reservation['customer_name']); ?></strong><br>
                    <?php echo h((string) $reservation['customer_email']); ?><br>
                    <?php echo h((string) ($reservation['customer_phone'] ?? '')); ?>
                  </td>
                  <td>
                    <?php foreach ($items as $item): ?>
                      <span class="badge"><?php echo h((string) $item['item_title']); ?></span>
                    <?php endforeach; ?>
                  </td>
                  <td>
                    <span class="badge success">Paid: <?php echo h((string) $reservation['status']); ?></span>
                    <span class="badge <?php echo (string) $reservation['fulfillment_status'] === 'pending' ? 'pending' : ''; ?>">Fulfillment: <?php echo h((string) $reservation['fulfillment_status']); ?></span>
                    <?php if (!empty($reservation['return_checked_at'])): ?>
                      <span class="badge success">Return checked</span>
                    <?php endif; ?>
                    <?php if (!empty($reservation['review_requested_at'])): ?>
                      <span class="badge success">Review requested</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a href="?<?php echo $providedToken !== '' ? 'token=' . rawurlencode($providedToken) . '&' : ''; ?>reservation_id=<?php echo (int) $reservation['id']; ?>">Preview email</a>
                    <form method="post" class="status-form">
                      <input type="hidden" name="token" value="<?php echo h($providedToken); ?>">
                      <input type="hidden" name="action" value="update_status">
                      <input type="hidden" name="reservation_id" value="<?php echo (int) $reservation['id']; ?>">
                      <select name="fulfillment_status">
                        <?php foreach ($validStatuses as $option): ?>
                          <option value="<?php echo h($option); ?>" <?php echo ((string) $reservation['fulfillment_status'] === $option) ? 'selected' : ''; ?>>
                            <?php echo h(ucfirst($option)); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit">Update</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <aside class="panel preview">
        <?php if (is_array($previewReservation)): ?>
          <?php
            $messageData = rental_build_customer_email_message(
                [
                    'customer_name' => (string) $previewReservation['customer_name'],
                    'customer_email' => (string) $previewReservation['customer_email'],
                    'start_date' => (string) $previewReservation['start_date'],
                    'end_date' => (string) $previewReservation['end_date'],
                    'total_amount_cents' => (int) $previewReservation['total_amount_cents'],
                    'currency' => (string) $previewReservation['currency'],
                ],
                array_map(
                    static fn(array $item): array => [
                        'title' => (string) $item['item_title'],
                    ],
                    is_array($previewReservation['items'] ?? null) ? $previewReservation['items'] : []
                )
            );
          ?>
          <h2>Email preview for #<?php echo (int) $previewReservation['id']; ?></h2>
          <p class="muted">This is the exact email body generated by the rental confirmation flow.</p>
          <p><span class="badge pending">Preview</span> <span class="badge">To: <?php echo h((string) $previewReservation['customer_email']); ?></span></p>
          <iframe srcdoc="<?php echo h((string) $messageData['html']); ?>"></iframe>
          <h3>Plain text</h3>
          <pre><?php echo h((string) $messageData['plain']); ?></pre>
        <?php else: ?>
          <h2>Select a reservation</h2>
          <p class="muted">Click “Preview email” on any reservation to render the message here.</p>
        <?php endif; ?>
      </aside>
    </div>
  </div>
</body>
</html>
