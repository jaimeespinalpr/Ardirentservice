from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = (ROOT / "accounts_migration_audit.php").read_text()


def test_migration_audit_is_cli_only_and_count_only():
    assert "PHP_SAPI !== 'cli'" in SCRIPT
    assert "SELECT COUNT(*)" in SCRIPT
    assert "customer_accounts" in SCRIPT
    assert "reservations" in SCRIPT
    for forbidden in (
        "password_hash",
        "SELECT email",
        "SELECT full_name",
        "SELECT phone",
        "SELECT *",
    ):
        assert forbidden not in SCRIPT


def test_migration_audit_uses_fixed_table_allowlist():
    assert "$allowedTables" in SCRIPT
    assert "$_GET" not in SCRIPT
    assert "$_POST" not in SCRIPT
    assert "argv" not in SCRIPT.lower()
