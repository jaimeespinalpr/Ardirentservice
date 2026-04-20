# Ardi Rent & Service LLC

Static landing page for camera rentals, audiovisual production, photography, video, podcast, and live streaming.

## Structure

- `index.html` - main page
- `assets/styles.css` - visual system
- `assets/app.js` - small interaction script

## Next steps

1. Replace the placeholder contact links with real WhatsApp, email, and booking details.
2. Add product photos, production portfolio images, and any pricing structure you want visible.
3. If the site needs a booking or deposit flow, Stripe Checkout now lives in `stripe-checkout.php`. Set these server values on Hostinger:
   - `STRIPE_SECRET_KEY`
   - `STRIPE_DEPOSIT_AMOUNT_CENTS`
   - `STRIPE_CURRENCY` (`usd` by default)
   - `STRIPE_DEPOSIT_LABEL` (optional)
   - `STRIPE_SUCCESS_URL` (optional)
   - `STRIPE_CANCEL_URL` (optional)
4. For Hostinger auto-deploy, add these GitHub Secrets and keep pushing to `codex/ardi-site`:
   - `HOSTINGER_SSH_HOST`
   - `HOSTINGER_SSH_PORT` (usually `22`)
   - `HOSTINGER_SSH_USER`
   - `HOSTINGER_SSH_KEY`
   - `HOSTINGER_REMOTE_PATH` (`/home/u467534899/domains/ardirentservice.com/public_html/`)
5. GitHub Pages deployment has been removed so the repo only publishes to Hostinger.
<!-- Trigger deployment to Hostinger -->
