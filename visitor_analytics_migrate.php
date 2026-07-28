<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('RENTAL_SKIP_AUTO_CORS', true);
require_once __DIR__ . '/rentals_common.php';
require_once __DIR__ . '/visitor_analytics_common.php';

$analytics = visitor_db();
visitor_prepare_db($analytics);

if (!is_file(__DIR__ . '/data/rentals.sqlite')) {
    fwrite(STDOUT, "No legacy analytics database found; migration skipped.\n");
    exit(0);
}

visitor_drop_legacy_tables(rental_db());
fwrite(STDOUT, "Legacy visitor analytics tables removed; dedicated database ready.\n");
