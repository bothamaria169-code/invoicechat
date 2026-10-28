# InvoiceChat: setup guide (logins, social apps, AI, payments)

Follow this top to bottom once the site is live at **https://invoicechat.swiftfusion.co.za**.
Each step says where you do it:

- 🟦 **ACCOUNT**: on Google, Meta, TikTok, Groq or OTT (their websites)
- 🟩 **ADMIN**: in InvoiceChat at `https://invoicechat.swiftfusion.co.za/admin/`
- 🟧 **CPANEL**: in your hosting control panel

> Do every step on the **live** site with **https** on. Google, Meta and TikTok won't send people back to `localhost` or plain `http`.

## Every URL you'll paste

| Where | Field | Paste exactly |
|---|---|---|
| Google Cloud → Clients | Authorised JavaScript origin | `https://invoicechat.swiftfusion.co.za` |
| Google Cloud → Clients | Authorised redirect URI (sign-in) | `https://invoicechat.swiftfusion.co.za/auth/google.php` |
| Google Cloud → Clients | Authorised redirect URI (YouTube) | `https://invoicechat.swiftfusion.co.za/admin/youtube-connect.php` |
| Google / Meta / TikTok | Privacy policy | `https://invoicechat.swiftfusion.co.za/privacy.php` |
| Google / Meta / TikTok | Terms of service | `https://invoicechat.swiftfusion.co.za/terms.php` |
| Meta → Facebook Login for Business | Valid OAuth Redirect URIs | `https://invoicechat.swiftfusion.co.za/admin/meta-connect.php` |
| Meta → App settings → Basic | App domain | `invoicechat.swiftfusion.co.za` |
| Meta → App settings → Basic | Data deletion instructions URL | `https://invoicechat.swiftfusion.co.za/privacy.php` |
| TikTok → Login Kit (sandbox) | Redirect URI | `https://invoicechat.swiftfusion.co.za/admin/tiktok-connect.php` |
| TikTok → Basic info | Website URL | `https://invoicechat.swiftfusion.co.za` |

The admin pages show each of these with a **copy** button, so you can copy from there too.

---

## 0. Before you start (🟧 CPANEL, 5 min)

1. **Domains** → `invoicechat.swiftfusion.co.za` → switch **Force HTTPS Redirect** to **On**.
2. **SSL/TLS Status** → make sure the subdomain has a valid certificate (press **Run AutoSSL** if not).
3. **Cron Jobs** → **Once Per Five Minutes** → command:
   ```bash
   /usr/local/bin/php /home/swiftfusionco/invoicechat.swiftfusion.co.za/cron.php >/dev/null 2>&1
   ```
   This sends scheduled social posts and campaign emails.
4. **Email Deliverability** → `swiftfusion.co.za` → SPF and DKIM must say **Valid**. Press **Repair** if not. Without this, sign-up copies, resets and campaign emails land in spam.

## 1. Email (🟩 ADMIN → Settings → Email delivery, 3 min)

1. 🟧 cPanel → **Email Accounts** → `admin@swiftfusion.co.za` → **Connect Devices**. Note the outgoing server (usually `mail.swiftfusion.co.za`, port **465**).
2. 🟩 Fill in host, port 465, username `admin@swiftfusion.co.za` and the mailbox password → **Save** → **Send test email**.

Every new sign-up is now copied to `admin@swiftfusion.co.za`. To send copies elsewhere: **Settings → Notifications**.

## 2. Google sign-in (10 min)

1. 🟦 https://console.cloud.google.com → project picker → **New project** → `InvoiceChat` → **Create**, and select it.
2. 🟦 **Google Auth Platform → Branding** → **Get started**:
   - App name `InvoiceChat`, support email `admin@swiftfusion.co.za`, Audience **External**, contact email `admin@swiftfusion.co.za` → **Create**.
   - Then fill in home page `https://invoicechat.swiftfusion.co.za`, privacy and terms URLs (table above), authorised domain `swiftfusion.co.za`, and upload `assets/img/mark.svg` (converted to PNG) as the logo → **Save**.
3. 🟦 **Audience** → **Publish app** → **Confirm**. Sign-in only asks for name and email, so it goes live without Google review.
4. 🟦 **Clients** → **Create client** → **Web application**, name `InvoiceChat web`:
   - Authorised JavaScript origins: `https://invoicechat.swiftfusion.co.za`
   - Authorised redirect URIs: add **both** `…/auth/google.php` and `…/admin/youtube-connect.php` (table above).
   - **Create** → copy the **Client ID** and **Client secret**.
