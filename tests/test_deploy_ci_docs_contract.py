from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(rel: str) -> str:
    path = ROOT / rel
    assert path.exists(), f"missing {rel}"
    return path.read_text(encoding="utf-8")


def test_deploy_workflow_transports_supabase_and_webhook_values():
    workflow = read(".github/workflows/deploy-scp.yml")
    lowered = workflow.lower()

    for fragment in (
        "SUPABASE_URL",
        "SUPABASE_PUBLISHABLE_KEY",
        "SUPABASE_SECRET_KEY",
        "SUPABASE_SERVICE_ROLE_KEY",
        "ACCOUNT_BACKEND",
        "STRIPE_SECRET_KEY",
        "STRIPE_WEBHOOK_SECRET",
        "RENTAL_SMTP_PASSWORD",
        "::add-mask::",
    ):
        assert fragment.lower() in lowered

    assert "github.ref == 'refs/heads/main'" in lowered
    assert "main" in lowered
    assert "workflow_dispatch" in lowered
    assert "scp" in lowered and ".env.pay" in lowered
    assert "--include 'supabase_common.php'" in workflow
    assert "--include 'rentals_webhook.php'" in workflow
    assert "https://jaimeespinalpr.github.io" not in workflow


def test_ci_workflow_uses_uv_php_lint_secret_scan_and_feature_triggers():
    workflow = read(".github/workflows/ci.yml")
    lowered = workflow.lower()

    assert "pull_request" in lowered
    for branch in ("feat/**", "feature/**", "bugfix/**", "hotfix/**"):
        assert branch in lowered
    assert "setup-uv" in lowered
    assert "uv run --with pytest pytest -q" in lowered
    assert "shivammathur/setup-php" in lowered
    assert "php -l" in lowered
    assert "secret scan" in lowered
    assert "run complete python pytest suite" in lowered
    assert "pytest -q tests" in lowered


def test_docs_and_example_cover_required_supabase_settings_and_webhook_secret():
    env_example = read(".env.example")
    supabase_setup = read("docs/SUPABASE_SETUP.md")
    supabase_plain = supabase_setup.replace("**", "")
    retirement = read("docs/SQLITE_ACCOUNT_RETIREMENT.md")
    readme = read("README.md")

    for fragment in (
        "SUPABASE_URL=https://your-project.supabase.co",
        "SUPABASE_PUBLISHABLE_KEY=sb_publishable_placeholder",
        "SUPABASE_SECRET_KEY=sb_secret_placeholder",
        "ACCOUNT_BACKEND=supabase",
        "SUPABASE_SERVICE_ROLE_KEY=sb_service_role_placeholder",
        "STRIPE_WEBHOOK_SECRET=whsec_placeholder",
    ):
        assert fragment in env_example

    for fragment in (
        "Confirm email: ON",
        "Site URL: `https://www.ardirentservice.com`",
        "https://ardirentservice.com/account.html?type=recovery",
        "SUPABASE_SECRET_KEY",
        "SUPABASE_SERVICE_ROLE_KEY",
        "rollback",
        "webhook",
    ):
        assert fragment.lower() in supabase_plain.lower()

    for fragment in (
        "count-only",
        "select count(*) from customer_accounts",
        "password_hash",
        "Do not migrate password hashes.",
        "data/rentals.sqlite",
        "retention",
    ):
        assert fragment in retirement

    assert "stripe webhook" in readme.lower()
