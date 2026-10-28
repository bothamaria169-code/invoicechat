# InvoiceChat

Create branded PDF invoices and quotes by chatting, then send them by email or WhatsApp.
PHP 8.1+ · MySQL/MariaDB · no build step.

| Area | Where |
|---|---|
| Landing page | `index.php` |
| Sign in / sign up / reset password / Google | `login.php`, `register.php`, `forgot-password.php`, `reset-password.php`, `auth/google.php` |
| Chat app | `chat.php`, `assets/js/chat.js`, `ajax/`, `bot/` |
| Admin dashboard | `admin/` (Dashboard, Users, Invoices & quotes, Marketing, Settings, My account) |
| Marketing | `admin/marketing.php`, `classes/MetaPoster.php`, `classes/TikTok.php`, `includes/tracking.php` |
| Database schema | `database/migrate.php` — runs itself on the first page load after a deploy |

## Deploy (cPanel + GitHub)

1. **🟧 cPanel → Git™ Version Control → Create** → Clone URL `https://github.com/bothamaria169-code/invoicechat.git`, repository path `/home/swiftfusionco/repositories/invoicechat`.
2. Check the folder in `.cpanel.yml` (`/home/swiftfusionco/public_html/invoicechat/`) is where the site lives. Edit it if not.
3. **Manage → Pull or Deploy → Update from Remote → Deploy HEAD Commit.**
4. **First time only:** with File Manager, upload `config/secrets.php` (copy `config/secrets.example.php` and fill it in). It holds the database password, the admin seed password and the mailbox password, and is never in git.
5. Open the site once. Missing tables and columns are created automatically, and the admin account `admin@swiftfusion.co.za` is created from `admin_seed_password` if it doesn't exist.
6. **cPanel → Cron Jobs** → every 5 minutes:
   ```bash
   /usr/local/bin/php /home/swiftfusionco/public_html/invoicechat/cron.php >/dev/null 2>&1
   ```

Afterwards, every change is: push to GitHub → cPanel **Update from Remote** → **Deploy HEAD Commit**.

> cPanel normally prefixes MySQL usernames with the account name (e.g. `swiftfusionco_yourname`). If the site says it can't connect, check the exact username under **MySQL Databases** and fix `db_user` in `secrets.php`.

## After deploying — switch things on (Admin → …)

- **Settings → Email delivery:** mailbox password for `admin@swiftfusion.co.za` → **Send test email**. Without it the site uses PHP `mail()`, which often lands in spam.
- **Settings → Google sign-in:** Client ID + secret from Google Cloud (steps and the exact redirect URI are on that page).
- **Marketing → Pixels & ads tracking:** Meta Dataset ID + Conversions API token, TikTok Pixel code + Events API token, GA4 ID. Then **Send a test sign-up event**.
- **Marketing → Connections:** Meta app (Facebook Page + Instagram) and TikTok sandbox app, then **Connect**.

## What happens on sign-up

Email or Google, every new account:
1. gets a welcome email;
2. is copied to `admin@swiftfusion.co.za` (or the address in Settings → Notifications) with name, method, ad source and IP;
3. is reported to Meta and TikTok as `CompleteRegistration` from both the browser pixel and the server API with one shared event id (they de-duplicate);
4. is saved with its first-touch source (`utm_*`, `fbclid`, `ttclid`, referrer), shown on the Dashboard.

## Security notes

- Secrets live only in `config/secrets.php`; `.htaccess` blocks `config/`, `includes/`, `database/`, `vendor/`, SQL dumps and credential notes.
- PDFs are no longer public: `/pdfs/` is blocked and documents open through `pdf.php` with a signed link. File names carry the user id plus a random tag, so users can't overwrite or guess each other's files.
- CSRF tokens on every form and AJAX POST; sign-in is rate-limited (6 failures / 15 min per email, 25 per IP); password reset links are single-use, hashed in the database and expire after 60 minutes.

## Local development

XAMPP at `http://localhost/Invoicechat`. On localhost the `local_db_*` credentials from `secrets.php` are used (default `root` with no password).
