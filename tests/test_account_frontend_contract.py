from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
ACCOUNT_HTML = ROOT / "account.html"
ACCOUNT_JS = ROOT / "assets" / "account.js"
ACCOUNT_CSS = ROOT / "assets" / "account.css"
APP_JS = ROOT / "assets" / "app.js"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def normalized(path: Path) -> str:
    return re.sub(r"\s+", " ", read(path))


def test_account_page_loads_official_supabase_client_and_keeps_page_minimal():
    html = normalized(ACCOUNT_HTML)
    assert "https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2/dist/umd/supabase.js" in html
    assert "data-account-panel=\"guest\"" in html
    assert html.count('data-account-panel="') == 6
    assert 'data-account-form="profile"' in html
    assert 'data-account-reset-password' in html
    assert 'data-account-benefit-state' in html


def test_account_client_bootstraps_supabase_public_config_and_persistent_session():
    js = read(ACCOUNT_JS)
    assert "action=config" in js
    assert "createClient(" in js
    assert "signUp(" in js and "emailRedirectTo" in js
    assert "full_name" in js and "phone" in js and "marketing_opt_in" in js
    assert "signInWithPassword" in js
    assert "signOut(" in js
    assert "resetPasswordForEmail" in js
    assert "updateUser" in js
    assert "customer_profiles" in js
    assert "welcome_discount_used_at" in js and "welcome_discount_reserved_at" in js
    assert "recoveryFromUrl" in js


def test_auth_copy_covers_terms_validation_and_es_en_error_states():
    js = read(ACCOUNT_JS)
    for fragment in (
        "10 characters",
        "10 caracteres",
        "accept_terms",
        "marketing_opt_in",
        "duplicate",
        "Ya existe una cuenta",
        "expired",
        "expiró",
        "invalid",
        "incorrect",
        "recovery",
    ):
        assert fragment in js


def test_checkout_posts_bearer_when_supabase_session_exists_and_keeps_guest_flow():
    js = read(APP_JS)
    assert "getSupabaseBearer" in js
    assert "Authorization" in js and "Bearer" in js
    assert 'credentials: "include"' in js


def test_styles_do_not_introduce_new_visual_panels():
    css = read(ACCOUNT_CSS)
    assert ".account-state[hidden]" in css
    assert ".account-profile-note" in css
    assert ".account-actions .button" in css
