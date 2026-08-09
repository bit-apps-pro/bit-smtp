=== Bit SMTP – Easy SMTP Solution with Email Logs ===
Contributors: bitpressadmin, akaioum
Tags: email logs, smtp, email, gmail smtp, wp mail smtp
Requires at least: 5.7
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.2.4
License: GPLv2 or later

SMTP plugin for reliable, secure email delivery. Connect Gmail, Outlook, SendGrid, Brevo, Mailgun, Amazon SES, Postmark, Cloudflare Email Sending, and more via API, OAuth, or SMTP.

== Description ==

## SMTP Plugin for WordPress for Reliable and Secure Email Delivery | Complete Free SMTP Solution with Detailed Email Logs

**Easily fix WordPress email delivery issues. Connect with Gmail, Outlook/Microsoft 365, SendGrid, Amazon SES, Mailgun, Postmark, Brevo, Cloudflare Email Sending, and more — over their fast API, secure OAuth, or classic SMTP — to keep your site communication dependable and secure.**

Add multiple sending connections, set a priority order so delivery automatically **falls back** to the next provider if one fails, track the **real delivery status** (delivered / bounced) of each email, and get **alerts** when sending fails — all free.

## Is your WordPress not sending emails, or are they going to spam?

Solve your email delivery problems with Bit SMTP. The perfect WP Mail SMTP plugin. SMTP helps you send WordPress emails safely and reliably.

When you use Bit SMTP, your emails get delivered perfectly because they are sent from an authenticated source. Many WordPress sites have problems with email. The standard PHP mail function is often blocked or filtered as spam. Bit SMTP solves this issue. It lets you connect your WordPress site to any real **SMTP server.**

## Easily track and manage all your WordPress email logs with Bit SMTP.

Bit SMTP is a **free plugin.** All of the features are completely free. So you don’t need to spend on SMTP configuration

Let’s explore the main reasons to use Bit SMTP and how you can set it up easily.

== Easily Setup Mail SMTP in Minutes ==

Using an SMTP server authenticates your website's emails, proving they are from a trusted source. This tells the recipient’s mail system that your email is genuine. It is like presenting your ID before entering a secure building.

Getting started with Bit SMTP is simple:

1. **Sign up with an email provider:** Choose Gmail, Outlook/Microsoft 365, SendGrid, Amazon SES, Mailgun, Postmark, Brevo, Cloudflare Email Sending, or any SMTP server.
2. **Add a connection:** In the Bit SMTP dashboard click **Add connection** and pick your provider from the grid.
3. **Enter your credentials:** Paste the provider's API key (or SMTP host/port/username/password). For Gmail and Microsoft 365, click **Connect** to authorize securely over OAuth — no password stored.
4. **Send a test email:** Run **Test** to confirm delivery. Your credentials are encrypted at rest before they're saved.
5. **Enable email logs:** View detailed logs — including each email's real delivery status — from the Bit SMTP dashboard.

Your provider handles delivery, ensuring your WordPress emails arrive securely every time.

## ⚡Key Features of Bit SMTP ⚡

