<?php
declare(strict_types=1);
require_once __DIR__ . '/rentals_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    rental_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$startDate = rental_validate_date(rental_clean_text($_GET['start_date'] ?? ''));
$endDate = rental_validate_date(rental_clean_text($_GET['end_date'] ?? ''));
$itemsRaw = rental_clean_text($_GET['items'] ?? '');

if ($startDate === null || $endDate === null || $startDate > $endDate) {
    rental_json(['ok' => false, 'error' => 'invalid_dates'], 422);
}

$itemIds = [];
if ($itemsRaw !== '') {
    foreach (explode(',', $itemsRaw) as $chunk) {
        $normalized = rental_normalize_item_id($chunk);
        if ($normalized !== null) {
            $itemIds[] = $normalized;
        }
    }
    $itemIds = array_values(array_unique($itemIds));
}

$pdo = rental_db();
$unavailable = rental_find_unavailable_items($pdo, $startDate, $endDate, $itemIds);

rental_json([
    'ok' => true,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'unavailable' => $unavailable,
]);
