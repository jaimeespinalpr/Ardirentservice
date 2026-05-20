# Ardi Rent & Service LLC

Camera rentals, audiovisual production, photography, video, podcast, and live streaming.

## Structure

- `index.html` - main page
- `assets/styles.css` - visual system
- `assets/app.js` - interaction script
- `rentals_*.php` - checkout flow (deployed to pay.ardirentservice.com)

## Deploy

Push to `main` auto-deploys via GitHub Actions:
- Static site → `ardirentservice.com` (public_html/)
- PHP checkout → `pay.ardirentservice.com` (pay/)

## GitHub Secrets required

- `HOSTINGER_SSH_HOST`
- `HOSTINGER_SSH_PORT`
- `HOSTINGER_SSH_USER`
- `HOSTINGER_SSH_KEY`
- `STRIPE_SECRET_KEY`
- `STRIPE_MODE`
- `RENTAL_ADMIN_TOKEN` - protects the reservation admin page at `rentals_admin.php`
- `RENTAL_SMTP_HOST` - optional authenticated SMTP host, for example `smtp.titan.email`
- `RENTAL_SMTP_PORT` - optional SMTP port, for example `587`
- `RENTAL_SMTP_USERNAME` - optional SMTP username, usually the full mailbox address
- `RENTAL_SMTP_PASSWORD` - optional SMTP password or app password
- `RENTAL_SMTP_ENCRYPTION` - optional SMTP encryption, usually `starttls`

## Admin

- Open `https://pay.ardirentservice.com/rentals_admin.php?token=YOUR_TOKEN`
- The page lists reservations stored in `data/rentals.sqlite`
- Use the preview panel to see the exact customer confirmation email body
- Use the fulfillment dropdown to mark orders as `pending`, `confirmed`, `ready`, `delivered`, `completed`, or `cancelled`