[Bit SMTP](https://bitapps.pro/bit-smtp/) gives you all the tools you need to send emails from WordPress safely and reliably.

- **Dedicated Provider Integrations:** Send over each provider's own API — SendGrid, Amazon SES, Mailgun, Postmark, Brevo, Resend, Mailjet, ZeptoMail, SparkPost, Cloudflare Email Sending — plus Gmail and Microsoft 365, any SMTP server, and PHP mail().
- **Secure OAuth for Gmail & Microsoft 365:** Authorize with one click; the plugin stores refresh tokens, never your account password.
- **Multiple Connections with Automatic Fallback:** Add several connections and set a priority order. If the top provider fails, Bit SMTP automatically retries the next one so your mail still goes out.
- **Smart Routing:** Route mail to a specific connection by recipient, from address, subject, or the sending plugin.
- **Real Delivery Tracking:** Provider webhooks report each email's true outcome — Delivered, Bounced, Blocked, Deferred — shown in a separate Delivery column, not just "accepted".
- **Failure Alerts:** Get notified by email or webhook when a send fails, so a broken provider never goes unnoticed.
- **Encrypted Credentials at Rest:** API keys and secrets are stored with authenticated AES-256-GCM encryption.
- **Customizable Reply-To:** Set a custom Reply-To address for managing responses.
- **Quick Setup:** Pick a provider, paste a key (or click Connect), and send — configured in minutes.
- **Free and Fully Functional:** All features are completely free. No premium plan required.
- **Detailed Email Logs:** View a log of every email sent, with delivery status and per-recipient detail. Set a retention period and search logs by email address; resend or delete records.
- **Dark Mode Interface:** Bit SMTP comes with a clean dark mode that’s easy on the eyes and makes your dashboard look great.

## Simple Setup for SMTP Configuration in WordPress

Get Bit SMTP running in minutes. Just go to **Bit SMTP ▸ Configuration** in your WordPress dashboard and click **Add connection**.

Every connection asks for an identity:

* **From Email Address:** Set the sender’s email (must be an address you've verified with the provider)
* **From Name:** Name to show as sender
* **Reply-To Email Address:** Add if you want replies somewhere specific

Then enter the provider's credentials:

* **API providers** (SendGrid, Amazon SES, Mailgun, Postmark, Brevo, Resend, Mailjet, ZeptoMail, SparkPost, Cloudflare Email Sending): paste the API key (SES and Mailgun also take a region/domain; Cloudflare also needs its Account ID).
* **Gmail / Microsoft 365:** enter the Client ID and Secret, then click **Connect** to authorize over OAuth.
* **Other SMTP:** enter **SMTP Host**, **Port** (usually 465 or 587), **Encryption** (SSL/TLS), and **Username / Password**.

Save, run **Test**, and you're done. Add more connections and drag them into a priority order for automatic fallback.

## Supported Email Services and SMTP Providers

Bit SMTP ships **dedicated integrations** for the major email services — using each provider's own fast API or secure OAuth — and also works with **any SMTP server**. Most providers offer a free tier, so you can pick the best option for your needs and budget.

**Dedicated API integrations** (paste an API key):

* **SendGrid** — great deliverability; free tier available.
* **Amazon SES** — best for large volumes; enter Access Key, Secret, and region.
* **Mailgun** — enter the sending API key, domain, and region (US/EU).
* **Postmark** — fast transactional delivery (Server API Token).
* **Brevo (Sendinblue)** — generous free daily tier.
* **Resend** — modern developer email API.
* **Mailjet** — API Key + Secret Key.
* **ZeptoMail (Zoho)** — transactional mail with a Send Mail Token and data center.
* **SparkPost** — classic Transmissions API (US/EU).
* **Cloudflare Email Sending (Beta)** — send outbound email through Cloudflare's Email Sending API. Configure the sender address and domain in Cloudflare first; sending to general recipients requires a Workers Paid plan. This is an API integration; use **Other SMTP** with an SMTP provider when SMTP is the better fit.

**OAuth integrations** (one-click Connect, no password stored):

* **Gmail / Google Workspace**
* **Microsoft 365 / Outlook**

**Any SMTP server** — use the **Other SMTP** provider for your host, or for Yahoo, Zoho Mail, or any mailbox. Common settings:

* **Gmail SMTP:** smtp.gmail.com · TLS · 587
* **Outlook SMTP:** smtp-mail.outlook.com · TLS · 587
* **Yahoo SMTP:** smtp.mail.yahoo.com · SSL · 465
* **Zoho Mail SMTP:** smtp.zoho.com · TLS · 587

You can also use **PHP Sendmail** (the server's local mail()) when no external provider is configured.

## Test Your Connection

Bit SMTP includes a tool for this:

1. Open a connection and click **Test Connection**, or visit **Bit SMTP ▸ Test** for a full test message.
2. Add the recipient’s address, your subject, and a brief message.
3. Click **Send**. The plugin sends a real email through that connection and shows success or the provider's error.

If you receive the email, you’re setup is correct. To make things even easier, check out our video tutorial:

https://youtu.be/1dnw6v2E2y8

## Compatible with All the Top Form Plugins

If you use forms, you want to make sure notifications are delivered every time. Bit SMTP works with all leading form plugins on WordPress.

Supported plugins include:

* [**Bit Form**](https://wordpress.org/plugins/bit-form/)
* **Gravity Form**
* **Contact Form 7**
* **WPForms Pro**
* **Ninja Forms**
* **Forminator Forms**
* **Fluent Forms**
* **Formidable Forms**
* **Everest Forms**
* **WS Forms**
* **Happy Forms**
* **weForms**
* **Kali Forms**
* **WPForm Lite**
* **PlanSo Forms**
* **Form Maker by 10Web**
* **Elementor Form**
* **FormCraft**
* **Quform WordPress Form Builder**
* **Caldera Contact Forms**

And all other plugins that rely on WordPress's wp_mail to send emails

## Strong Security for Your WordPress Emails

Bit SMTP follows best security practices. Many providers, like **Gmail SMTP**, **SendGrid**, or **Mailgun**, provide secure SMTP configuration credentials. You can connect using the SMTP credentials, not by typing your main login into WordPress. This keeps your data safe and ensures a higher level of security.

## **Reliable WordPress Mail SMTP for Every User**

Don’t let emails go missing or end up in spam. Bit SMTP, the leading WP Mail SMTP plugin, gives you full control over your WordPress email delivery. You can use Gmail SMTP or connect to any mail service you trust. 

Make sure all your emails get to the right place, every time. Install Bit SMTP now and enjoy reliable, secure email delivery from your WordPress site. Say goodbye to missed emails and spam issues!

## Track and Monitor WordPress Email Logs
With Bit SMTP’s built-in email logs system, you can easily track every email you send. View delivery details and monitor performance right from your WordPress dashboard. This gives you complete transparency and control over your WordPress site's email activity.

### **Explore Our Other Products :**

* [**Bit Form**](https://bit-form.com/): A powerful WordPress form builder that lets you create **multi-step and conversational forms** with a **smart drag-and-drop builder**. Connect your forms with 50+ apps through **built-in integrations** to automate workflows. Build, customize, and convert with the lightning-fast form solution
* [**Bit Integrations**](https://bit-integrations.com/): A no-code WordPress automation plugin that lets you connect **300+ apps and services** to automate your workflows in minutes. With its **3 easy automation methods**, you can automate tasks, sync data, and maximize productivity. It connects your forms, CRMs, LMS, and eCommerce tools all inside WordPress.
* [**Bit Assist**](https://bitassist.co/): Connect all your customer support channels with a single button. Integrate Floating Chat Widget, WhatsApp, Email, SMS, Telegram, Messenger, and more with Bit Assist.
* [**Bit Social**](https://bit-social.com/): A social media automation tool that lets you **auto-post, schedule, and share instantly** across **12+ platforms** like Facebook, Instagram, LinkedIn, X, Pinterest, and more with a smart calendar view.
* [**Bit Flows**](https://bit-flows.com/): Bit Flows is a powerful automation tool with multi-step, unlimited workflows and advanced tools, including Router, Repeater, Iterator, and JSON Parser. With built-in AI integrations, it’s a more powerful and easier alternative to n8n.
* [**Bit File Manager**:](https://bitapps.pro/bit-smtp/) Bit File Manager is a WordPress plugin for easy file management. Upload, organize, and control from your WordPress dashboard.

Join our [**Bit Apps Community**](https://www.facebook.com/groups/bitapps) for the latest plugin and exclusive features updates

You can find the full source code on [GitHub,](https://github.com/Bit-Apps-Pro/bit-smtp) and we welcome any contributions to help improve this amazing plugin

#### **Telemetry Data**

Bit SMTP uses [wp-telemetry](https://packagist.org/packages/bitapps/wp-telemetry) to collect some telemetry data upon the user’s confirmation. This helps us to troubleshoot problems faster & make product improvements.

Wp-Telemetry DOES NOT IMMEDIATELY start gathering data; rather, it will gather basic telemetry data when a user allows it. We collect the data to ensure a great user experience for all our users.

== Frequently Asked Questions ==

= Which provider should I use? =
Any of them work. If you already have an SMTP host, use **Other SMTP**. For better deliverability and speed, use a dedicated API provider (SendGrid, Amazon SES, Mailgun, Postmark, Brevo, Resend, Mailjet, ZeptoMail, SparkPost, Cloudflare Email Sending) or connect Gmail / Microsoft 365 over OAuth.

= Do I have to enter my Gmail or Microsoft password? =
No. Gmail and Microsoft 365 use OAuth: you create an app, enter its Client ID and Secret, then click **Connect** to authorize. Bit SMTP stores only the OAuth tokens, never your account password.

= What happens if my email provider goes down? =
Add more than one connection and drag them into a priority order. If the highest-priority connection fails, Bit SMTP automatically retries the next one, so your mail still goes out.

= Can I see whether an email was actually delivered? =
Yes. For providers that support delivery webhooks, the Logs screen shows a separate **Delivery** status (Delivered, Bounced, Blocked, Deferred) alongside the send status. Amazon SES marks delivery on a successful send-accept.

= Are my API keys and passwords stored safely? =
Yes. Credentials are encrypted at rest with authenticated AES-256-GCM before they are written to the database.

= Does it work with my form or WooCommerce emails? =
Yes. Bit SMTP routes any email sent through WordPress's `wp_mail()`, including form plugins, WooCommerce, and core notifications.

= Will I be notified if sending fails? =
Yes. Turn on failure alerts (Notifications) to be told by email or webhook when a send fails.

== Screenshots ==
1. SMTP Configuration
2. Send test mail to validate SMTP Configuration
3. Mail sent logs
4. Log detail of a sent mail
5. Preview of email log

== Changelog ==

= 1.3.0 (26 Jul, 2026) =
* Feat: Multi-provider framework — send over dedicated integrations for SendGrid, Amazon SES, Mailgun, Postmark, Brevo, Resend, Mailjet, ZeptoMail, and SparkPost, plus Gmail and Microsoft 365 via OAuth, any SMTP server, and PHP mail().
* Feat: Cloudflare Email Sending (Beta) API integration. Configure your sender address/domain in Cloudflare; sending to general recipients requires Workers Paid. Use Other SMTP as the SMTP alternative.
* Feat: Multiple connections with a priority order and automatic fallback to the next connection when a send fails.
* Feat: Smart routing — send matching mail through a chosen connection by recipient, from address, subject, or source plugin.
* Feat: Real delivery tracking — provider webhooks record each email's true outcome (Delivered / Bounced / Blocked / Deferred) in a separate Delivery column.
* Feat: Failure alerts by email or webhook when a send fails.
* Feat: Credentials encrypted at rest with AES-256-GCM; existing plaintext values are migrated on save.
* Security: hardened the inbound delivery-webhook and outbound API paths.
* Requires PHP 8.1 or newer (was 8.0). Installs on older PHP show an admin notice and stay inactive.

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
