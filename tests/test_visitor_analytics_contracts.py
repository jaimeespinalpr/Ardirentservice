import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[1]
HTML = sorted(ROOT.glob('*.html'))


def test_every_public_html_uses_consent_tracker_and_no_unconditional_ga():
    assert len(HTML) == 10
    for path in HTML:
        text = path.read_text(encoding='utf-8')
        assert 'assets/visitor-analytics.js?v=20260727-v22' in text, path.name
        assert '<script async src="https://www.googletagmanager.com/gtag/js' not in text, path.name


def test_consent_dialog_uses_plain_privacy_language_without_analytics_branding():
    text = (ROOT / 'assets/visitor-analytics.js').read_text(encoding='utf-8')
    assert "'Preferencias de privacidad'" in text
    assert "'Privacy preferences'" in text
    assert "'Permitir medición'" in text
    assert "'Allow measurement'" in text
    for visible_copy in [
        'Preferencias de analítica', 'Analytics preferences',
        'Aceptar analítica', 'Accept analytics',
        'usamos analítica anónima', 'use anonymous analytics',
    ]:
        assert visible_copy not in text


def test_backend_is_deployed_and_secret_is_not_in_static_assets():
    workflow = (ROOT / '.github/workflows/deploy-scp.yml').read_text(encoding='utf-8')
    assert "--include 'visitor_analytics.php'" in workflow
    assert "--include 'visitor_analytics_common.php'" in workflow
    assert 'VISITOR_ANALYTICS_TOKEN=${VISITOR_ANALYTICS_TOKEN}' in workflow
    for path in [ROOT / 'assets/visitor-analytics.js', ROOT / 'assets/visitor-analytics-core.js']:
        text = path.read_text(encoding='utf-8')
        assert 'VISITOR_ANALYTICS_TOKEN' not in text
        assert not re.search(r'Bearer\\s+[A-Za-z0-9_-]{24,}', text)


def test_tracker_never_reads_form_values_or_fingerprints():
    text = (ROOT / 'assets/visitor-analytics.js').read_text(encoding='utf-8')
    forbidden = ['.value', 'FormData', 'navigator.userAgent', 'screen.width', 'canvas', 'WebGL', 'document.cookie']
    for token in forbidden:
        assert token not in text, token
    assert 'localStorage' in text
    assert 'consent' in text.lower()
    assert 'target.textContent' not in text
    assert "closest('form')" in text
    assert 'page_location: `${window.location.origin}${Core.canonicalPage(window.location.href)}`' in text
    assert "page_referrer: ''" in text
    assert 'allow_google_signals: false' in text
    assert 'allow_ad_personalization_signals: false' in text


def test_endpoint_uses_dedicated_analytics_database():
    source = (ROOT / 'visitor_analytics.php').read_text(encoding='utf-8')
    assert '$pdo = visitor_db();' in source
    assert '$pdo = rental_db();' not in source
    assert 'rental_db()' not in source
    assert 'visitor_drop_legacy_tables' not in source
    assert 'rental_allowed_origins()' not in source
    assert 'visitor_allowed_origins()' in source
    common = (ROOT / 'visitor_analytics_common.php').read_text(encoding='utf-8')
    assert "return ['https://ardirentservice.com', 'https://www.ardirentservice.com'];" in common
    assert 'Access-Control-Allow-Credentials' not in source
    assert 'jaimeespinalpr.github.io' not in source


def test_legacy_cleanup_runs_only_as_cli_deployment_migration():
    workflow = (ROOT / '.github/workflows/deploy-scp.yml').read_text(encoding='utf-8')
    migration = (ROOT / 'visitor_analytics_migrate.php').read_text(encoding='utf-8')
    assert "--include 'visitor_analytics_migrate.php'" in workflow
    assert 'php visitor_analytics_migrate.php' in workflow
    assert "PHP_SAPI !== 'cli'" in migration
    assert "is_file(__DIR__ . '/data/rentals.sqlite')" in migration
    assert 'visitor_drop_legacy_tables(rental_db())' in migration
