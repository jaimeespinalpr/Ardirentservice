from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def read(name: str) -> str:
    path = ROOT / name
    assert path.exists(), f"missing {name}"
    return path.read_text(encoding="utf-8")


def normalized(text: str) -> str:
    return re.sub(r"\s+", " ", text.lower())


def test_supabase_adapter_reads_all_configuration_from_environment():
    php = read("supabase_common.php")
    lowered = php.lower()
    for key in (
        "SUPABASE_URL",
        "SUPABASE_PUBLISHABLE_KEY",
        "SUPABASE_SECRET_KEY",
        "SUPABASE_SERVICE_ROLE_KEY",
    ):
        assert key.lower() in lowered
    assert "getenv(" in lowered
    assert "file_get_contents('.env" not in lowered


def test_supabase_adapter_sends_real_auth_headers_and_does_not_log_values():
    php = normalized(read("supabase_common.php"))
    assert "/auth/v1/user" in php
    assert "email_confirmed_at" in php
    assert "authorization: bearer " in php
    assert "apikey: " in php
    assert "error_log('supabase bearer validation failed')" in php
    assert "supabase request failed" in php
    assert "error_log($token" not in php
    assert "error_log($serverkey" not in php


def test_supabase_cors_allowlist_contains_only_official_origins():
    php = normalized(read("supabase_common.php"))
    assert "https://ardirentservice.com" in php
    assert "https://www.ardirentservice.com" in php
    assert "https://jaimeespinalpr.github.io" not in php
    assert "access-control-allow-origin" in php
    assert "access-control-allow-credentials" in php
    assert "access-control-allow-headers" in php
    assert "authorization" in php


def test_public_config_endpoint_exposes_only_publishable_values_without_opening_db():
    api = normalized(read("accounts_api.php"))
    marker = "if ($method === 'get' && $action === 'config')"
    assert marker in api
    block = api.split(marker, 1)[1].split("$pdo = rental_db()", 1)[0]
    assert "supabase_url" in block
    assert "supabase_publishable_key" in block
    assert "supabase_secret_key" not in block
    assert "supabase_service_role_key" not in block
    assert "rental_db()" not in block


def test_checkout_auth_is_supabase_only_when_backend_selected():
    php = normalized(read("rentals_checkout.php"))
    assert "account_backend" in php
    assert "supabase_validate_bearer" in php
    assert "supabase_reserve_welcome_discount" in php
    assert "supabase_release_welcome_discount" in php
    assert "checkout_intent_id" in php


def test_confirm_requires_successful_discount_consumption():
    php = normalized(read("rentals_confirm.php"))
    assert "rental_finalize_checkout_intent" in php
    common = normalized(read("rentals_common.php"))
    assert "supabase_consume_welcome_discount" in common
    assert "if (!$consumed)" in common
    assert "checkout_intent_mismatch" in common
    assert "checkout_currency_mismatch" in common
    assert "checkout_amount_mismatch" in common