5. 🟩 **Settings → Google sign-in** → paste both → tick **Show the Google button** → **Save**.
6. Test in a private window: **Sign in → Continue with Google**.

## 3. YouTube Shorts (5 min, uses the same Google project)

1. 🟦 Google Cloud → **APIs & Services → Library** → search **YouTube Data API v3** → **Enable**.
2. 🟦 **Google Auth Platform → Data access** → **Add or remove scopes** → tick `.../auth/youtube.upload` and `.../auth/youtube.readonly` → **Update** → **Save**.
3. 🟦 **Audience → Test users** → add the Gmail address that owns the InvoiceChat YouTube channel.
4. 🟩 **Social & ads → Connections → Connect YouTube** → sign in with that Gmail → choose the channel. If Google warns "unverified app", choose **Advanced → Go to InvoiceChat** (it's your own app).
5. Post: **Social & ads → New post** → upload a vertical MP4 under 60 s → tick **YouTube Shorts**.

> While the upload scope is unverified, Google may ask you to reconnect about once a week. To stop that, submit the app for verification under **Verification centre** (free; needs a short screen recording of the connect flow).

## 4. Meta: Facebook Page + Instagram posting (20 min)

1. 🟦 Make sure you're an admin of the InvoiceChat Facebook Page, and that the Instagram account is **Professional → Business** and linked to the Page (Meta Business Suite → Settings → Accounts → Instagram).
2. 🟦 https://developers.facebook.com/apps → **Create app** → use case **Manage everything on your Page** → name `InvoiceChat Poster` → **Create**.
3. 🟦 **App settings → Basic**: app domain, privacy URL, data deletion URL (table above), category **Business and pages** → **Save**. Copy **App ID** and **App secret**.
4. 🟦 **Facebook Login for Business → Settings → Valid OAuth Redirect URIs** → `…/admin/meta-connect.php` → **Save**.
5. 🟦 **Use cases → Customise** → permissions `pages_show_list`, `pages_manage_posts`, `pages_read_engagement`, `instagram_basic`, `instagram_content_publish`, `business_management`.
6. 🟩 **Social & ads → Connections** → paste App ID + secret → **Save keys** → **Connect with Facebook** → tick the Page and Instagram → allow.
7. 🟦 Switch the app to **Live** (top bar). Meta may ask for **Business verification** first: upload the SwiftFusion CIPC certificate.

## 5. Meta Pixel + Conversions API (ads tracking, 10 min)

1. 🟦 https://business.facebook.com → **Events Manager** → **Connect data → Web** → name `InvoiceChat` → choose **Conversions API and Meta Pixel** → copy the **Dataset (Pixel) ID**.
2. 🟦 Same dataset → **Settings → Conversions API → Generate access token** → copy it.
3. 🟩 **Social & ads → Pixels & ads tracking** → paste both → **Save tracking**.
4. 🟦 Events Manager → **Test events** → copy the test code → 🟩 paste it in **Test event code** → **Send a test sign-up event** → you should see `CompleteRegistration` in Meta. Then **clear** the test code.
5. 🟦 Events Manager → **Settings → Domains** → add `swiftfusion.co.za` and verify it (Meta gives a DNS TXT record → 🟧 cPanel **Zone Editor**).

## 6. TikTok: posting drafts + Pixel (25 min)

**Posting (Content Posting API)**
1. 🟦 https://developers.tiktok.com → log in with the InvoiceChat TikTok account → **Manage apps → Connect an app** → `InvoiceChat`.
2. 🟦 Switch to **Sandbox → Create sandbox** → fill in icon, category *Business*, description, terms + privacy URLs, platform **Web** with the website URL.
3. 🟦 **Add products**: **Login Kit** (redirect URI `…/admin/tiktok-connect.php`) and **Content Posting API** (leave Direct Post off). Scopes: `user.info.basic`, `video.upload`.
4. 🟦 **Sandbox settings → Target users** → add the InvoiceChat TikTok account.
5. 🟩 **Social & ads → Connections** → paste the sandbox **Client key** + **Client secret** → **Save keys** → **Connect TikTok** → authorise.
6. Videos you post arrive in the TikTok app inbox as drafts: open → add a trending sound → **Post**.

**Pixel + Events API**
1. 🟦 https://ads.tiktok.com → **Tools → Events → Web events → Set up web events** → **Manually set up** → **TikTok Pixel + Events API** → copy the **Pixel code**.
2. 🟦 Pixel → **Settings → Events API → Generate access token**.
3. 🟩 **Pixels & ads tracking** → paste both → **Save** → **Send a test sign-up event** (with the TikTok test code while checking).

## 7. Google Analytics 4 (5 min, optional but recommended)

1. 🟦 https://analytics.google.com → **Admin → Create property** `InvoiceChat` → Web stream `https://invoicechat.swiftfusion.co.za` → copy the **Measurement ID** (`G-…`).
2. 🟩 **Pixels & ads tracking → Measurement ID** → **Save**. Sign-ups show as the `sign_up` event.
3. 🟦 https://search.google.com/search-console → add the subdomain so Google indexes the landing page.

## 8. AI assistant: Groq (free, 2 min)

1. 🟦 https://console.groq.com → sign up (Google works) → **API Keys → Create API Key** → `InvoiceChat` → copy (`gsk_…`).
2. 🟩 **Settings → AI assistant** → paste → model **Llama 3.3 70B** → **Save** → **Test the key**.

What it powers:
- **Describe it**: users type *"Invoice Acme for 3 logos at R500 each, 15% VAT, due in 14 days"* and get a ready draft.
- **AI logo ideas**: symbol ideas from Groq, images from Pollinations (free, no key).

Free limits are roughly 1,000 requests a day on 70B and about 14,000 on Llama 3.1 8B. Per-user monthly limits: **Payments → Prices & plans**. If you ever outgrow Groq, Google **Gemini 2.0 Flash** (free tier in Google AI Studio) or **OpenRouter** free models are drop-in alternatives.

## 9. Payments with OTT vouchers

1. 🟦 Register as an **OTT merchant** (https://www.ott.co.za, *Merchants*) so you can **redeem** voucher PINs in their portal or app. This is how you verify a voucher is real and unused.
2. 🟩 **Payments → Prices & plans**: check the prices (defaults: Free 5 docs/month, Pro R50/month for 100 docs, top-up R10 = 10 credits). Optionally give new sign-ups a few free credits.
3. Daily routine: 🟩 **Payments → To verify** → copy the PIN → redeem it in OTT → if it redeems for the right amount, **Approve** (credits/Pro are added and the customer is emailed). Otherwise **Reject** with a reason.

> Later, OTT's merchant API can redeem PINs automatically, so customers get credits instantly. Ask OTT for API access once volumes grow.

## 10. Leads (🟩 ADMIN → Leads & calls)

1. **Import & lists** → upload a list (start with the ones that have a business column; the page lists them). Tick **Only rows with a business name**. Try 500 rows first.
2. Files over the upload limit: upload them with File Manager to `/home/swiftfusionco/leads/` (outside the website), then 🟧 **Terminal**:
   ```bash
   php ~/invoicechat.swiftfusion.co.za/import-leads.php ~/leads/list12.xlsx "List 12" --business
   ```
3. **Call list → Fill today's list** (e.g. 30) → call with the script on each card → choose the outcome → **Save call**. Calls with no answer come back in 3 hours; "Interested" comes back in 3 days for a follow-up.
4. **Email campaigns** → start with 100–200 a day. Emailed leads join the call list the next day.
5. **Scripts & templates** → set your name as the caller.

Only names, business, job title, email, mobile, industry, town, province and website are imported. ID numbers, bank details, salaries and credit data in the files are **ignored**. Keep the original lists offline and secure.

---

## Troubleshooting

| You see | Fix |
|---|---|
| Google: `redirect_uri_mismatch` | The URI in Google Cloud must match exactly: https, no trailing slash, the `invoicechat.` subdomain. |
| Google button doesn't show | Both Client ID and secret must be saved, and **Show the Google button** ticked. |
| Facebook: "URL blocked" | Redirect URI in Facebook Login for Business must be exactly `…/admin/meta-connect.php`. |
| Instagram "not linked" | Link Instagram to the Page in Business Suite, then **Reconnect**. |
| TikTok `redirect_uri` error | Save the redirect URI inside the **sandbox**, not the production app. |
| YouTube "access blocked" | Add your Gmail under **Audience → Test users**, and enable YouTube Data API v3. |
| Pixel events don't arrive | Clear browser ad blockers to test the pixel; server events show under **Recent server events**. |
| Emails in spam | cPanel → Email Deliverability → fix SPF/DKIM, and lower campaign speed. |
| Scheduled posts/campaigns never send | The cron job isn't running. **Social & ads → Connections** shows when it last ran. |
