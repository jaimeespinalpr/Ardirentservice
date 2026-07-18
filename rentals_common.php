<?php
declare(strict_types=1);

// Load .env from same directory (pay/) so STRIPE_SECRET_KEY and STRIPE_MODE
// are available via getenv() without being committed to the repo.
(static function (): void {
    $envFile = __DIR__ . '/.env';
    if (!is_file($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
})();

const DAILY_RATE_CENTS = 5000;
const CURRENCY = 'usd';

function rental_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
}

function rental_public_site_url(): string
{
    return rtrim(rental_env('PUBLIC_SITE_URL', 'https://www.ardirentservice.com'), '/');
}

function rental_public_url(string $path = ''): string
{
    $normalized = ltrim($path, '/');
    return rental_public_site_url() . ($normalized === '' ? '' : '/' . $normalized);
}

function rental_format_money(int $amountCents, string $currency = CURRENCY): string
{
    $symbol = strtolower($currency) === 'usd' ? '$' : strtoupper($currency) . ' ';
    return $symbol . number_format($amountCents / 100, 2);
}

function rental_email_from(): string
{
    return rental_env('RENTAL_EMAIL_FROM', 'Ardi Rent & Service <noreply@ardirentservice.com>');
}

function rental_email_reply_to(): string
{
    return rental_env('RENTAL_EMAIL_REPLY_TO', 'info@ardirentservice.com');
}

function rental_admin_email(): string
{
    return rental_env('RENTAL_ADMIN_EMAIL', 'ardirentservice@gmail.com');
}

function rental_admin_emails(): array
{
    $configured = rental_env(
        'RENTAL_ADMIN_EMAILS',
        rental_admin_email() . ',jaimeespinalpr@gmail.com'
    );
    $emails = array_filter(array_map(
        static fn(string $email): string => filter_var(trim($email), FILTER_VALIDATE_EMAIL) ?: '',
        explode(',', $configured)
    ));
    return array_values(array_unique($emails));
}

function rental_return_review_emails(): array
{
    $configured = rental_env('RENTAL_RETURN_REVIEW_EMAILS', implode(',', rental_admin_emails()));
    $emails = array_filter(array_map(
        static fn(string $email): string => filter_var(trim($email), FILTER_VALIDATE_EMAIL) ?: '',
        explode(',', $configured)
    ));
    return array_values(array_unique($emails));
}

function rental_google_review_url(): string
{
    return rental_env('GOOGLE_REVIEW_URL', 'https://g.page/r/CUSTOMER_REVIEW_LINK/review');
}

function rental_pay_site_url(): string
{
    return rtrim(rental_env('PAY_SITE_URL', rental_base_url()), '/');
}

function rental_admin_action_url(int $reservationId, string $action): string
{
    $token = rental_env('RENTAL_ADMIN_TOKEN', '');
    $query = http_build_query([
        'reservation_id' => $reservationId,
        'action' => $action,
        'token' => $token,
    ]);
    return rental_pay_site_url() . '/rentals_return.php?' . $query;
}

function rental_pickup_details_html(): string
{
    $address = rental_env('RENTAL_PICKUP_ADDRESS', 'Park Boulevard condominium');
    $hours = rental_env('RENTAL_PICKUP_HOURS', 'Same day, the afternoon before, or by 1:00 p.m. the next day when the rental ends very late.');
    $contact = rental_env('RENTAL_PICKUP_CONTACT', 'Reply to this email or contact Ardi Rent & Service by WhatsApp.');
    $notes = rental_env(
        'RENTAL_PICKUP_NOTES',
        'Deliveries and pickups are handled at Park Boulevard. Bring a valid ID and your order confirmation.'
    );

    return '<ul style="padding-left:20px;margin:10px 0 0;color:#202124;line-height:1.55">'
        . '<li><strong>Delivery / pickup location:</strong> ' . htmlspecialchars($address, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
        . '<li><strong>Timing:</strong> ' . htmlspecialchars($hours, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
        . '<li><strong>Contact:</strong> ' . htmlspecialchars($contact, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
        . '<li><strong>Notes:</strong> ' . htmlspecialchars($notes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
        . '</ul>';
}

function rental_delivery_policy_html(): string
{
    return '<div style="background:#f7f7f7;border:1px solid #e5e5e5;border-radius:14px;padding:16px;margin:18px 0">'
        . '<h2 style="font-size:18px;margin:0 0 8px">Delivery and pickup system</h2>'
        . '<p style="font-size:15px;line-height:1.55;margin:0 0 10px">Ardi Rent & Service handles equipment delivery and pickup at the <strong>Park Boulevard</strong> condominium.</p>'
        . '<ul style="padding-left:20px;margin:0;color:#202124;line-height:1.55">'
        . '<li><strong>Delivery:</strong> You can receive the equipment the same day you will use it or, if preferred, the afternoon before.</li>'
        . '<li><strong>Pickup:</strong> If you finish using the equipment the same day, we can pick it up that same day. If you finish very late, you have until 1:00 p.m. the next day to return it.</li>'
        . '</ul>'
        . '</div>';
}

function rental_delivery_policy_plain(): string
{
    return "Delivery and pickup system:\n"
        . "Ardi Rent & Service handles equipment delivery and pickup at the Park Boulevard condominium.\n"
        . "Delivery: You can receive the equipment the same day you will use it or, if preferred, the afternoon before.\n"
        . "Pickup: If you finish using the equipment the same day, we can pick it up that same day. If you finish very late, you have until 1:00 p.m. the next day to return it.\n";
}

function rental_send_customer_email(array $reservation, array $items): bool
{
    $email = filter_var((string) ($reservation['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        error_log('Ardi rental email skipped: invalid customer email');
        return false;
    }

    $message = rental_build_customer_email_message($reservation, $items);
    if (rental_smtp_configured()) {
        $sent = rental_send_smtp_message($email, $message);
        if (!$sent) {
            error_log('Ardi rental SMTP email failed for ' . $email);
        }
        return $sent;
    }

    $sent = mail($email, $message['subject'], $message['body'], implode("\r\n", $message['headers']));
    if (!$sent) {
        error_log('Ardi rental email failed for ' . $email);
    }
    return $sent;
}

function rental_send_admin_email(array $reservation, array $items): bool
{
    $emails = rental_admin_emails();
    if ($emails === []) {
        error_log('Ardi rental admin email skipped: no valid admin emails');
        return false;
    }

    $message = rental_build_admin_email_message($reservation, $items);
    $allSent = true;
    foreach ($emails as $email) {
        $sent = rental_smtp_configured()
            ? rental_send_smtp_message($email, $message)
            : mail($email, $message['subject'], $message['body'], implode("\r\n", $message['headers']));
        if (!$sent) {
            error_log('Ardi rental admin email failed for ' . $email);
            $allSent = false;
        }
    }
    return $allSent;
}

function rental_send_return_inspection_email(array $reservation, array $items): bool
{
    $emails = rental_return_review_emails();
    if ($emails === []) {
        error_log('Ardi rental return inspection email skipped: no valid recipients');
        return false;
    }

    $message = rental_build_return_inspection_email_message($reservation, $items);
    $allSent = true;
    foreach ($emails as $email) {
        $sent = rental_smtp_configured()
            ? rental_send_smtp_message($email, $message)
            : mail($email, $message['subject'], $message['body'], implode("\r\n", $message['headers']));
        if (!$sent) {
            error_log('Ardi rental return inspection email failed for ' . $email);
            $allSent = false;
        }
    }
    return $allSent;
}

function rental_send_google_review_request_email(array $reservation, array $items): bool
{
    $email = filter_var((string) ($reservation['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        error_log('Ardi review request skipped: invalid customer email');
        return false;
    }

    $message = rental_build_google_review_request_email_message($reservation, $items);
    $sent = rental_smtp_configured()
        ? rental_send_smtp_message($email, $message)
        : mail($email, $message['subject'], $message['body'], implode("\r\n", $message['headers']));
    if (!$sent) {
        error_log('Ardi review request email failed for ' . $email);
    }
    return $sent;
}

function rental_email_date(string $date): string
{
    if ($date === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($date))->format('M j, Y');
    } catch (Throwable) {
        return rental_clean_text($date);
    }
}

function rental_finalize_email_message(string $subject, string $html, string $plain, string $replyTo): array
{
    $boundary = 'ardi_' . bin2hex(random_bytes(12));
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . rental_email_from(),
        'Reply-To: ' . $replyTo,
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
        . $plain . "\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
        . $html . "\r\n"
        . '--' . $boundary . "--\r\n";

    return [
        'subject' => $subject,
        'headers' => $headers,
        'body' => $body,
        'html' => $html,
        'plain' => $plain,
    ];
}

function rental_build_branded_email_html(
    array $reservation,
    array $items,
    string $eyebrow,
    string $headline,
    string $intro,
    bool $admin = false
): string {
    $reservationId = (int) ($reservation['id'] ?? 0);
    $confirmation = $reservationId > 0 ? '#' . $reservationId : 'Confirmed';
    $name = rental_clean_text($reservation['customer_name'] ?? '');
    $email = rental_clean_text($reservation['customer_email'] ?? '');
    $phone = rental_clean_text($reservation['customer_phone'] ?? '');
    $startDate = rental_email_date((string) ($reservation['start_date'] ?? ''));
    $endDate = rental_email_date((string) ($reservation['end_date'] ?? ''));
    $total = rental_format_money(
        (int) ($reservation['total_amount_cents'] ?? 0),
        (string) ($reservation['currency'] ?? CURRENCY)
    );
    $logoUrl = rental_public_url('assets/logos/logo-black-square.png');
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $itemRows = '';
    foreach ($items as $item) {
        $title = rental_clean_text($item['title'] ?? $item['item_title'] ?? '');
        if ($title === '') {
            continue;
        }
        $itemRows .= '<tr><td style="border-top:1px solid #e5e5e5;padding:14px 0;font-size:15px">'
            . $e($title)
            . '</td><td align="right" style="border-top:1px solid #e5e5e5;padding:14px 0;color:#666;font-size:13px">Reserved</td></tr>';
    }
    if ($itemRows === '') {
        $itemRows = '<tr><td style="border-top:1px solid #e5e5e5;padding:14px 0;font-size:15px">Rental equipment</td>'
            . '<td align="right" style="border-top:1px solid #e5e5e5;padding:14px 0;color:#666;font-size:13px">Reserved</td></tr>';
    }

    $customerDetails = '';
    if ($admin) {
        $customerDetails = '<tr><td style="padding:8px 20px;color:#686868;font-size:14px">Customer</td>'
            . '<td align="right" style="padding:8px 20px;font-size:14px;font-weight:bold">' . $e($name !== '' ? $name : 'Not provided') . '</td></tr>'
            . '<tr><td style="padding:8px 20px;color:#686868;font-size:14px">Email</td>'
            . '<td align="right" style="padding:8px 20px;font-size:14px;font-weight:bold">' . $e($email !== '' ? $email : 'Not provided') . '</td></tr>'
            . '<tr><td style="padding:8px 20px;color:#686868;font-size:14px">Phone</td>'
            . '<td align="right" style="padding:8px 20px;font-size:14px;font-weight:bold">' . $e($phone !== '' ? $phone : 'Not provided') . '</td></tr>';
    }

    return '<!doctype html><html><body style="margin:0;background:#ededed;font-family:Arial,Helvetica,sans-serif;color:#111">'
        . '<div style="display:none;max-height:0;overflow:hidden;color:transparent">' . $e($intro) . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#ededed"><tr>'
        . '<td align="center" style="padding:32px 12px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#fff;border-radius:22px;overflow:hidden">'
        . '<tr><td align="center" style="background:#050505;padding:30px 24px 28px">'
        . '<img src="' . $e($logoUrl) . '" width="92" alt="Ardi Rent &amp; Service" style="display:block;width:92px;max-width:92px;background:#fff;border-radius:18px;padding:7px">'
        . '<p style="margin:18px 0 0;color:#cfcfcf;font-size:11px;letter-spacing:2.4px;text-transform:uppercase">' . $e($eyebrow) . '</p></td></tr>'
        . '<tr><td style="padding:34px 34px 10px">'
        . '<p style="margin:0;color:#151515;font-size:13px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase">&#10003; Rental confirmed</p>'
        . '<h1 style="margin:24px 0 12px;font-size:31px;line-height:1.15;letter-spacing:-.7px">' . $e($headline) . '</h1>'
        . '<p style="margin:0;color:#4e4e4e;font-size:16px;line-height:1.65">' . $e($intro) . '</p></td></tr>'
        . '<tr><td style="padding:18px 34px 0"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f4f4;border:1px solid #dedede;border-radius:16px">'
        . '<tr><td colspan="2" style="padding:18px 20px 10px;font-size:12px;color:#666;letter-spacing:1.2px;text-transform:uppercase">Reservation summary</td></tr>'
        . $customerDetails
        . '<tr><td style="padding:8px 20px;color:#686868;font-size:14px">Confirmation</td><td align="right" style="padding:8px 20px;font-size:14px;font-weight:bold">' . $e($confirmation) . '</td></tr>'
        . '<tr><td style="padding:8px 20px;color:#686868;font-size:14px">Rental dates</td><td align="right" style="padding:8px 20px;font-size:14px;font-weight:bold">' . $e($startDate . ' – ' . $endDate) . '</td></tr>'
        . '<tr><td style="padding:8px 20px 20px;color:#686868;font-size:14px">Total paid</td><td align="right" style="padding:8px 20px 20px;font-size:18px;font-weight:bold">' . $e($total) . '</td></tr>'
        . '</table></td></tr>'
        . '<tr><td style="padding:30px 34px 0"><h2 style="margin:0 0 14px;font-size:18px">Equipment reserved</h2>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $itemRows . '</table></td></tr>'
        . '<tr><td style="padding:30px 34px 0"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#0b0b0b;border-radius:16px;color:#fff">'
        . '<tr><td style="padding:22px 22px 8px;font-size:18px;font-weight:bold">Pickup &amp; return</td></tr>'
        . '<tr><td style="padding:0 22px 22px;color:#d0d0d0;font-size:14px;line-height:1.65">'
        . '<strong style="color:#fff">Location:</strong> ' . $e(rental_env('RENTAL_PICKUP_ADDRESS', 'Park Boulevard condominium')) . '<br>'
        . '<strong style="color:#fff">Bring:</strong> A valid ID and this confirmation<br>'
        . '<strong style="color:#fff">Need help?</strong> Reply to this email or contact Ardi Rent &amp; Service by WhatsApp.</td></tr></table></td></tr>'
        . '<tr><td align="center" style="padding:30px 34px 34px">'
        . '<a href="' . $e(rental_public_site_url()) . '" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:14px 25px;border-radius:999px;font-size:14px;font-weight:bold">Visit Ardi Rent &amp; Service</a>'
        . '<p style="margin:26px 0 6px;color:#222;font-size:14px;font-weight:bold">Capture the moment. We’ll handle the gear.</p>'
        . '<p style="margin:0;color:#777;font-size:12px;line-height:1.55">Ardi Rent &amp; Service · Puerto Rico<br>'
        . ($admin ? 'Administrative rental notification.' : 'This confirmation was sent because a rental was completed using this email.')
        . '</p></td></tr></table></td></tr></table></body></html>';
}

function rental_build_admin_email_message(array $reservation, array $items): array
{
    $reservationId = (int) ($reservation['id'] ?? 0);
    $name = rental_clean_text($reservation['customer_name'] ?? 'Customer');
    $customerEmail = rental_clean_text($reservation['customer_email'] ?? '');
    $phone = rental_clean_text($reservation['customer_phone'] ?? '');
    $startDate = rental_email_date((string) ($reservation['start_date'] ?? ''));
    $endDate = rental_email_date((string) ($reservation['end_date'] ?? ''));
    $total = rental_format_money((int) ($reservation['total_amount_cents'] ?? 0), (string) ($reservation['currency'] ?? CURRENCY));
    $titles = array_values(array_filter(array_map(
        static fn(array $item): string => rental_clean_text($item['title'] ?? $item['item_title'] ?? ''),
        $items
    )));
    $subject = 'New paid rental' . ($reservationId > 0 ? ' #' . $reservationId : '') . ' - Ardi Rent & Service';
    $headline = 'New rental from ' . ($name !== '' ? $name : 'a customer');
    $intro = 'A paid equipment rental has been confirmed. Everything you need to prepare the order is included below.';
    $html = rental_build_branded_email_html($reservation, $items, 'New paid rental', $headline, $intro, true);
    $plain = "New paid equipment rental" . ($reservationId > 0 ? " #{$reservationId}" : '') . "\n\n"
        . "Customer: {$name}\nEmail: {$customerEmail}\nPhone: " . ($phone !== '' ? $phone : 'Not provided') . "\n"
        . "Dates: {$startDate} to {$endDate}\nEquipment: " . ($titles !== [] ? implode(', ', $titles) : 'Rental equipment') . "\n"
        . "Total paid: {$total}\n";

    return rental_finalize_email_message(
        $subject,
        $html,
        $plain,
        $customerEmail !== '' ? $customerEmail : rental_email_reply_to()
    );
}

function rental_build_customer_email_message(array $reservation, array $items): array
{
    $customerName = rental_clean_text($reservation['customer_name'] ?? '');
    $startDate = rental_email_date((string) ($reservation['start_date'] ?? ''));
    $endDate = rental_email_date((string) ($reservation['end_date'] ?? ''));
    $total = rental_format_money((int) ($reservation['total_amount_cents'] ?? 0), (string) ($reservation['currency'] ?? CURRENCY));
    $plainItems = implode(', ', array_filter(array_map(
        static fn(array $item): string => rental_clean_text($item['title'] ?? $item['item_title'] ?? ''),
        $items
    )));
    $subject = 'Your rental is confirmed - Ardi Rent & Service';
    $headline = 'Thank you' . ($customerName !== '' ? ', ' . $customerName : '') . '!';
    $intro = 'Your gear is reserved. Now focus on creating something great—we’ll make sure your equipment is ready.';
    $html = rental_build_branded_email_html($reservation, $items, 'Equipment rental confirmation', $headline, $intro);
    $plain = "Thank you for renting with Ardi Rent & Service.\n\n"
        . "Your equipment is reserved.\n"
        . "Rental dates: {$startDate} to {$endDate}\n"
        . "Items: " . ($plainItems !== '' ? $plainItems : 'Rental equipment') . "\n"
        . "Total paid: {$total}\n\n"
        . rental_delivery_policy_plain()
        . "\nReply to this email if you have questions.\n";

    return rental_finalize_email_message($subject, $html, $plain, rental_email_reply_to());
}

function rental_build_return_inspection_email_message(array $reservation, array $items): array
{
    $reservationId = (int) ($reservation['id'] ?? 0);
    $name = rental_clean_text($reservation['customer_name'] ?? 'Customer');
    $customerEmail = rental_clean_text($reservation['customer_email'] ?? '');
    $phone = rental_clean_text($reservation['customer_phone'] ?? '');
    $startDate = rental_email_date((string) ($reservation['start_date'] ?? ''));
    $endDate = rental_email_date((string) ($reservation['end_date'] ?? ''));
    $titles = array_values(array_filter(array_map(
        static fn(array $item): string => rental_clean_text($item['title'] ?? $item['item_title'] ?? ''),
        $items
    )));
    $goodUrl = $reservationId > 0 ? rental_admin_action_url($reservationId, 'returned_ok') : '';
    $problemUrl = $reservationId > 0 ? rental_admin_action_url($reservationId, 'returned_problem') : '';
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $itemList = '<ul style="padding-left:20px;margin:10px 0;color:#202124;line-height:1.6">';
    foreach ($titles !== [] ? $titles : ['Rental equipment'] as $title) {
        $itemList .= '<li>' . $e($title) . '</li>';
    }
    $itemList .= '</ul>';

    $subject = 'Check returned rental' . ($reservationId > 0 ? ' #' . $reservationId : '') . ' - Ardi Rent & Service';
    $html = '<!doctype html><html><body style="margin:0;background:#ededed;font-family:Arial,Helvetica,sans-serif;color:#111">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#ededed"><tr><td align="center" style="padding:32px 12px">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#fff;border-radius:22px;overflow:hidden">'
        . '<tr><td style="background:#050505;color:#fff;padding:28px 30px"><p style="margin:0;color:#cfcfcf;font-size:12px;letter-spacing:2px;text-transform:uppercase">Returned equipment check</p>'
        . '<h1 style="margin:14px 0 0;font-size:30px;line-height:1.15">Please confirm the returned gear</h1></td></tr>'
        . '<tr><td style="padding:30px"><p style="margin:0 0 16px;color:#444;font-size:16px;line-height:1.6">The rental has been marked completed. Check that all items were returned and that everything is in good condition. If everything is OK, click the button and the customer will automatically receive the Google Review request.</p>'
        . '<div style="background:#f4f4f4;border:1px solid #dedede;border-radius:16px;padding:18px;margin:0 0 18px">'
        . '<p style="margin:0 0 8px"><strong>Reservation:</strong> #' . $e((string) $reservationId) . '</p>'
        . '<p style="margin:0 0 8px"><strong>Customer:</strong> ' . $e($name) . '</p>'
        . '<p style="margin:0 0 8px"><strong>Email:</strong> ' . $e($customerEmail !== '' ? $customerEmail : 'Not provided') . '</p>'
        . '<p style="margin:0 0 8px"><strong>Phone:</strong> ' . $e($phone !== '' ? $phone : 'Not provided') . '</p>'
        . '<p style="margin:0"><strong>Rental dates:</strong> ' . $e($startDate . ' to ' . $endDate) . '</p>'
        . $itemList
        . '</div>'
        . '<p style="margin:26px 0 0">'
        . '<a href="' . $e($goodUrl) . '" style="display:inline-block;background:#1f7a45;color:#fff;text-decoration:none;padding:14px 22px;border-radius:999px;font-weight:bold;margin:0 8px 10px 0">Everything is OK - send review request</a>'
        . '<a href="' . $e($problemUrl) . '" style="display:inline-block;background:#9a2d20;color:#fff;text-decoration:none;padding:14px 22px;border-radius:999px;font-weight:bold;margin:0 0 10px">There is a problem</a>'
        . '</p>'
        . '<p style="margin:20px 0 0;color:#777;font-size:12px;line-height:1.5">Only use the green button after confirming the equipment is complete and in good condition.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
    $plain = "Returned rental check" . ($reservationId > 0 ? " #{$reservationId}" : '') . "\n\n"
        . "Customer: {$name}\nEmail: {$customerEmail}\nPhone: " . ($phone !== '' ? $phone : 'Not provided') . "\n"
        . "Dates: {$startDate} to {$endDate}\nItems: " . implode(', ', $titles !== [] ? $titles : ['Rental equipment']) . "\n\n"
        . "Everything OK - send review request: {$goodUrl}\n"
        . "There is a problem: {$problemUrl}\n";

    return rental_finalize_email_message($subject, $html, $plain, rental_email_reply_to());
}

function rental_build_google_review_request_email_message(array $reservation, array $items): array
{
    $customerName = rental_clean_text($reservation['customer_name'] ?? '');
    $reviewUrl = rental_google_review_url();
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $subject = 'How was your Ardi Rent & Service experience?';
    $headline = 'Thank you' . ($customerName !== '' ? ', ' . $customerName : '') . '!';
    $html = '<!doctype html><html><body style="margin:0;background:#ededed;font-family:Arial,Helvetica,sans-serif;color:#111">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#ededed"><tr><td align="center" style="padding:32px 12px">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px;background:#fff;border-radius:22px;overflow:hidden">'
        . '<tr><td style="background:#050505;color:#fff;padding:30px"><p style="margin:0;color:#cfcfcf;font-size:12px;letter-spacing:2px;text-transform:uppercase">Ardi Rent &amp; Service</p>'
        . '<h1 style="margin:14px 0 0;font-size:31px;line-height:1.15">' . $e($headline) . '</h1></td></tr>'
        . '<tr><td style="padding:32px"><p style="margin:0 0 18px;color:#444;font-size:16px;line-height:1.65">We hope the rental helped you capture exactly what you needed. If everything went well, your review helps other creators trust Ardi Rent &amp; Service.</p>'
        . '<p style="margin:26px 0"><a href="' . $e($reviewUrl) . '" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:15px 24px;border-radius:999px;font-weight:bold">Leave a Google Review</a></p>'
        . '<p style="margin:0;color:#777;font-size:13px;line-height:1.55">It only takes a minute. Thank you for supporting a Puerto Rico creative rental business.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
    $plain = "Thank you for renting with Ardi Rent & Service.\n\n"
        . "If everything went well, please leave us a Google Review here:\n{$reviewUrl}\n\n"
        . "Thank you for supporting Ardi Rent & Service.\n";

    return rental_finalize_email_message($subject, $html, $plain, rental_email_reply_to());
}

function rental_smtp_configured(): bool
{
    return rental_env('RENTAL_SMTP_HOST') !== ''
        && rental_env('RENTAL_SMTP_USERNAME') !== ''
        && rental_env('RENTAL_SMTP_PASSWORD') !== '';
}

function rental_extract_email_address(string $headerValue): string
{
    if (preg_match('/<([^>]+)>/', $headerValue, $matches)) {
        return filter_var($matches[1], FILTER_VALIDATE_EMAIL) ?: '';
    }

    return filter_var($headerValue, FILTER_VALIDATE_EMAIL) ?: '';
}

function rental_smtp_read_response($socket): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function rental_smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    $response = rental_smtp_read_response($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Unexpected SMTP response: ' . trim($response));
    }
    return $response;
}

function rental_send_smtp_message(string $recipientEmail, array $message): bool
{
    $host = rental_env('RENTAL_SMTP_HOST');
    $port = (int) rental_env('RENTAL_SMTP_PORT', '587');
    $username = rental_env('RENTAL_SMTP_USERNAME');
    $password = rental_env('RENTAL_SMTP_PASSWORD');
    $encryption = strtolower(rental_env('RENTAL_SMTP_ENCRYPTION', 'starttls'));
    $fromHeader = rental_email_from();
    $fromEmail = rental_extract_email_address($fromHeader);

    if ($host === '' || $port <= 0 || $username === '' || $password === '' || $fromEmail === '') {
        return false;
    }

    $remote = $encryption === 'ssl' ? 'ssl://' . $host . ':' . $port : $host . ':' . $port;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ],
    ]);

    $socket = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!is_resource($socket)) {
        error_log("SMTP connection failed: {$errstr} ({$errno})");
        return false;
    }

    stream_set_timeout($socket, 30);

    try {
        $greeting = rental_smtp_read_response($socket);
        if ((int) substr($greeting, 0, 3) !== 220) {
            throw new RuntimeException('Unexpected SMTP greeting: ' . trim($greeting));
        }

        $hostname = $_SERVER['SERVER_NAME'] ?? 'ardirentservice.com';
        rental_smtp_command($socket, 'EHLO ' . $hostname, [250]);

        if (in_array($encryption, ['tls', 'starttls'], true)) {
            rental_smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Unable to enable SMTP TLS');
            }
            rental_smtp_command($socket, 'EHLO ' . $hostname, [250]);
        }

        rental_smtp_command($socket, 'AUTH LOGIN', [334]);
        rental_smtp_command($socket, base64_encode($username), [334]);
        rental_smtp_command($socket, base64_encode($password), [235]);
        rental_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        rental_smtp_command($socket, 'RCPT TO:<' . $recipientEmail . '>', [250, 251]);
        rental_smtp_command($socket, 'DATA', [354]);

        $headers = array_merge(
            [
                'To: ' . $recipientEmail,
                'Subject: ' . (string) ($message['subject'] ?? ''),
            ],
            $message['headers'] ?? []
        );
        $raw = implode("\r\n", $headers) . "\r\n\r\n" . (string) ($message['body'] ?? '');
        $raw = str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $raw);
        fwrite($socket, $raw . "\r\n.\r\n");
        $dataResponse = rental_smtp_read_response($socket);
        if ((int) substr($dataResponse, 0, 3) !== 250) {
            throw new RuntimeException('Unexpected SMTP DATA response: ' . trim($dataResponse));
        }

        rental_smtp_command($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        error_log('SMTP send failed: ' . $e->getMessage());
        rental_smtp_command_safely($socket, 'QUIT');
        fclose($socket);
        return false;
    }
}

function rental_smtp_command_safely($socket, string $command): void
{
    try {
        if (is_resource($socket)) {
            fwrite($socket, $command . "\r\n");
        }
    } catch (Throwable) {
        // Best-effort cleanup only.
    }
}

function rental_allowed_origins(): array
{
    $configured = rental_env(
        'ALLOWED_ORIGINS',
        'https://ardirentservice.com,https://www.ardirentservice.com,https://jaimeespinalpr.github.io'
    );

    return array_values(array_filter(array_map(
        static fn(string $origin): string => rtrim(trim($origin), '/'),
        explode(',', $configured)
    )));
}

function rental_apply_cors(): void
{
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? rtrim((string) $_SERVER['HTTP_ORIGIN'], '/') : '';
    if ($origin !== '' && in_array($origin, rental_allowed_origins(), true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

rental_apply_cors();

function rental_item_rate_cents(string $itemId): int
{
    $cameraRates = [
        'cable-hdmi'              => 100,
        'sony-a7-v'               => 7500,
        'sony-a7s-iii'            => 7500,
        'sony-a7-iv'              => 7500,
        'sony-alpha-1'            => 9500,
        'gopro-hero12-black'      => 7500,
        'gopro-hero13-black'      => 7500,
        'dji-osmo-pocket'         => 7500,
        'sony-pxw-z150-4k-xdcam' => 7500,
    ];

    return $cameraRates[$itemId] ?? DAILY_RATE_CENTS;
}

function rental_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function rental_redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function rental_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = __DIR__ . '/data/rentals.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reservations (
            id                   INTEGER PRIMARY KEY AUTOINCREMENT,
            checkout_session_id  TEXT UNIQUE NOT NULL,
            start_date           TEXT NOT NULL,
            end_date             TEXT NOT NULL,
            customer_name        TEXT NOT NULL,
            customer_email       TEXT NOT NULL,
            customer_phone       TEXT,
            total_amount_cents   INTEGER NOT NULL,
            currency             TEXT NOT NULL,
            status               TEXT NOT NULL,
            fulfillment_status   TEXT NOT NULL DEFAULT "pending",
            return_checked_at    TEXT,
            review_requested_at  TEXT,
            created_at           TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reservation_items (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            reservation_id     INTEGER NOT NULL,
            item_id            TEXT NOT NULL,
            item_title         TEXT NOT NULL,
            unit_amount_cents  INTEGER NOT NULL,
            FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
        )'
    );

    $columnsStmt = $pdo->query('PRAGMA table_info(reservations)');
    $columns = $columnsStmt ? $columnsStmt->fetchAll() : [];
    $columnNames = array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $columns);
    if (!in_array('fulfillment_status', $columnNames, true)) {
        $pdo->exec("ALTER TABLE reservations ADD COLUMN fulfillment_status TEXT NOT NULL DEFAULT 'pending'");
    }
    if (!in_array('return_checked_at', $columnNames, true)) {
        $pdo->exec('ALTER TABLE reservations ADD COLUMN return_checked_at TEXT');
    }
    if (!in_array('review_requested_at', $columnNames, true)) {
        $pdo->exec('ALTER TABLE reservations ADD COLUMN review_requested_at TEXT');
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservation_dates ON reservations(start_date, end_date, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservation_items_item ON reservation_items(item_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservation_fulfillment ON reservations(fulfillment_status, created_at)');

    return $pdo;
}

function rental_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function rental_clean_text(mixed $value): string
{
    $text = trim((string) $value);
    $text = strip_tags($text);
    return preg_replace('/\s+/', ' ', $text) ?? '';
}

function rental_validate_date(string $value): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value ? $value : null;
}

function rental_normalize_item_id(string $value): ?string
{
    $id = strtolower(trim($value));
    if ($id === '' || !preg_match('/^[a-z0-9-]{3,120}$/', $id)) {
        return null;
    }
    return $id;
}

function rental_days_between(string $startDate, string $endDate): int
{
    $start = new DateTimeImmutable($startDate);
    $end   = new DateTimeImmutable($endDate);
    $days  = (int) $start->diff($end)->format('%a') + 1;
    return max(1, $days);
}

function rental_find_unavailable_items(PDO $pdo, string $startDate, string $endDate, array $itemIds): array
{
    if ($itemIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $sql =
        'SELECT ri.item_id, COUNT(*) AS reserved_count
         FROM reservation_items ri
         INNER JOIN reservations r ON r.id = ri.reservation_id
         WHERE r.status = ?
           AND r.start_date <= ?
           AND r.end_date   >= ?
           AND ri.item_id IN (' . $placeholders . ')
         GROUP BY ri.item_id';

    $stmt   = $pdo->prepare($sql);
    $params = array_merge(['paid', $endDate, $startDate], $itemIds);
    $stmt->execute($params);

    $rows = $stmt->fetchAll();
    $unavailable = [];
    foreach ($rows as $row) {
        $itemId = (string) ($row['item_id'] ?? '');
        if ($itemId === '') {
            continue;
        }
        $reservedCount = (int) ($row['reserved_count'] ?? 0);
        if ($reservedCount >= rental_item_inventory($itemId)) {
            $unavailable[] = $itemId;
        }
    }

    return array_values(array_unique($unavailable));
}

function rental_item_inventory(string $itemId): int
{
    // Default inventory per item is 1 until additional units are configured.
    $inventory = [
        // 'sony-a7-v' => 2,
    ];

    $value = (int) ($inventory[$itemId] ?? 1);
    return max(1, $value);
}

function rental_reservation_statuses(): array
{
    return ['pending', 'confirmed', 'ready', 'delivered', 'completed', 'cancelled'];
}

function rental_get_reservation(PDO $pdo, int $reservationId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? LIMIT 1');
    $stmt->execute([$reservationId]);
    $reservation = $stmt->fetch();
    if (!is_array($reservation)) {
        return null;
    }

    $itemsStmt = $pdo->prepare(
        'SELECT item_id, item_title, unit_amount_cents
         FROM reservation_items
         WHERE reservation_id = ?
         ORDER BY id ASC'
    );
    $itemsStmt->execute([$reservationId]);
    $reservation['items'] = $itemsStmt->fetchAll();

    return $reservation;
}

function rental_update_reservation_fulfillment_status(PDO $pdo, int $reservationId, string $status): bool
{
    if (!in_array($status, rental_reservation_statuses(), true)) {
        return false;
    }

    $stmt = $pdo->prepare('UPDATE reservations SET fulfillment_status = ? WHERE id = ?');
    return $stmt->execute([$status, $reservationId]);
}

function rental_base_url(): string
{
    $https  = $_SERVER['HTTPS'] ?? '';
    $scheme = ($https === 'on' || $https === '1') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $prefix = $dir === '' || $dir === '/' ? '' : $dir;
    return $scheme . '://' . $host . $prefix;
}

function stripe_request(string $method, string $path, array $form = []): array
{
    $secret = getenv('STRIPE_SECRET_KEY') ?: '';
    if ($secret === '') {
        return ['ok' => false, 'error' => 'missing_secret_key'];
    }

    $mode = strtolower(trim((string) (getenv('STRIPE_MODE') ?: '')));
    if (!in_array($mode, ['test', 'live'], true)) {
        return ['ok' => false, 'error' => 'invalid_or_missing_stripe_mode'];
    }

    $isTestMode = $mode === 'test';
    $isTestKey  = str_starts_with($secret, 'sk_test_');
    $isLiveKey  = str_starts_with($secret, 'sk_live_');

    if ($isTestMode && !$isTestKey) {
        return ['ok' => false, 'error' => 'test_mode_requires_sk_test_key'];
    }
    if (!$isTestMode && !$isLiveKey) {
        return ['ok' => false, 'error' => 'live_mode_requires_sk_live_key'];
    }

    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $ch  = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init_failed'];
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secret,
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }

    $body   = curl_exec($ch);
    $error  = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'status' => $status, 'error' => $error !== '' ? $error : 'empty_response'];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'status' => $status, 'error' => 'invalid_json'];
    }

    if ($status < 200 || $status >= 300) {
        $message = (string) ($json['error']['message'] ?? 'stripe_error');
        return ['ok' => false, 'status' => $status, 'error' => $message, 'raw' => $json];
    }

    return ['ok' => true, 'status' => $status, 'data' => $json];
}
