# Supabase setup for Ardi Rent & Service

This checklist is for the **exact production project only**. Do **not** apply the migration until the correct Supabase project has been explicitly identified and approved.

## 1) Supabase Auth settings

Set the following in **Project Settings → Auth**:

- **Confirm email:** ON
- **Site URL:** `https://www.ardirentservice.com`
- **Additional redirect URLs:**
  - `https://www.ardirentservice.com`
  - `https://ardirentservice.com/account.html`
  - `https://www.ardirentservice.com/account.html`
  - `https://ardirentservice.com/account.html?type=recovery`
  - `https://www.ardirentservice.com/account.html?type=recovery`


If the Supabase dashboard asks for a recovery callback, use the same account page with `?type=recovery` so the reset email can return users to the account screen without leaking them to an unrelated route.

Recommended redirect allowlist summary:
- `https://ardirentservice.com/account.html?type=recovery`
- `https://www.ardirentservice.com/account.html?type=recovery`

## 2) Apply the database migration

The migration file is:

- `supabase/migrations/202607120001_customer_profiles_and_welcome_discount.sql`

Apply it only after the project has been identified.

Recommended order:

1. Review the migration in the SQL editor.
2. Confirm the project contains the intended `auth.users` tenant.
3. Run the migration in Supabase SQL Editor or via the CLI after linking the project.
4. Verify `public.customer_profiles`, the RLS policies, and the `reserve_welcome_discount` / `release_welcome_discount` / `consume_welcome_discount` / `expire_abandoned_welcome_discounts` RPC functions exist.

Example CLI flow if you are working from a local machine with the Supabase CLI:

```bash
supabase link --project-ref <explicit-project-ref>
supabase db push
```

Do **not** run `db push` against a guessed project.

## 3) Get the public and secret keys

In **Project Settings → API**:

- Copy the **Project URL** into `SUPABASE_URL`.
- Copy the **anon/public key** into `SUPABASE_PUBLISHABLE_KEY`.
- Copy the **service role key** into `SUPABASE_SECRET_KEY`.

If the deployment still uses the older secret name, set **both** of these GitHub secrets to the same service-role value:

- `SUPABASE_SECRET_KEY`
- `SUPABASE_SERVICE_ROLE_KEY`

The workflow accepts either name, but the canonical secret should be `SUPABASE_SECRET_KEY`.

## 4) GitHub secret setup

Add these repository secrets:

- `SUPABASE_URL`
- `SUPABASE_PUBLISHABLE_KEY`
- `SUPABASE_SECRET_KEY` or `SUPABASE_SERVICE_ROLE_KEY`
- `ACCOUNT_BACKEND` — set to `supabase` after the migration is approved
- Existing Hostinger and Stripe secrets must remain in place

Do not put any of these values into HTML, JavaScript, or `.env.example`.

## 5) Hostinger notes

The deploy workflow writes the pay backend environment file to Hostinger as `pay/.env`.

Important rules:

- The workflow preserves the existing Stripe and SMTP values.
- `ACCOUNT_BACKEND` is transported alongside the Supabase variables.
- Secret values are masked in the GitHub Actions logs.
- The workflow only deploys from `main`; feature branches do not deploy.
- Do not hand-edit the generated `pay/.env` unless you are performing a controlled rollback.

## Stripe webhook (required before enabling Supabase accounts)

1. In Stripe **Developers → Webhooks**, create an endpoint for `https://pay.ardirentservice.com/rentals_webhook.php`.
2. Subscribe only to `checkout.session.completed` and `checkout.session.expired`.
3. Copy the signing secret into the GitHub Actions secret `STRIPE_WEBHOOK_SECRET`; never place it in source or Supabase.
4. Keep `STRIPE_MODE=test` and an `sk_test_...` key throughout QA. Do not perform a real charge.
5. Verify one completed test Checkout creates exactly one reservation/email pair, and replaying the event does not duplicate either. Verify an expired Checkout releases the welcome-discount hold.

## Rollback

If Supabase must be disabled temporarily:

1. Set `ACCOUNT_BACKEND=sqlite` in GitHub Secrets.
2. Keep the SQLite rental database and reservation tables intact.
3. Remove or revoke the Supabase secret key in Supabase if you need to rotate access.
4. Leave the Auth settings in place until the rollback is validated.
5. Redeploy so Hostinger receives the reverted `.env`.

Rollback is config-only. Do not delete the migration or attempt to move password hashes back into SQLite.
