<?php
declare(strict_types=1);

const VISITOR_CONSENT_VERSION = '2026-07-27-v1';
const VISITOR_IDLE_SECONDS = 900;
const VISITOR_RETENTION_SECONDS = 604800;
const VISITOR_EVENTS_PER_MINUTE = 300;
const VISITOR_STARTS_PER_MINUTE = 10;
const VISITOR_ALLOWED_ACTIONS = [
    'request-quote', 'rent-now', 'contact', 'book-now', 'view-equipment', 'checkout',
];

function visitor_allowed_origins(): array
{
    return ['https://ardirentservice.com', 'https://www.ardirentservice.com'];
}

function visitor_db_path(): string
{
    return __DIR__ . '/data/visitor_analytics.sqlite';
}

function visitor_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . visitor_db_path());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    @chmod(visitor_db_path(), 0600);
    return $pdo;
}

function visitor_clean_page(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '/';
    }

    $parts = parse_url($raw);
    if ($parts === false) {
        return '/';
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host !== '' && !in_array($host, ['ardirentservice.com', 'www.ardirentservice.com'], true)) {
        return '/';
    }
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '' || $path[0] !== '/' || strlen($path) > 180 || !preg_match('#^/[A-Za-z0-9_./-]*$#', $path)) {
        return '/';
    }
    $path = preg_replace('#/{2,}#', '/', $path) ?: '/';
    if ($path === '/index.html') {
        return '/';
    }
    $allowed = [
        '/', '/about.html', '/account.html', '/case-study.html', '/contact.html',
        '/equipment.html', '/lenses.html', '/prints.html', '/production.html', '/services.html',
    ];
    return in_array($path, $allowed, true) ? $path : '/other';
}

function visitor_clean_uuid(mixed $value): ?string
{
    $uuid = strtolower(trim((string) $value));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)
        ? $uuid
        : null;
}

function visitor_clean_active_ms(mixed $value): int
{
    $milliseconds = filter_var($value, FILTER_VALIDATE_INT);
    if ($milliseconds === false || $milliseconds <= 0) {
        return 0;
    }
    return min(30000, $milliseconds);
}

function visitor_clean_click_label(mixed $value): string
{
    $label = strtolower(trim((string) $value));
    if (in_array($label, ['button', 'external-link'], true)) {
        return $label;
    }
    if (str_starts_with($label, 'link:')) {
        return 'link:' . visitor_clean_page(substr($label, 5));
    }
    if (str_starts_with($label, 'action:') && in_array(substr($label, 7), VISITOR_ALLOWED_ACTIONS, true)) {
        return $label;
    }
    return '';
}

function visitor_increment_rate_bucket(PDO $pdo, string $bucket, int $expiresAt): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO visitor_rate_limits (bucket, event_count, expires_at) VALUES (?, 1, ?)
         ON CONFLICT(bucket) DO UPDATE SET event_count = visitor_rate_limits.event_count + 1'
    );
    $stmt->execute([$bucket, $expiresAt]);
    $stmt = $pdo->prepare('SELECT event_count FROM visitor_rate_limits WHERE bucket = ?');
    $stmt->execute([$bucket]);
    return (int) $stmt->fetchColumn();
}

function visitor_enforce_rate_limit(PDO $pdo, string $eventType, int $now): void
{
    $minute = intdiv($now, 60);
    $expiresAt = ($minute + 2) * 60;
    if (visitor_increment_rate_bucket($pdo, "events:{$minute}", $expiresAt) > VISITOR_EVENTS_PER_MINUTE) {
        throw new InvalidArgumentException('rate_limited');
    }
    if ($eventType === 'start' && visitor_increment_rate_bucket($pdo, "starts:{$minute}", $expiresAt) > VISITOR_STARTS_PER_MINUTE) {
        throw new InvalidArgumentException('rate_limited');
    }
}

function visitor_clean_target_type(mixed $value): string
{
    $type = strtolower(trim((string) $value));
    return in_array($type, ['link', 'button', 'navigation'], true) ? $type : 'control';
}

function visitor_build_summary(array $pages, array $clicks): array
{
    $cleanPages = [];
    $totalActiveMs = 0;
    foreach ($pages as $page) {
        $activeMs = max(0, (int) ($page['active_ms'] ?? 0));
        $totalActiveMs += $activeMs;
        $cleanPages[] = [
            'page_path' => visitor_clean_page($page['page_path'] ?? '/'),
            'active_ms' => $activeMs,
            'views' => max(0, (int) ($page['views'] ?? 0)),
        ];
    }

    $cleanClicks = [];
    foreach ($clicks as $click) {
        $cleanClicks[] = [
            'page_path' => visitor_clean_page($click['page_path'] ?? '/'),
            'target_type' => visitor_clean_target_type($click['target_type'] ?? ''),
            'target_label' => visitor_clean_click_label($click['target_label'] ?? ''),
            'click_count' => max(0, (int) ($click['click_count'] ?? 0)),
        ];
    }

    return ['total_active_ms' => $totalActiveMs, 'pages' => $cleanPages, 'clicks' => $cleanClicks];
}

