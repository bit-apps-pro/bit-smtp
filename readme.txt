=== Bit SMTP – Easy SMTP Solution with Email Logs ===
Contributors: bitpressadmin, akaioum
Tags: email logs, smtp, email, gmail smtp, transactional email
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later

Fix WordPress email delivery. Send through Gmail, Microsoft 365, SendGrid, Amazon SES, Brevo and more by API, OAuth, or SMTP, with fallback and logs.

== Description ==

**Easily fix WordPress email delivery. Connect Gmail, Outlook/Microsoft 365, SendGrid, Amazon SES, Mailgun, Postmark, Brevo, Cloudflare Email Sending, and more — over their fast API, secure OAuth, or classic SMTP.**

Add several sending connections, set a priority order so delivery automatically **falls back** to the next provider if one fails, see the **real delivery status** (delivered / bounced) of each email, and get **alerts** when sending fails — all free.

### Is your WordPress not sending emails, or are they going to spam?

The default PHP `mail()` function is often blocked by hosts or filtered as spam. Bit SMTP sends your WordPress email through an authenticated provider instead, so receiving mail servers can trust that it really comes from you.

### Key features

- **Dedicated provider integrations:** SendGrid, Amazon SES, Mailgun, Postmark, Brevo, Resend, Mailjet, Zoho CPaaS (ZeptoMail), SparkPost, and Cloudflare Email Sending over their own APIs — plus Gmail and Microsoft 365, any SMTP server, and PHP mail().
- **Secure OAuth for Gmail & Microsoft 365:** authorize with one click; Bit SMTP stores tokens, never your account password.
- **Multiple connections with automatic fallback:** if the top connection fails, the next one sends the email.
- **Automatic retries:** temporary failures (timeouts, rate limits) are queued and retried with backoff.
- **Smart routing:** send matching mail through a chosen connection by recipient, from address, subject, or the plugin that sent it.
- **Real delivery tracking:** provider webhooks record each email's outcome — Delivered, Bounced, Blocked, Deferred — separately from "accepted by the provider". Webhooks are set up for you when you save a supported connection.
- **Connection health checks and alerts:** get told by email, webhook, Slack, Discord, or Telegram when sending starts failing or a connection becomes unhealthy.
- **Detailed email logs:** every email with its send result, delivery status, and recipients (To/Cc/Bcc). Search, filter, preview, resend, delete, or export to CSV, with automatic clean-up after the retention period you choose.
- **Analytics dashboard:** volume, delivery outcomes, and sending trends by plugin and connection. Optional open and click tracking, off by default.
- **Encrypted credentials:** API keys and secrets are stored with AES-256-GCM, or kept out of the database entirely as `wp-config.php` constants.
- **WP-CLI:** send test emails, read and export logs, manage the retry queue, run health checks, and import from other SMTP plugins.
- **Easy switching:** import connections from WP Mail SMTP or Easy WP SMTP with WP-CLI.
- **Multisite ready**, with a dark mode and an accessible, plain-language interface.
- **Free and fully functional:** no premium plan required.

### Set up in minutes

1. **Sign up with an email provider:** Gmail, Microsoft 365, SendGrid, Amazon SES, Mailgun, Postmark, Brevo, Cloudflare Email Sending, or any SMTP server.
2. **Add a connection:** go to **Bit SMTP ▸ Connections**, click **Add connection**, and pick your provider.
3. **Enter your credentials:** paste the provider's API key (or SMTP host, port, username, and password). For Gmail and Microsoft 365, click **Connect** to authorize over OAuth.
4. **Set the sender:** the From email (an address verified with the provider), the From name, and an optional Reply-To.
5. **Send a test:** click **Test** to confirm delivery. Add more connections and drag them into a priority order for fallback.

Every field has help text that explains what it is and where to find it in your provider's dashboard.

### Supported email services

**API integrations** (paste an API key):

* **SendGrid** — great deliverability; free tier available.
* **Amazon SES** — best for large volumes; enter Access Key, Secret, and region.
* **Mailgun** — sending API key, domain, and region (US/EU).
* **Postmark** — fast transactional delivery (Server API Token).
* **Brevo (Sendinblue)** — generous free daily tier.
* **Resend** — modern developer email API.
* **Mailjet** — API Key + Secret Key.
* **Zoho CPaaS (ZeptoMail)** — transactional mail with a Send Mail Token and data center.
* **SparkPost** — Transmissions API (US/EU).
* **Cloudflare Email Sending (Beta)** — configure the sender address and domain in Cloudflare first; sending to general recipients requires a Workers Paid plan.

