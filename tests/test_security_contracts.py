from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def source(name: str) -> str:
    return (ROOT / name).read_text(encoding="utf-8")


def test_admin_uses_session_auth_and_csrf_not_query_string_master_token():
    admin = source("rentals_admin.php")
    auth = source("admin_auth.php")
    assert "admin_require_authenticated" in admin
    assert "admin_require_csrf" in admin
    assert "$_GET['token']" not in admin
    assert "session_regenerate_id(true)" in auth
    assert "'samesite' => 'Strict'" in auth
    assert "$_SERVER['REMOTE_ADDR']" in auth
    assert "HTTP_HOST" not in auth
    assert "'secure' => !admin_is_local_request()" in auth


def test_return_actions_are_signed_scoped_and_post_only():
    common = source("rentals_common.php")
    endpoint = source("rentals_return.php")
    assert "hash_hmac('sha256'" in common
    assert "rental_verify_return_action" in endpoint
    assert "REQUEST_METHOD" in endpoint
    assert "method_not_allowed" in endpoint
    assert "$_GET['token']" not in endpoint
    assert "$_POST['signature']" in endpoint


def test_checkout_uses_configured_pay_host_and_confirmation_binds_amount_currency():
    checkout = source("rentals_checkout.php")
    confirm = source("rentals_confirm.php")
    assert "rental_pay_site_url()" in checkout
    assert "rental_base_url() . '/rentals_confirm.php" not in checkout
    assert "rental_checkout_session_matches" in confirm
    assert "amount_total" in source("rentals_common.php")
    assert "currency" in source("rentals_common.php")


def test_public_deploy_is_separated_from_backend_and_development_files():
    workflow = source(".github/workflows/deploy-scp.yml")
    for exclusion in [
        "--exclude '*.php'",
        "--exclude 'data/'",
        "--exclude 'tests/'",
        "--exclude 'scripts/'",
        "--exclude 'backups/'",
        "--exclude 'docs/'",
        "--exclude 'deploy/'",
    ]:
        assert exclusion in workflow
    assert "deploy/pay/.htaccess" in workflow
    assert "RENTAL_ADMIN_TOKEN=${RENTAL_ADMIN_TOKEN}" in workflow
    assert "HOSTINGER_SSH_KNOWN_HOSTS" in workflow
    assert "StrictHostKeyChecking=no" not in workflow
    assert "UserKnownHostsFile=/dev/null" not in workflow


def test_public_print_catalog_lives_under_assets_not_protected_backend_data():
    prints = source("prints.html")
    checkout = source("prints_checkout.php")
    assert 'fetch("assets/data/prints_metadata.json"' in prints
    assert "'/assets/data/prints_metadata.json'" in checkout
    assert (ROOT / "assets/data/prints_metadata.json").is_file()
    assert (ROOT / "assets/data/instagram_latest.json").is_file()
    assert not (ROOT / "data/prints_metadata.json").exists()
    assert '"assets/data/instagram_latest.json"' in source("assets/app.js")


def test_sensitive_runtime_files_are_ignored_and_pay_hardening_exists():
    gitignore = source(".gitignore")
    pay_htaccess = source("deploy/pay/.htaccess")
    assert "data/*.sqlite" in gitignore
    assert ".env" in gitignore
    assert "Options -Indexes" in pay_htaccess
    assert "Require all denied" in pay_htaccess
    assert "Referrer-Policy" in pay_htaccess
