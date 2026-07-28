import importlib.util
from pathlib import Path

MODULE_PATH = Path(__file__).resolve().parents[1] / 'scripts' / 'ardi_visitor_monitor.py'
spec = importlib.util.spec_from_file_location('ardi_visitor_monitor', MODULE_PATH)
assert spec is not None and spec.loader is not None
monitor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(monitor)


def test_duration_formatting():
    assert monitor.format_duration(0) == '0s'
    assert monitor.format_duration(65_000) == '1m 5s'
    assert monitor.format_duration(3_665_000) == '1h 1m 5s'


def test_summary_has_privacy_limit_and_page_times():
    message = monitor.format_summary({
        'session_id': '123e4567-e89b-42d3-a456-426614174000',
        'total_active_ms': 65_000,
        'pages': [{'page_path': '/equipment.html', 'active_ms': 65_000, 'views': 2}],
        'clicks': [{'page_path': '/equipment.html', 'target_type': 'button', 'target_label': 'Rent now', 'click_count': 1}],
    })
    assert '1m 5s' in message
    assert '/equipment.html' in message
    assert 'Rent now' in message
    assert 'no se recopilaron nombres, IP' in message