**OAuth integrations** (one-click Connect, no password stored): **Gmail / Google Workspace** and **Microsoft 365 / Outlook**.

**Any SMTP server** — use **Other SMTP** for your host, Yahoo, Zoho Mail, or any mailbox. Common settings:

* **Gmail SMTP:** smtp.gmail.com · TLS · 587
* **Outlook SMTP:** smtp-mail.outlook.com · TLS · 587
* **Yahoo SMTP:** smtp.mail.yahoo.com · SSL · 465
* **Zoho Mail SMTP:** smtp.zoho.com · TLS · 587

You can also use **PHP mail()** (the server's own mailer) when no external provider is configured.

### Failure alerts: email, webhook, Slack, Discord, and Telegram

Open **Bit SMTP ▸ Settings** to add alert channels. Bit SMTP sends one alert when a failure streak starts and one when it recovers, so an outage does not flood your inbox or channel.

* **Slack:** create an app with **Incoming Webhooks** and paste its `https://hooks.slack.com/services/...` URL.
* **Discord:** create a channel webhook and paste its URL.
* **Telegram:** create a bot with **@BotFather**, message it from the destination chat, then enter the bot token and that chat's numeric ID.
* **Webhook:** any HTTPS URL; requests are signed so you can verify they came from your site.

Use **Test notification** to check a saved channel. Webhook URLs and bot tokens are encrypted and shown masked. Alerts are best effort: each request has a five-second limit and can never change the outcome of the email that triggered it.

### Works with every plugin that sends email

Bit SMTP handles any email sent through WordPress's `wp_mail()` — WooCommerce, core notifications, and form plugins such as [Bit Form](https://wordpress.org/plugins/bit-form/), Contact Form 7, Gravity Forms, WPForms, Ninja Forms, Forminator, Fluent Forms, Formidable Forms, Everest Forms, Elementor Forms, and more.

### Explore our other products

* [**Bit Form**](https://bit-form.com/): multi-step and conversational form builder with 50+ built-in integrations.
* [**Bit Integrations**](https://bit-integrations.com/): no-code automation connecting 300+ apps and services.
* [**Bit Assist**](https://bitassist.co/): one button for all your support channels — chat, WhatsApp, email, SMS, Telegram, Messenger, and more.
* [**Bit Social**](https://bit-social.com/): auto-post and schedule to 12+ social platforms.
* [**Bit Flows**](https://bit-flows.com/): multi-step automation with routers, iterators, and built-in AI integrations.
* [**Bit File Manager**](https://wordpress.org/plugins/file-manager/): manage files from your WordPress dashboard.

Join our [**Bit Apps Community**](https://www.facebook.com/groups/bitapps) for updates. The full source code is on [GitHub](https://github.com/Bit-Apps-Pro/bit-smtp); contributions are welcome.

== External services ==

Bit SMTP delivers your site's email through the provider you choose, so it connects to third-party services. Except for the optional deactivation feedback described below, nothing is contacted until you set up the matching feature. Your email content only ever goes to the email provider you configured.

= Email providers =

When you add a connection, every email sent through it — including test emails, resends, and retries — is sent to that provider. Each request contains the full message (sender, To/Cc/Bcc recipients, Reply-To, subject, body, attachments, and headers) and is authenticated with the API key, SMTP login, or OAuth token you entered.

For providers that support it, saving a connection with **Track delivery status** turned on (the default) also registers your site's webhook address (`https://your-site/bit-smtp/...`) with the provider through its API, so it can report delivery events back to your site. Deleting the connection removes that webhook. Turn **Track delivery status** off to skip this.

* **SendGrid (Twilio)** — `api.sendgrid.com`. [Terms](https://www.twilio.com/en-us/legal/tos), [Privacy](https://www.twilio.com/en-us/legal/privacy).
* **Amazon SES (Amazon Web Services)** — `email.<region>.amazonaws.com`. If you subscribe your webhook address to Amazon SNS for delivery events, Bit SMTP also confirms that subscription and downloads the SNS signing certificate from `sns.<region>.amazonaws.com`. [Customer Agreement](https://aws.amazon.com/agreement/), [Service Terms](https://aws.amazon.com/service-terms/), [Privacy](https://aws.amazon.com/privacy/).
* **Mailgun (Sinch)** — `api.mailgun.net` or `api.eu.mailgun.net`. [Terms](https://www.mailgun.com/legal/terms/), [Privacy](https://www.mailgun.com/legal/privacy-policy/).
* **Postmark (ActiveCampaign)** — `api.postmarkapp.com`. [Terms](https://postmarkapp.com/terms-of-service), [Privacy](https://www.activecampaign.com/legal/privacy-policy).
* **Brevo** — `api.brevo.com`. After a successful connection test, Bit SMTP also reads that test email's delivery events from Brevo's statistics API. [Terms](https://www.brevo.com/legal/termsofuse/), [Privacy](https://www.brevo.com/legal/privacypolicy/).
* **Resend** — `api.resend.com`. [Terms](https://resend.com/legal/terms-of-service), [Privacy](https://resend.com/legal/privacy-policy).
* **Mailjet (Sinch)** — `api.mailjet.com`. [Terms](https://www.mailjet.com/legal/terms/), [Privacy](https://www.mailjet.com/legal/privacy-policy/).
* **Zoho CPaaS (ZeptoMail)** — `api.zeptomail.com`, `api.zeptomail.eu`, or `api.zeptomail.in`, depending on the data center you pick. [Terms](https://www.zoho.com/cpaas/terms.html), [Privacy](https://www.zoho.com/privacy.html).
* **SparkPost (Bird)** — `api.sparkpost.com` or `api.eu.sparkpost.com`. [Terms](https://bird.com/legal/terms), [Privacy](https://bird.com/legal/privacy).
* **Cloudflare Email Sending** — `api.cloudflare.com`. [Terms](https://www.cloudflare.com/terms/), [Privacy](https://www.cloudflare.com/privacypolicy/).
* **Gmail / Google Workspace (Google)** — when you click **Connect**, your browser opens Google's sign-in page (`accounts.google.com`) with your Client ID and From address; Bit SMTP then exchanges the authorization code, and later refreshes the access token, with `oauth2.googleapis.com` using your Client ID and Secret. Email is sent through the Gmail API (`gmail.googleapis.com`). [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy), [API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy).
* **Microsoft 365 / Outlook (Microsoft)** — the same OAuth flow with `login.microsoftonline.com`; email is sent through Microsoft Graph (`graph.microsoft.com`). [Services Agreement](https://www.microsoft.com/en-us/servicesagreement), [Privacy](https://www.microsoft.com/en-us/privacy/privacystatement).
* **Other SMTP** — the SMTP server you enter, under that host's own terms.

= Failure and health alerts =

Only used when you add an alert channel in **Settings**. Alerts and test notifications include your site name and address, the connection's name and provider, the error, and the failed email's subject and up to 10 recipient addresses — never the email body. Health alerts include the connection's status and last error.

* **Slack** — `hooks.slack.com`. [Terms](https://slack.com/terms-of-service/user), [Privacy](https://slack.com/trust/privacy/privacy-policy).
* **Discord** — `discord.com`. [Terms](https://discord.com/terms), [Privacy](https://discord.com/privacy).
* **Telegram** — `api.telegram.org`. [Terms](https://telegram.org/tos), [Privacy](https://telegram.org/privacy).
* **Webhook** — the URL you enter receives the same details as signed JSON.

= Usage data sharing (opt-in) =

Only if you choose to share usage data in the welcome prompt or in **Settings**; you can stop at any time. The report goes to Bit Apps (`wp-api.bitapps.pro`) once when you agree and then about once a week. It contains your site name and address, the site admin email and the first administrator's name, your server's public IP address, WordPress, PHP, and MySQL versions, your theme and language, a few server settings, user counts by role, the names and versions of your active plugins, the number of inactive plugins, and how many test emails you sent. It never contains email content, recipients, logs, passwords, or API keys. [Bit Apps Terms](https://bitapps.pro/terms-of-service/), [Bit Apps Privacy](https://bitapps.pro/privacy-policy/).

To find the server's public IP address, the report asks `icanhazip.com`, which is operated by Cloudflare. That request sends nothing except the request itself. [Cloudflare Privacy](https://www.cloudflare.com/privacypolicy/).

= Deactivation feedback (optional) =

When you deactivate Bit SMTP, an optional feedback form appears. Only if you choose a reason and submit it, Bit SMTP sends that reason and any text you typed, plus your site address, site language, and plugin version, to Bit Apps (`wp-api.bitapps.pro`). Choosing **Skip** sends nothing. [Bit Apps Terms](https://bitapps.pro/terms-of-service/), [Bit Apps Privacy](https://bitapps.pro/privacy-policy/).

= Open and click tracking (off by default) =

Not an external service: when you turn it on, the tracking image and rewritten links point to your own site (`https://your-site/bit-smtp/track/...`), and the results are stored in your own database.

== Frequently Asked Questions ==

= Which provider should I use? =
Any of them work. If you already have an SMTP host, use **Other SMTP**. For better deliverability and speed, use a dedicated API provider (SendGrid, Amazon SES, Mailgun, Postmark, Brevo, Resend, Mailjet, Zoho CPaaS (ZeptoMail), SparkPost, Cloudflare Email Sending) or connect Gmail / Microsoft 365 over OAuth.

= Do I have to enter my Gmail or Microsoft password? =
No. Gmail and Microsoft 365 use OAuth: you create an app, enter its Client ID and Secret, then click **Connect** to authorize. Bit SMTP stores only the OAuth tokens, never your account password.

= What happens if my email provider goes down? =
Add more than one connection and drag them into a priority order. If the highest-priority connection fails, Bit SMTP automatically tries the next one. Turn on retries in **Settings** to also retry temporary failures later.

= Can I see whether an email was actually delivered? =
Yes. For providers that support delivery webhooks, the Logs screen shows a separate **Delivery** status (Delivered, Bounced, Blocked, Deferred) next to the send result. A successful send on its own only means the provider accepted the email.

= Are my API keys and passwords stored safely? =
Yes. Credentials are encrypted with authenticated AES-256-GCM before they are saved. You can also keep any credential out of the database by defining it in `wp-config.php` as `BIT_SMTP_<CONNECTION ID>_<FIELD>`.

= Does it work with my form or WooCommerce emails? =
Yes. Bit SMTP handles any email sent through WordPress's `wp_mail()`, including form plugins, WooCommerce, and core notifications.

= Will I be notified if sending fails? =
Yes. Add an alert channel (email, webhook, Slack, Discord, or Telegram) in **Settings**. Bit SMTP alerts once when failures start and once when sending recovers.

= Can I move my settings from another SMTP plugin? =
Yes. Run `wp bit-smtp import` to bring over connections from WP Mail SMTP or Easy WP SMTP. You can also export and import Bit SMTP's own settings from **Settings**.

= Which WP-CLI commands are available? =
`wp bit-smtp send-test`, `wp bit-smtp logs`, `wp bit-smtp retry status`, `wp bit-smtp retry flush`, `wp bit-smtp health check`, `wp bit-smtp export`, and `wp bit-smtp import`. Add `--help` to any command for its options.

= Does Bit SMTP track my recipients? =
Not unless you turn on open and click tracking in **Settings** (off by default). When it is on, results stay in your own database and are included in WordPress's **Export Personal Data** and **Erase Personal Data** tools.

= Can AI tools or automation read my email analytics? =
On WordPress 6.9 or newer, Bit SMTP registers five read-only abilities for authorized tools: `bit-smtp/get-email-analytics`, `bit-smtp/analyze-plugin-email`, `bit-smtp/analyze-deliverability`, `bit-smtp/explain-routing`, and `bit-smtp/detect-email-anomalies`. All require the `manage_options` capability and return only aggregate metrics or privacy-safe routing details — never email bodies, attachments, credentials, tokens, or full recipient addresses. They read only Bit SMTP's retained logs.

Inputs: `start` and `end` (ISO-8601 timestamps; default is the last 30 days, or your retention period if shorter, up to 200 days), `bucket` (`hour`, `day`, or `week`), `plugin`, and `connection_id`. `analyze-plugin-email` requires `plugin`. `explain-routing` takes either a `log_id` or `to_domains` with an optional `source_plugin`, and never sends email.

Delivery counts stay separate from send acceptance: an email counts as delivered or bounced only after an authenticated provider webhook reports it. Older logs without source information are reported as `unknown` rather than guessed.

== Screenshots ==
1. SMTP Configuration
2. Send test mail to validate SMTP Configuration
3. Mail sent logs
4. Log detail of a sent mail
5. Preview of email log

== Changelog ==

= 1.3.0 (29 Oct, 2026) =
* Feat: Dedicated API integrations for SendGrid, Amazon SES, Mailgun, Postmark, Brevo, Resend, Mailjet, Zoho CPaaS (ZeptoMail), SparkPost, and Cloudflare Email Sending (Beta), plus Gmail and Microsoft 365 via OAuth, any SMTP server, and PHP mail().
* Feat: Multiple connections with a priority order and automatic fallback; enable, disable, and disconnect connections without deleting them.
* Feat: Retry queue with backoff for temporary failures, failure classification, and connection health checks.
* Feat: Smart routing by recipient, from address, subject, or source plugin; the source picker lists every installed plugin.
* Feat: Real delivery tracking through provider webhooks, registered automatically when you save a supported connection; Amazon SES events via signed SNS notifications.
* Feat: Failure and health alerts by email, webhook, Slack, Discord, and Telegram, sent once per incident with recovery notices.
* Feat: Analytics dashboard, optional open and click tracking, and read-only Email Analytics abilities on WordPress 6.9+.
* Feat: Richer logs — Cc/Bcc, message IDs, filters, resend history, and CSV export — with scheduled retention clean-up.
* Feat: Settings page with export/import and a configurable send timeout; import connections from WP Mail SMTP and Easy WP SMTP with WP-CLI.
* Feat: WP-CLI commands and `wp-config.php` credential constants.
* Feat: Multisite support, guided setup with help on every field, a refreshed accessible theme, and dark mode.
* Security: Credentials encrypted at rest with AES-256-GCM; hardened webhook and outbound API paths; usage data is shared only after a one-time opt-in.
* Privacy: The admin font is now bundled with the plugin instead of loaded from Google Fonts; all external services are documented in this readme.
* Requires PHP 8.1 or newer and WordPress 5.9 or newer. Installs on older PHP show an admin notice and stay inactive.

= 1.2.4 (11 May, 2026) =
* fix: short description issue in readme.txt 

= 1.2.3 (20 Feb, 2026) =
* fix: broken access control 
* chore: ui updated
* chore: tested with latest wordpress version

= 1.2.2 (04 Jan, 2026) =
* fix: mail sending is not working some hosting provider due to sender is not set
* chore: ui updated
* chore: tested with latest wordpress version

= 1.2.1 (10 Nov, 2025) =
* chore: tested with latest wordpress version
* chore: ui updated

= 1.2 (08 Nov, 2025) =
* Feat: Redesigned interface
* Feat: Email activity history
* Feat: View sent emails
* Feat: Resend email
* Feat: Delete email record
* Feat: Resend multiple emails

= 1.1.8 (31 Jan, 2025) =
* Bit Flows promotional banner added 

= 1.1.7 (11 Dec, 2024) =
* Bit Social promotional banner removed 

= 1.1.6 (01 Dec, 2024) =
* Bit Social promotional banner updated 

= 1.1.5 (05 Nov, 2024) =
* Plugin deletion issue fixed 

= 1.1.4 (02 Nov, 2024) =
* Bit Social promotional banner updated 
* Telemetry package version updated

= 1.1.3 (03 Oct, 2024) =
* Others page updated

= 1.1.2 (29 Sep, 2024) =
* Telemetry Modal replaced with Multi Step Modal

= 1.1.1 =
* Namespace conflict issue fixed

= 1.1.0 =
* SMTP debug enable/disable option added

= 1.0.9 =
* UI modified and some issue fixed

= 1.0.7 =
* Tested with 6.3
* Reply To nullable

= 1.0.6 =
* Improves confirmation message

= 1.0.5 =
* Fix: Illegal string offset (PHP Warning)

= 1.0.4 =
* Tested with WordPress 5.9 version

= 1.0.3 =
Fix: required field issue

= 1.0.2 =
Fix: some issues

= 1.0.1 =
* SMTP Frontend changed

= 1.0.0 =
* Initial release of bit-smtp
