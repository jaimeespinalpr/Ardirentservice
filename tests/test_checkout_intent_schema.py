from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def php() -> str:
    path = ROOT / "rentals_common.php"
    assert path.exists(), "missing rentals_common.php"
    return " ".join(path.read_text().lower().split())


def test_checkout_intent_schema_preserves_local_context_and_idempotency():
    code = php()
    assert "create table if not exists checkout_intents" in code
    assert "id text primary key" in code
    assert "checkout_intent_id text unique" in code
    assert "checkout_session_id text unique" in code
    assert "items_json text not null" in code
    assert "discount_cents integer not null default 0" in code
    assert "finalized_at text" in code
    assert "released_at text" in code
    assert "email_sent_at text" in code
    assert "customer_email_sent_at text" in code
    assert "admin_email_sent_at text" in code
    assert "create table if not exists checkout_webhook_events" in code
    assert "event_id text primary key" in code
    assert "webhook processing" not in code


def test_checkout_common_exposes_shared_finalize_and_release_helpers():
    code = php()
    for fragment in (
        "function rental_create_checkout_intent",
        "function rental_get_checkout_intent",
        "function rental_finalize_checkout_intent",
        "function rental_release_checkout_intent",
        "function rental_record_checkout_webhook_event",
        "function rental_verify_stripe_webhook_signature",
    ):
        assert fragment in code
