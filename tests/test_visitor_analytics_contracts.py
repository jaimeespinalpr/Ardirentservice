import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[1]
HTML = sorted(ROOT.glob('*.html'))


def test_every_public_html_uses_consent_tracker_and_no_unconditional_ga():
    assert len(HTML) == 10
    for path in HTML:
        text = path.read_text(encoding='utf-8')
        assert 'assets/visitor-analytics.js?v=20260727-v19' in text, path.name
        assert '<script async src="https://www.googletagmanager.com/gtag/js' not in text, path.name


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
