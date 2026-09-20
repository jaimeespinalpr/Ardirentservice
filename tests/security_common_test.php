<?php
declare(strict_types=1);
define('RENTAL_SKIP_AUTO_CORS', true);
require_once __DIR__ . '/../rentals_common.php';

function expect_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "$label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

putenv('RENTAL_ADMIN_TOKEN=test-admin-secret-with-enough-entropy');
$_ENV['RENTAL_ADMIN_TOKEN'] = 'test-admin-secret-with-enough-entropy';
$_SERVER['RENTAL_ADMIN_TOKEN'] = 'test-admin-secret-with-enough-entropy';

expect_same('pay.ardirentservice.com', rental_normalize_host('PAY.ArdiRentService.com:443'), 'host normalized');
expect_same('', rental_normalize_host('evil host.example'), 'malformed host rejected');
expect_same(true, rental_is_production_host('ardirentservice.com'), 'apex host accepted');
expect_same(true, rental_is_production_host('pay.ardirentservice.com:443'), 'real subdomain accepted');
expect_same(false, rental_is_production_host('evilardirentservice.com'), 'false suffix rejected');
expect_same(false, rental_is_production_host('ardirentservice.com.evil.test'), 'lookalike suffix rejected');

$expires = time() + 600;
$signature = rental_sign_return_action(42, 'returned_ok', $expires);
expect_same(true, rental_verify_return_action(42, 'returned_ok', $expires, $signature), 'signed action accepted');
expect_same(false, rental_verify_return_action(43, 'returned_ok', $expires, $signature), 'reservation tampering rejected');
expect_same(false, rental_verify_return_action(42, 'returned_problem', $expires, $signature), 'action tampering rejected');
expect_same(false, rental_verify_return_action(42, 'returned_ok', time() - 1, $signature), 'expired action rejected');

$paidSession = ['payment_status' => 'paid', 'amount_total' => 14500, 'currency' => 'usd'];
expect_same(true, rental_checkout_session_matches($paidSession, 14500, 'usd'), 'matching checkout accepted');
expect_same(false, rental_checkout_session_matches($paidSession, 14499, 'usd'), 'amount mismatch rejected');
expect_same(false, rental_checkout_session_matches($paidSession, 14500, 'eur'), 'currency mismatch rejected');
expect_same(false, rental_checkout_session_matches(array_merge($paidSession, ['payment_status' => 'unpaid']), 14500, 'usd'), 'unpaid checkout rejected');

fwrite(STDOUT, "security PHP tests passed\n");