function visitor_prepare_db(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS visitor_sessions (
            session_id TEXT PRIMARY KEY,
            consent_version TEXT NOT NULL,
            locale TEXT NOT NULL DEFAULT "",
            started_at INTEGER NOT NULL,
            last_seen_at INTEGER NOT NULL,
            ended_at INTEGER,
            start_notified_at INTEGER,
            summary_notified_at INTEGER
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS visitor_pages (
            session_id TEXT NOT NULL,
            tab_id TEXT NOT NULL,
            page_path TEXT NOT NULL,
            active_ms INTEGER NOT NULL DEFAULT 0,
            views INTEGER NOT NULL DEFAULT 0,
            first_seen_at INTEGER NOT NULL,
            last_seen_at INTEGER NOT NULL,
            PRIMARY KEY (session_id, tab_id, page_path),
            FOREIGN KEY (session_id) REFERENCES visitor_sessions(session_id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS visitor_clicks (
            session_id TEXT NOT NULL,
            page_path TEXT NOT NULL,
            target_type TEXT NOT NULL,
            target_label TEXT NOT NULL,
            click_count INTEGER NOT NULL DEFAULT 0,
            last_clicked_at INTEGER NOT NULL,
            PRIMARY KEY (session_id, page_path, target_type, target_label),
            FOREIGN KEY (session_id) REFERENCES visitor_sessions(session_id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS visitor_rate_limits (
            bucket TEXT PRIMARY KEY,
            event_count INTEGER NOT NULL,
            expires_at INTEGER NOT NULL
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visitor_pending_start ON visitor_sessions(start_notified_at, started_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visitor_pending_summary ON visitor_sessions(summary_notified_at, last_seen_at)');
}

function visitor_drop_legacy_tables(PDO $legacyPdo): void
{
    $stmt = $legacyPdo->query(
        "SELECT COUNT(*) FROM sqlite_master
         WHERE type = 'table' AND name IN ('visitor_sessions', 'visitor_pages', 'visitor_clicks', 'visitor_rate_limits', 'visitor_meta')"
    );
    $tableCount = (int) $stmt->fetchColumn();
    $stmt->closeCursor();
    if ($tableCount === 0) {
        return;
    }

    $legacyPdo->beginTransaction();
    try {
        $legacyPdo->exec('DROP TABLE IF EXISTS visitor_clicks');
        $legacyPdo->exec('DROP TABLE IF EXISTS visitor_pages');
        $legacyPdo->exec('DROP TABLE IF EXISTS visitor_rate_limits');
        $legacyPdo->exec('DROP TABLE IF EXISTS visitor_meta');
        $legacyPdo->exec('DROP TABLE IF EXISTS visitor_sessions');
        $legacyPdo->commit();
    } catch (Throwable $error) {
        if ($legacyPdo->inTransaction()) {
            $legacyPdo->rollBack();
        }
        throw $error;
    }
}

function visitor_record_event(PDO $pdo, array $payload, int $now): array
{
    $sessionId = visitor_clean_uuid($payload['session_id'] ?? null);
    $tabId = visitor_clean_uuid($payload['tab_id'] ?? null);
    $eventType = strtolower(trim((string) ($payload['event_type'] ?? '')));
    if ($sessionId === null || $tabId === null || !in_array($eventType, ['start', 'page_view', 'heartbeat', 'click', 'end'], true)) {
        throw new InvalidArgumentException('invalid_event');
    }
    if (($payload['consent_version'] ?? '') !== VISITOR_CONSENT_VERSION) {
        throw new InvalidArgumentException('invalid_consent');
    }

    $page = visitor_clean_page($payload['page_path'] ?? '/');
    $activeMs = visitor_clean_active_ms($payload['active_ms'] ?? 0);
    $locale = mb_substr(trim((string) ($payload['locale'] ?? '')), 0, 12, 'UTF-8');

    $pdo->beginTransaction();
    try {
        visitor_enforce_rate_limit($pdo, $eventType, $now);
        if ($eventType !== 'start') {
            $stmt = $pdo->prepare('SELECT 1 FROM visitor_sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            if ($stmt->fetchColumn() === false) {
                throw new InvalidArgumentException('unknown_session');
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO visitor_sessions (session_id, consent_version, locale, started_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(session_id) DO UPDATE SET last_seen_at = MAX(last_seen_at, excluded.last_seen_at)'
        );
        $stmt->execute([$sessionId, VISITOR_CONSENT_VERSION, $locale, $now, $now]);

        $views = $eventType === 'page_view' || $eventType === 'start' ? 1 : 0;
        if ($views > 0 || $activeMs > 0) {
            $stmt = $pdo->prepare(
                'INSERT INTO visitor_pages (session_id, tab_id, page_path, active_ms, views, first_seen_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(session_id, tab_id, page_path) DO UPDATE SET
                    active_ms = visitor_pages.active_ms + excluded.active_ms,
                    views = visitor_pages.views + excluded.views,
                    last_seen_at = MAX(visitor_pages.last_seen_at, excluded.last_seen_at)'
            );
            $stmt->execute([$sessionId, $tabId, $page, $activeMs, $views, $now, $now]);
        }

        if ($eventType === 'click') {
            $label = visitor_clean_click_label($payload['target_label'] ?? '');
            if ($label !== '') {
                $targetType = visitor_clean_target_type($payload['target_type'] ?? '');
                $stmt = $pdo->prepare(
                    'INSERT INTO visitor_clicks (session_id, page_path, target_type, target_label, click_count, last_clicked_at)
                     VALUES (?, ?, ?, ?, 1, ?)
                     ON CONFLICT(session_id, page_path, target_type, target_label) DO UPDATE SET
                        click_count = visitor_clicks.click_count + 1,
                        last_clicked_at = excluded.last_clicked_at'
                );
                $stmt->execute([$sessionId, $page, $targetType, $label, $now]);
            }
        }

        if ($eventType === 'end') {
            $pdo->prepare('UPDATE visitor_sessions SET ended_at = ? WHERE session_id = ?')->execute([$now, $sessionId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return ['session_id' => $sessionId, 'accepted' => true];
}

function visitor_pending_notifications(PDO $pdo, int $now): array
{
    $pdo->prepare('DELETE FROM visitor_sessions WHERE last_seen_at < ?')->execute([$now - VISITOR_RETENTION_SECONDS]);
    $pdo->prepare('DELETE FROM visitor_rate_limits WHERE expires_at < ?')->execute([$now]);

    $startsStmt = $pdo->prepare(
        'SELECT session_id, locale, started_at FROM visitor_sessions
         WHERE start_notified_at IS NULL ORDER BY started_at ASC LIMIT 25'
    );
    $startsStmt->execute();
    $starts = $startsStmt->fetchAll() ?: [];

    $summariesStmt = $pdo->prepare(
        'SELECT session_id, locale, started_at, last_seen_at, ended_at FROM visitor_sessions
         WHERE summary_notified_at IS NULL AND last_seen_at <= ? ORDER BY last_seen_at ASC LIMIT 25'
    );
    $summariesStmt->execute([$now - VISITOR_IDLE_SECONDS]);
    $sessions = $summariesStmt->fetchAll() ?: [];

    $summaries = [];
    $pageStmt = $pdo->prepare(
        'SELECT page_path, SUM(active_ms) AS active_ms, SUM(views) AS views
         FROM visitor_pages WHERE session_id = ? GROUP BY page_path ORDER BY first_seen_at ASC'
    );
    $clickStmt = $pdo->prepare(
        'SELECT page_path, target_type, target_label, click_count
         FROM visitor_clicks WHERE session_id = ? ORDER BY last_clicked_at ASC LIMIT 50'
    );
    foreach ($sessions as $session) {
        $pageStmt->execute([$session['session_id']]);
        $clickStmt->execute([$session['session_id']]);
        $summary = visitor_build_summary($pageStmt->fetchAll() ?: [], $clickStmt->fetchAll() ?: []);
        $summaries[] = array_merge($session, $summary);
    }

    return ['starts' => $starts, 'summaries' => $summaries, 'idle_seconds' => VISITOR_IDLE_SECONDS];
}

function visitor_ack_notifications(PDO $pdo, array $payload, int $now): int
{
    $kind = (string) ($payload['kind'] ?? '');
    $ids = array_values(array_filter(array_map('visitor_clean_uuid', (array) ($payload['session_ids'] ?? []))));
    if (!in_array($kind, ['start', 'summary'], true) || $ids === []) {
        throw new InvalidArgumentException('invalid_ack');
    }
    $column = $kind === 'start' ? 'start_notified_at' : 'summary_notified_at';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE visitor_sessions SET {$column} = ? WHERE session_id IN ({$placeholders})");
    $stmt->execute(array_merge([$now], $ids));
    return $stmt->rowCount();
}
