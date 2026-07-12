<?php
declare(strict_types=1);

/**
 * Count-only migration preflight. This script never selects identity fields,
 * credentials, password hashes, reservation details, or customer content.
 *
 * Usage on Hostinger: php accounts_migration_audit.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/rentals_common.php';

$pdo = rental_db();
$allowedTables = ['customer_accounts', 'reservations', 'reservation_items'];
$result = [];

foreach ($allowedTables as $table) {
    $exists = $pdo->prepare(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?"
    );
    $exists->execute([$table]);
    if ((int) $exists->fetchColumn() !== 1) {
        $result[$table] = null;
        continue;
    }

    // Names come exclusively from the fixed allowlist above, never user input.
    $result[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}

fwrite(STDOUT, json_encode([
    'ok' => true,
    'counts' => $result,
    'contains_personal_data' => false,
    'sensitive_columns_read' => false,
], JSON_UNESCAPED_SLASHES) . PHP_EOL);
