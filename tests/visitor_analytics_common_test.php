<?php
declare(strict_types=1);
require_once __DIR__ . '/../visitor_analytics_common.php';

function expect_same(mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "$label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

expect_same('/equipment.html', visitor_clean_page('/equipment.html?email=secret@example.com#checkout'), 'query stripped');
expect_same('/', visitor_clean_page('https://evil.example/private?email=x'), 'external host rejected');
expect_same('/about.html', visitor_clean_page('/about.html?email=x'), 'query stripped');
expect_same('/other', visitor_clean_page('/customer/Jaime-Espinal?email=x'), 'unknown path is bucketed');
expect_same(null, visitor_clean_uuid('not-a-uuid'), 'invalid UUID rejected');
expect_same('123e4567-e89b-42d3-a456-426614174000', visitor_clean_uuid('123e4567-e89b-42d3-a456-426614174000'), 'UUID accepted');
expect_same(30000, visitor_clean_active_ms(90000), 'active delta capped');
expect_same(0, visitor_clean_active_ms(-100), 'negative active delta rejected');

$label = visitor_clean_click_label('Email secret@example.com or call +1 (939) 555-1212');
expect_same('', $label, 'free-text click label rejected');
expect_same('link:/equipment.html', visitor_clean_click_label('link:/equipment.html'), 'canonical link descriptor accepted');
expect_same('action:request-quote', visitor_clean_click_label('action:request-quote'), 'static action descriptor accepted');
expect_same('', visitor_clean_click_label('action:jaime-customer-123'), 'arbitrary action descriptor rejected');
expect_same('', visitor_clean_click_label('Jaime Espinal'), 'name-like free text rejected');
expect_same('visitor_analytics.sqlite', basename(visitor_db_path()), 'analytics uses a dedicated database file');

$summary = visitor_build_summary([
    ['page_path' => '/about.html', 'active_ms' => 61000, 'views' => 1],
    ['page_path' => '/equipment.html', 'active_ms' => 120000, 'views' => 2],
], [
    ['page_path' => '/equipment.html', 'target_type' => 'link', 'target_label' => 'Request availability', 'click_count' => 2],
]);
expect_same(181000, $summary['total_active_ms'], 'total duration summed');
expect_same(2, count($summary['pages']), 'pages preserved');
expect_same(1, count($summary['clicks']), 'clicks preserved');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
visitor_prepare_db($pdo);
$now = time();
$sessionId = '123e4567-e89b-42d3-a456-426614174000';
$tabId = '123e4567-e89b-42d3-a456-426614174001';
visitor_record_event($pdo, [
    'event_type' => 'start',
    'session_id' => $sessionId,
    'tab_id' => $tabId,
    'page_path' => '/equipment.html',
    'active_ms' => 0,
    'locale' => 'es-PR',
    'consent_version' => VISITOR_CONSENT_VERSION,
], $now);
visitor_record_event($pdo, [
    'event_type' => 'heartbeat',
    'session_id' => $sessionId,
    'tab_id' => $tabId,
    'page_path' => '/equipment.html',
    'active_ms' => 65000,
    'consent_version' => VISITOR_CONSENT_VERSION,
], $now + 1);
visitor_record_event($pdo, [
    'event_type' => 'click',
    'session_id' => $sessionId,
    'tab_id' => $tabId,
    'page_path' => '/equipment.html',
    'target_type' => 'button',
    'target_label' => 'action:rent-now',
    'consent_version' => VISITOR_CONSENT_VERSION,
], $now + 2);
$pendingNow = visitor_pending_notifications($pdo, $now + 2);
expect_same(1, count($pendingNow['starts']), 'new session has one start notification');
expect_same(0, count($pendingNow['summaries']), 'active session has no early summary');
$pendingIdle = visitor_pending_notifications($pdo, $now + VISITOR_IDLE_SECONDS + 3);
expect_same(1, count($pendingIdle['summaries']), 'idle session has one summary');
expect_same(30000, $pendingIdle['summaries'][0]['total_active_ms'], 'heartbeat delta is capped');
expect_same('/equipment.html', $pendingIdle['summaries'][0]['pages'][0]['page_path'], 'summary includes page');
expect_same('action:rent-now', $pendingIdle['summaries'][0]['clicks'][0]['target_label'], 'summary includes safe click descriptor');
visitor_ack_notifications($pdo, ['kind' => 'start', 'session_ids' => [$sessionId]], $now + 3);
visitor_ack_notifications($pdo, ['kind' => 'summary', 'session_ids' => [$sessionId]], $now + VISITOR_IDLE_SECONDS + 3);
$pendingAcked = visitor_pending_notifications($pdo, $now + VISITOR_IDLE_SECONDS + 4);
expect_same(0, count($pendingAcked['starts']), 'acknowledged start is not repeated');
expect_same(0, count($pendingAcked['summaries']), 'acknowledged summary is not repeated');

$ratePdo = new PDO('sqlite::memory:');
$ratePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
visitor_prepare_db($ratePdo);
for ($index = 0; $index < VISITOR_STARTS_PER_MINUTE; $index++) {
    $suffix = str_pad((string) ($index + 10), 12, '0', STR_PAD_LEFT);
    visitor_record_event($ratePdo, [
        'event_type' => 'start',
        'session_id' => "123e4567-e89b-42d3-a456-{$suffix}",
        'tab_id' => $tabId,
        'page_path' => '/',
        'consent_version' => VISITOR_CONSENT_VERSION,
    ], $now);
}
$rateLimited = false;
try {
    visitor_record_event($ratePdo, [
        'event_type' => 'start',
        'session_id' => '123e4567-e89b-42d3-a456-999999999999',
        'tab_id' => $tabId,
        'page_path' => '/',
        'consent_version' => VISITOR_CONSENT_VERSION,
    ], $now);
} catch (InvalidArgumentException $error) {
    $rateLimited = $error->getMessage() === 'rate_limited';
}
expect_same(true, $rateLimited, 'start events are rate limited without storing IP addresses');

$legacyPdo = new PDO('sqlite::memory:');
$legacyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
visitor_prepare_db($legacyPdo);
visitor_drop_legacy_tables($legacyPdo);
$legacyTables = (int) $legacyPdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'visitor_%'"
)->fetchColumn();
expect_same(0, $legacyTables, 'legacy analytics tables are removed from the rental database');

fwrite(STDOUT, "visitor-analytics PHP tests passed\n");
