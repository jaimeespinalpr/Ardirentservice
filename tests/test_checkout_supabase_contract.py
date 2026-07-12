from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(name: str) -> str:
    path = ROOT / name
    assert path.exists(), f"missing {name}"
    return path.read_text().lower()


def test_checkout_uses_opaque_local_intents_and_safe_stripe_metadata_only():
    php = text("rentals_checkout.php")
    assert "checkout_intent_id" in php
    assert "rental_create_checkout_intent" in php
    assert "metadata[checkout_intent_id]" in php
    assert "metadata[account_backend]" in php
    assert "metadata[customer_name]" not in php
    assert "metadata[customer_email]" not in php
    assert "metadata[customer_phone]" not in php
    assert "metadata[item_ids]" not in php
    assert "metadata[item_titles]" not in php
    assert "metadata[supabase_user_id]" not in php
    assert "metadata[supabase_reservation_token]" not in php
    assert "metadata[welcome_discount_token]" not in php
    assert "metadata[account_id]" not in php


def test_checkout_release_path_clears_local_and_remote_holds_on_creation_failure():
    php = text("rentals_checkout.php")
    assert "rental_release_checkout_intent" in php
    assert "stripe_checkout_failed" in php
    assert "missing_checkout_url" in php
    assert "checkout_session_id" in php
    assert "account_backend" in php
    common = text("rentals_common.php")
    assert "supabase_release_welcome_discount" in common
    assert "account_release_discount" in common


def test_confirm_and_webhook_share_idempotent_finalization_logic():
    confirm = text("rentals_confirm.php")
    webhook = text("rentals_webhook.php")

    assert "rental_finalize_checkout_intent" in confirm
    assert "rental_finalize_checkout_intent" in webhook
    assert "checkout.session.completed" in webhook
    assert "checkout.session.expired" in webhook
    assert "stripe-signature" in webhook
    assert "stripe_webhook_secret" in webhook
    common = text("rentals_common.php")
    assert "checkout_webhook_events" in common
    assert "supabase_consume_welcome_discount" in common
    assert "rental_release_checkout_intent" in webhook
