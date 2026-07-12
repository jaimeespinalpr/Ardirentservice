# SQLite account retirement plan

This document covers the safe retirement of the legacy SQLite account store. It is intentionally conservative: rental data stays preserved, and account credentials are **not** migrated by copying password hashes.

## What to audit in production

Run a **count-only** audit of the legacy account rows.

Example:

```sql
select count(*) from customer_accounts;
```

Rules for the audit:

- Count rows only.
- Do **not** select `email`, `phone`, `password_hash`, or any other identity field.
- Do **not** export or print password hashes.
- Do **not** use the SQLite table as a source of truth for new Supabase logins.

## No password-hash migration

The old SQLite `password_hash` values must stay in SQLite and must **not** be copied into Supabase Auth.

Why:

- Supabase Auth manages its own password storage.
- Copying hashes would create a fragile and unsafe migration.
- The retirement path is password reset / re-registration, not hash reuse.

## Password reset path for users

For any customer who still exists in the SQLite account table:

1. Keep the account row count as a reference only.
2. Ask the user to reset their password in Supabase Auth.
3. Send the Supabase password reset email if they already have a Supabase account.
4. If the user is still on the legacy path, let them create a fresh Supabase identity and password.
5. Verify the new Supabase identity before retiring the old account row.

The important rule is that the user keeps access through Supabase Auth, not through a copied SQLite hash.

## Retention and preservation

Keep the legacy SQLite files and rental records intact for the agreed retention window.

Must preserve:

- `data/rentals.sqlite`
- rental reservation history
- checkout and fulfillment records
- any other rental-related tables or exports

Must not preserve as active auth state:

- `customer_accounts.password_hash`
- legacy login sessions
- any SQLite-only customer identity as the live authentication source

## Retirement sequence

1. Confirm the production row count.
2. Confirm Supabase Auth is live and validated.
3. Confirm rental checkout, webhook, and confirmation flows still work.
4. Keep SQLite available as fallback while users transition.
5. After the retention window, archive the old account table if needed, but keep rental data untouched.

## Do not do these things

- Do not migrate password hashes.
- Do not delete rental tables while retiring accounts.
- Do not use the count audit to infer customer emails or passwords.
- Do not remove the SQLite fallback until the Supabase rollout is explicitly complete.
