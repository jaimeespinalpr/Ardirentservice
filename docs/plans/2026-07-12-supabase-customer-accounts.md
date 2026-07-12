# Supabase Customer Accounts Implementation Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Replace customer password/account storage in SQLite with Supabase Auth and PostgreSQL while preserving guest rentals, existing reservation data, and a reversible SQLite fallback until production verification is complete.

**Architecture:** The static frontend uses the official `@supabase/supabase-js` client with a publishable key loaded from a public backend configuration endpoint. The PHP backend validates bearer tokens through Supabase Auth, then uses a server-only secret key to call narrowly granted PostgreSQL RPC functions for discount reservation/release/consume. A feature flag keeps the existing SQLite account backend available during rollout; `data/rentals.sqlite` and rental records are never deleted or migrated by this change.

**Tech Stack:** Static HTML/CSS/JavaScript, Supabase Auth, PostgreSQL/RLS, PHP 8, Stripe Checkout/Webhooks, GitHub Actions, Hostinger.

---

## Security invariants

- No Supabase secret/service key in HTML, JavaScript, Git, Stripe metadata, logs, or responses.
- User identity comes only from a verified Supabase access token, never a submitted `user_id`.
- Stripe metadata contains only reservation/user/token identifiers required for idempotent completion.
- Price, eligibility, and discount amount are computed server-side.
- Guest checkout remains available and receives no welcome discount.
- SQLite account data stays untouched until a separate, verified retirement procedure.
- No production payment tests; Stripe Test Mode only.
- No migration is applied to a Supabase project until the correct Ardi Rent & Service project is explicitly identified.

## Task 1: Baseline and migration inventory

**Files:** inspect `accounts_common.php`, `accounts_api.php`, `rentals_checkout.php`, `rentals_confirm.php`, `rentals_webhook.php`, `assets/account.js`, `account.html`, `data/rentals.sqlite` on production.

1. Confirm GitHub identity and create `feat/supabase-customer-accounts` from current `main`.
2. Count `customer_accounts` rows on production without selecting emails or password hashes.
3. Confirm reservations remain in the same SQLite file and document that they are out of migration scope.
4. Record the Supabase organization/project selected for production.

## Task 2: Add SQL verification tests first

**Files:**
- Create: `tests/test_supabase_migration.py`
- Create: `supabase/migrations/202607120001_customer_profiles_and_welcome_discount.sql`

1. Write tests that require table constraints, RLS, column privileges, safe `SECURITY DEFINER` search paths, restricted grants, trigger creation, and four atomic RPC functions.
2. Run tests and verify RED because the migration does not exist.
3. Implement the migration.
4. Run tests and verify GREEN.

## Task 3: Add secure Supabase PHP client tests first

**Files:**
- Create: `tests/test_supabase_php_contract.py`
- Create: `supabase_common.php`
- Modify: `accounts_api.php`

1. Test environment-only secret loading, exact allowlist CORS, bearer parsing, Auth `/user` validation, public config response, and absence of secrets in output.
2. Verify RED.
3. Implement a cURL-based client with timeouts and redacted errors.
4. Verify GREEN and run all tests.

## Task 4: Integrate atomic discount into Stripe checkout

**Files:**
- Modify: `rentals_checkout.php`
- Modify: `rentals_common.php`
- Modify: `rentals_confirm.php`
- Modify: `rentals_webhook.php`
- Test: `tests/test_checkout_supabase_contract.py`

1. Test that guest checkout remains unchanged.
2. Test that authenticated checkout verifies bearer identity and calls `reserve_welcome_discount` server-side.
3. Test release when Stripe session creation fails.
4. Test Stripe metadata is identifier-only and excludes service keys and customer PII.
5. Test webhook/confirmation consumes exactly once after paid status and validates user/token.
6. Test abandoned reservations expire and webhook cancellation releases when applicable.
7. Implement minimal changes behind `ACCOUNT_BACKEND=supabase` with SQLite fallback.
8. Run all tests.

## Task 5: Replace frontend auth with Supabase Auth

**Files:**
- Modify: `account.html`
- Modify: `assets/account.js`
- Modify: `assets/account.css` only as required
- Modify: `assets/app.js`
- Modify: `equipment.html`
- Modify: `index.html`
- Test: `tests/test_account_frontend_contract.py`

1. Write failing tests for official Supabase client usage, 10-character password validation, metadata fields, email verification state, duplicate/invalid credentials handling, recovery/update-password flows, persistent session handling, profile updates, marketing preference, discount state, and ES/EN strings.
2. Implement one visible panel at a time without redesigning approved UI.
3. Send access token as `Authorization: Bearer` to authenticated backend calls.
4. Verify changing language does not clear the Supabase session.
5. Run tests and responsive QA at 390×844 and desktop.

## Task 6: Deployment and secret transport

**Files:**
- Modify: `.github/workflows/deploy-scp.yml`
- Create: `.env.example`
- Create: `docs/SUPABASE_SETUP.md`
- Create: `.github/workflows/ci.yml`

1. Add placeholder-only environment documentation.
2. Add GitHub Secrets validation and transport for `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY`, and `SUPABASE_SECRET_KEY`/`SUPABASE_SERVICE_ROLE_KEY`.
3. Preserve Stripe/SMTP values and never print secret values.
4. Add PHP lint, Python contract tests, secret scan, and static checks on the feature branch and PRs.
5. Document Supabase email confirmation, authorized redirects, recovery URL, SMTP/site URL settings, migration application, and rollback.

## Task 7: Production-data-safe migration procedure

**Files:**
- Create: `docs/SQLITE_ACCOUNT_RETIREMENT.md`

1. Count legacy accounts without reading credentials.
2. If count is nonzero, require users to create/reset a Supabase password; never copy password hashes.
3. Keep `customer_accounts` and all rental tables as a temporary backup.
4. Deploy with SQLite fallback available.
5. Enable Supabase only after migration, Auth settings, secrets, and Test Mode checkout are verified.
6. Remove SQLite account code only in a later PR after an agreed retention period; never delete rental data.

## Task 8: Verification and PR

1. Run PHP lint in GitHub Actions because local PHP is unavailable.
2. Run all contract/security tests.
3. Apply the migration only to the explicitly selected Ardi project.
4. Run the 25-item acceptance checklist using temporary Test Mode users and delete them afterward.
5. Capture mobile and desktop screenshots locally and against production after an approved deployment.
6. Scan Git history/diff for secrets.
7. Request independent spec and security review.
8. Push branch and create a detailed Pull Request; do not merge automatically.
