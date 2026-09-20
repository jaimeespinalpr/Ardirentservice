# Security policy

## Report a vulnerability

Do not open a public issue containing customer data, credentials, payment details, or an exploitable proof of concept. Contact the repository owner privately with the affected endpoint, reproduction steps, impact, and a proposed mitigation if available.

## Trust boundaries

- `ardirentservice.com` / `www.ardirentservice.com`: public static website only.
- `pay.ardirentservice.com`: PHP API, checkout, account sessions, and administrative interface.
- `assets/data/`: public JSON required by browser code.
- `data/`: private runtime storage. Direct HTTP access must remain denied.
- Stripe: payment authority. A browser redirect alone is not proof of payment.

## Operational requirements

- Keep `STRIPE_SECRET_KEY`, `RENTAL_ADMIN_TOKEN`, SMTP credentials, and analytics tokens in GitHub/hosting secrets only.
- Use a long, randomly generated `RENTAL_ADMIN_TOKEN`; rotate it after suspected exposure.
- Never place the administrative token in URLs, screenshots, logs, tickets, or email links.
- Keep the pay-site `.env` mode `0600` and the backend `data/` directory non-public.
- Review GitHub Actions changes carefully because deployment workflows can access production secrets.

## Known architectural follow-up

The rental system currently finalizes paid reservations through the Stripe return flow. A signed Stripe webhook with a shared idempotent finalizer remains the recommended next reliability improvement so a reservation is finalized even when a customer closes the browser before returning from Stripe.