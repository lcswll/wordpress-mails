=== Mailspur – Email Log ===
Contributors: lcswll
Tags: email log, mail log, wp_mail, smtp, email
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Logs the emails WordPress sends, maps the types of email your site sends and alerts you when one stops. Safe preview, diagnostics, private.

== Description ==

Did the order confirmation go out? Why did the password reset never arrive? Mailspur records every email sent through `wp_mail()` – no matter which plugin triggered it or which SMTP or API mailer delivers it – and shows not just *that* an email was sent, but its whole trail ("Spur"): where it came from, how it left your server and what could stop it from arriving.

= What makes it different =

* **It maps the emails your site sends – and notices when one stops.** Mailspur builds a map of all email types on its own (order confirmation, password reset, contact form …), learns how often each one goes out and tells you when a type that normally runs daily or weekly suddenly falls silent – naming the plugin update that happened in between.
* **Safe preview of logged emails.** HTML emails are shown in a sandboxed frame with an opaque origin, so scripts and forms in a logged email do not run, and remote content such as tracking pixels is only loaded when you allow it.
* **Secrets in links are masked.** One-time secrets in links (password resets, activation keys, order keys) are masked *before* they are written to the database, so the log does not contain working reset links.
* **It explains problems instead of just listing them.** Every email is checked for issues that keep it from arriving or displaying correctly, and failed emails come with a plain-language explanation and concrete steps.
* **It records what was actually sent** – the final body after template plugins, the real sender, the SMTP server or API plugin that delivered it, and the exact code that called `wp_mail()`.
* **Built for large logs:** an indexed table, live search, two small queries per email. No upsells, no tracking, no external service required.

= Email types: the map of your site's emails =

Most email problems are not failures but emails that are never triggered: a plugin update breaks the order confirmation, a cron job dies, a form stops sending. A log cannot show what is missing – the "Email types" tab can:

* **Every kind of email, found automatically,** grouped by sending plugin and subject. Order numbers, dates and names become placeholders, so "Welcome, Anna!" and "Welcome, Ben!" are one type.
* **Health per type:** emails and failures of the last 30 days, the rhythm ("daily", "weekly"), when it was last sent and notes in its latest email.
* **"This email type stopped":** Mailspur learns each type's rhythm from its last 8 weeks and marks overdue types. With alerts on, you get one message – naming the updates installed since – and another when it is back. For WP-Cron emails it names the likely cause (event no longer scheduled, WP-Cron not running, event overdue).
* **Content changes after updates:** "Content changed on 3 Oct, after the WooCommerce 9.4 update" – "Compare" shows the text changes and both previews side by side. Names, numbers and dates are ignored.
* **Shortcuts:** open the latest email, send it to yourself, or jump to where its text is edited (WooCommerce, Contact Form 7, WPForms, Gravity Forms, Fluent Forms or the plugin's settings). "Trigger to me" generates the WordPress password reset or new user email for your own account and shows the result.
* **Hints where they help:** types that reach administrators 30+ times a month (with where to switch them off), types that keep visitors waiting 1.5 seconds or more for the mail server, and plugins that send their first email ("New sender", with an alert when one writes to many external addresses).
* **Email inventory:** export all types as CSV or a printable page with sending plugin, recipient group, rhythm, retention and the categories of personal data found – for a record of processing activities or a handover. No contents or addresses.
* **Built for privacy and speed:** sorting happens afterwards, never while an email is sent. Only counters, subject patterns and send durations are stored – never recipients or contents.

= Find any email in seconds =

* Live search over recipient and subject, optionally also the content.
* Status tabs with counts (sent, failed, held, unknown), date range, sorting, and filters for the sending plugin or theme, HTML/plain text, attachments and notes. Every filter is part of the URL, so a filtered view can be bookmarked.
* Keyboard friendly: `/` jumps to the search, `j`/`k` move between emails, `Esc` closes the preview.
* Export the current view as CSV (Excel-ready, safe against formula injection) or JSON.
* **Copy for support:** one click in the email view copies a ready-to-paste sentence for a customer, in the site language – e.g. "The email “Your invoice #1042” was sent to anna@example.com on 3 October at 14:02 and accepted by the mail server. Please also check your spam folder." – without technical details.

= Emails right where you need them =

"Did the customer get her invoice?" Mailspur links emails to what they belong to:

* **WooCommerce orders** (HPOS and classic) get an "Emails" box with every email sent for that order – status, notes, a link to the log entry and a resend button. Emails to the billing address around the order date that are not linked, such as older or imported entries, are marked "same recipient".
* **User profiles** show the latest emails to that user, including notices sent to a previous address.
* In the log, an email shows the order or user it belongs to. Only people who can view the log and edit that order or user see these links.

= The trail of every email =

* **Trace:** how long sending took, which mailer and SMTP server (host, port, encryption, masked username) or which API plugin delivered it, the file, line and function that called `wp_mail()`, the active hooks and the request (cron, REST, Ajax, admin, front end, CLI – no IP addresses, no query strings).
* **SMTP transcript** for failed emails (optional for all): the conversation with the SMTP server, with login data and message content always masked.
* **Download as .eml** to open any logged email in your mail program.
* **See how an email really looks:** switch the preview to phone width, dark mode (using the email's own dark-mode styles), a forced-dark simulation like in apps that invert colours, or plain text. Scripts, forms and remote content stay blocked in every view.

= Notes: problems found in your emails =

* HTML sent as plain text, Gmail clipping (> 102 KB), relative links and images, development or staging URLs, unreplaced placeholders like `{first_name}` or `%s`, broken encoding (Ã¤), risky sender addresses (wordpress@localhost, gmail.com sent from your server), bulk mail without `List-Unsubscribe`, typos in recipient domains (gmial.com), misleading link texts and more – shown as a badge in the list.
* **Open distribution lists:** warns when several outside recipients in To or Cc can see each other's addresses (a typical GDPR data breach). Your own domain and Bcc do not count.
* **Spam signs:** a subject in capitals or with many exclamation marks, an email that is almost only an image, links via URL shorteners (bit.ly …) – hints that can make delivery worse, not a spam score.
* **Secrets in plain text:** warns when a plugin emails a password, API key, private key or card number – and masks the value in the log, together with the secrets in links.
* When you open an entry: whether the recipient domain can receive email at all (MX lookup), whether the same email was sent several times within minutes and whether links to your own site lead to a page that no longer exists (404).
* **Error messages explained:** PHPMailer errors, SMTP reply codes (421, 450, 535, 550, 554 …), Gmail and Microsoft 365 login problems and API mailer errors (invalid key, unverified domain, quota) with step-by-step fixes.

= Statistics, charts and alerts =

* Emails over time (sent / failed / held), failure rate, a weekday × hour heatmap and the top senders, recipient domains and subjects – click any bar to open those emails. Accessible charts (keyboard, data tables), no external libraries.
* Dashboard widget with the last 7 days.
* **Monitoring alerts (opt-in):** get notified by email or webhook (Slack, Discord or any JSON endpoint) when emails fail repeatedly or when your site unexpectedly stops sending emails.
* **Weekly report (opt-in):** a short email every Monday to the alert addresses, only when there is something to report – number of emails and failure rate compared with the previous week, new, stopped and changed email types, emails with notes and emergency-brake incidents.

= Deliverability and staging =

* **Sender check:** SPF, DKIM, DMARC and MX of the domains your site sends from, with a traffic light per record and ready-to-paste record suggestions.
* **Staging mode:** hold every email (log only) or redirect all emails to test addresses on staging and development copies – a clear warning shows while it is active, held emails can be sent one by one.
* **Emergency brake for mail floods:** Mailspur learns your site's normal email volume and alerts you when far more emails leave than usual, e.g. when spam bots abuse a contact form. Optionally it holds further emails until you release or discard them. Password reset emails always go out.
* **Problem recipients:** addresses that failed hard twice (unknown mailbox, domain without mail server, hard bounce) are listed with "Allow again". Optionally further emails to them are held (off by default; password resets always go out).
* **Delivery status from your email provider (opt-in):** Postmark, Mailgun, Brevo or Amazon SES can report deliveries, bounces and spam complaints to a webhook URL of your site. The logged email then shows "Delivered", "Bounced" or "Marked as spam".
* **Send to another address**, e.g. to forward a lost order confirmation, or resend the original.

= Bring your old log along =

Switching from another plugin? Mailspur imports existing logs of WP Mail Logging, Email Log, Check & Log Email, FluentSMTP, Post SMTP, SureMail, WP Mail Catcher and WP Mail Log. The import can be resumed and repeated, skips emails that are already in the log, converts time zones correctly and masks secrets in old emails as well. The other plugin's data is only read, never changed.

= Privacy =

The log contains personal data (recipients and content of emails). Mailspur:

* deletes entries automatically after a configurable retention period (default: 90 days) and/or above a maximum number of entries – or anonymises them first, keeping only what the statistics need,
* integrates with the WordPress personal data export and erasure tools,
* suggests a paragraph for your privacy policy,
* keeps problem recipients only until the retention period of the log ends, and includes them in the personal data export and erasure,
* contacts no external service by default. The optional exceptions (an alert webhook you enter yourself, and the certificate and subscription requests of Amazon SNS when you choose Amazon SES as your email provider) are listed under "External services".

= External services =

Mailspur works without any external service. All of the following are optional and off by default:

* **Alert webhook (off by default).** If you enable monitoring alerts and enter a webhook URL, your site sends a short JSON POST to exactly that URL when an alert fires (or when you click "Send test alert"): site name, site URL, the alert text, a link to the log and a timestamp – never email contents or recipient addresses. For a stopped email type the alert text names the sending plugin, the subject pattern with placeholders (e.g. "Your order #… has been received") and recently updated plugins. Where the data goes depends on the URL you enter. For Slack see the [terms](https://slack.com/terms-of-service) and [privacy policy](https://slack.com/privacy-policy), for Discord the [terms](https://discord.com/terms) and [privacy policy](https://discord.com/privacy).

* **Delivery status from your email provider (off by default).** If you choose Postmark, Mailgun, Brevo or Amazon SES under Settings → "Delivery status from your email provider", that provider sends webhook requests to a URL of your site that contains a random secret key. Your site receives the recipient address, the kind of event and identifiers of the email; it stores only the status and, for hard bounces, the address as a problem recipient. To match the reports, outgoing emails get one header with a random reference (e.g. `X-PM-Metadata-mailspur` or `X-Mailgun-Variables`), which your provider receives with the email. Your site sends nothing to Postmark, Mailgun or Brevo. Postmark: [terms](https://postmarkapp.com/terms-of-service), [privacy policy](https://postmarkapp.com/privacy-policy). Mailgun: [terms](https://www.mailgun.com/legal/terms/), [privacy policy](https://www.mailgun.com/legal/privacy-policy/). Brevo: [terms](https://www.brevo.com/legal/termsofuse/), [privacy policy](https://www.brevo.com/legal/privacypolicy/).
* **Amazon SNS (only with Amazon SES as the email provider).** Amazon SES reports through Amazon SNS. To verify each message, your site downloads the SNS signing certificate from `https://sns.<region>.amazonaws.com/` (only that host, cached for a day), and to confirm the subscription it requests the confirmation link SNS sends (again only `sns.<region>.amazonaws.com`). These requests contain no email data. Amazon Web Services: [service terms](https://aws.amazon.com/service-terms/), [privacy notice](https://aws.amazon.com/privacy/).

The sender check (SPF/DKIM/DMARC/MX) and the recipient-domain check only ask your server's own DNS resolver, on demand, and contact no third-party service.

The dead-link check only looks at links to your own site: first internally, otherwise with one short request to your own site (like WordPress' Site Health does). Links to other websites are never requested.

Remote images in a logged email are blocked in the preview. Only when you click "Load remote content" (or enable "Always load remote images") does your browser request them from the servers named in that email, just as a mail program would. The plugin itself sends nothing to these servers.

= For developers =

* WP-CLI: `wp mailspur list|show|resend|stats|purge|export|import`, `wp mailspur brake status|release|discard|reset` and `wp mailspur probe [--type=password-reset|new-user|all] [--to=<admin email>]` (triggers the core emails for an administrator's own account; exits with an error code when one fails, e.g. in deploy scripts).
* REST: `GET /wp-json/mailspur-email-log/v1/types` lists every email type with its health.
* Filters: `mailspur_should_log` (skip logging an email), `mailspur_redact_params` (masked URL parameters), `mailspur_import_duplicate_window` (seconds), `mailspur_note_rules` and `mailspur_note_texts` (own checks), `mailspur_staging_subject`, `mailspur_brake_exempt` (never hold an email, also for problem recipients).

= Source code =

Development happens on GitHub: https://github.com/lcswll/wordpress-mails – issues and pull requests are welcome.

= About the author =

Mailspur is made by [Lucas Wille](https://lucaswille.de/), web designer and developer from Magdeburg, Germany. If Mailspur saves you time, a [review on WordPress.org](https://wordpress.org/support/plugin/mailspur-email-log/reviews/#new-post) helps a lot.

== Installation ==

1. Install the plugin via Plugins → Add New (search for "Mailspur Email Log") or upload the ZIP file.
2. Activate it. Logging starts immediately.
3. Open "Mail Log" in the admin menu. Adjust retention, access, alerts and staging mode under the "Settings" tab.

== Frequently Asked Questions ==

= Does it work with my SMTP plugin? =

Yes. Mailspur hooks into `wp_mail()` itself, so it works with any SMTP or API mailer that uses WordPress' mail function – including those that send through `pre_wp_mail`. The Trace tab shows which one delivered each email.

= Can the log be used to hijack accounts via logged password-reset links? =

Not with the default settings. Secret keys in links (`key=`, `token=` and similar) are replaced with `[redacted]` before the email is stored. If you resend such an email, the masked version is sent.

= Why are images missing in the preview? =

Remote images and fonts are blocked on purpose: otherwise every preview would tell the sender's tracking service that the email was opened. Click "Load remote content" above the preview, or enable "Always load remote images" in the settings.

= What are the notes next to some emails? =

Hints about problems found in that email, e.g. links that only work on your website, unreplaced placeholders or a typo in the recipient's domain. Open the entry and switch to the "Notes" tab for an explanation and how to fix it. Notes can be turned off or hidden individually under Settings → Notes.

= Does checking emails slow down sending? =

No. Emails are checked with simple text rules when they are logged, without network requests or extra queries. The DNS lookup of recipient domains and the check for repeated sending only run when you open an entry.

= Which secrets are masked besides reset links? =

Passwords written into an email ("Password: …"), API keys with well-known prefixes, private keys and payment card numbers. The email gets a note explaining the risk, and the value is stored as [redacted]. Both follow the setting "Redact secrets" (on by default).

= Does the SMTP transcript contain my SMTP password? =

No. Everything sent after "AUTH" (usernames, passwords, OAuth tokens) and the server's login challenges are replaced with [hidden], and the message content is omitted.

= What is the difference between the reconstructed and the exact .eml file? =

By default the .eml file is rebuilt from the logged data: secrets such as password-reset keys stay redacted and attachments are not included. If you enable "Store the exact raw MIME source" (and confirm the warning), the message is stored exactly as it was sent – not redacted, and roughly doubling the storage per email.

= What does staging mode do? =

"Log only" logs every email but delivers none (status "Held"). "Redirect" delivers every email to the addresses you enter instead of the real recipients; Cc and Bcc are removed and the subject shows the original recipients. Held emails can be sent individually with "Send now". On sites whose environment type is not "production", Mailspur suggests turning it on.

= What does the emergency brake do? =

Mailspur remembers the busiest hour of the last 14 days. If more than three times that many emails (at least 50) leave within one hour, it sends one alert through your monitoring alert channels and shows a notice with the main source. In "Alert and hold" mode, further emails are held until you release them (sent in small batches) or discard them; password reset emails and Mailspur's own alerts are never held. You can also set a fixed threshold or switch the brake off.

= Can I see whether an email reached the inbox? =

Only your email provider knows that. If you send through Postmark, Mailgun, Brevo or Amazon SES, choose it under Settings → "Delivery status from your email provider" and paste the webhook URL shown there into your provider's webhook settings. Reports are matched by a reference header, the Message-ID or – as a fallback – the recipient and time, and appear in the email's details. Without it, "Sent" means that your server or provider accepted the email.

= What are problem recipients? =

Addresses that failed hard at least twice: the receiving server rejected the mailbox (e.g. "550 user unknown"), the recipient domain has no mail server, or your email provider reported a hard bounce. Temporary errors and problems on the sender's side do not count. You find them under Settings → "Problem recipients", where each one can be allowed again. Entries expire with the retention period of the log.

= How accurate is the dark-mode preview? =

It is a simulation. "Dark" applies the email's own dark-mode styles, as Apple Mail and other clients that support them do. "Forced dark" inverts the colours roughly the way some phone apps do. Test important templates in the real apps too.

= Does the sender check contact an external service? =

No. It only asks your server's own DNS resolver for the SPF, DMARC, DKIM and MX records of your sender domains, and only when an administrator clicks "Check sender domains". If no DKIM key is found although your emails are signed, enter your selector (the s= value of the DKIM-Signature header) and check again.

= How does the "silence" alert avoid false alarms at night or on weekends? =

It only alerts when the last 14 days show that your site normally sends emails in that time window (on at least 10 of 14 days, including the same weekday one and two weeks ago).

= How does Mailspur know that an email type stopped? =

From the type's own history, without any setting to tune: a type sent on at least 6 of the last 56 days is "regular", and it is overdue once the time since its last email exceeds twice its typical gap or its longest gap so far plus one day. Rare or irregular types never alert. Turn the alert on under Settings → "Stopped email types"; it uses the email addresses and webhook of the monitoring alerts.

= Does Mailspur store email contents for the before/after comparison? =

No. Per type it stores only a short fingerprint and the IDs of the two log entries. The comparison reads the log entries themselves and is no longer available once they are deleted or anonymised.

= Why are two of my emails shown as one type, or one email as two types? =

Numbers, dates, addresses, links and quoted text are always placeholders. Other words only merge when they look like names (capitalised in a normally written subject) and most of the subject stays the same – so different wording always stays apart. If a sender produces more than 150 different subjects, the rest is collected as "Other emails" of that sender.

= Can I keep my history when switching from WP Mail Logging or another log plugin? =

Yes. Under Mail Log → Settings → "Import from other plugins" Mailspur lists every supported log it finds on the site (also from deactivated plugins) and imports it with one click. Entries older than your retention period are skipped, so raise the retention first if you want the complete history.

= What if two plugins logged the same email, or Mailspur already logged it? =

It is imported only once. Before each entry is imported, Mailspur looks for the same recipients and subject sent within two minutes from another source – Mailspur itself or another import. Mailspur's own entry always wins because it is the most accurate one.

= What does "Anonymise entries after" do? =

Once an entry is older than the configured number of days, the daily cleanup removes its content, headers, attachments and module data and masks the addresses (a***@example.com). Date, status, source, size and notes are kept, so statistics keep working. Anonymised entries cannot be resent.

= Are attachments stored? =

No. Only the file names are logged. When you resend an email, attachments are re-attached only if the original files still exist on the server.

= Does it slow down my site? =

No. Nothing runs on normal page views. Each sent email costs a few small database queries; statistics, email types and link checks are computed only when you open them (or hourly in the background) and are cached.

= What happens when I delete the plugin? =

By default all data of the plugin (log table, settings, scheduled tasks) is removed. You can keep the data by unchecking "Delete the log table and settings when the plugin is deleted" before deleting the plugin.

= Does it support multisite? =

Yes. Each site keeps its own log.

== Screenshots ==

1. The log: live search, status tabs, filters and a badge for emails with notes.
2. Email preview in a sandboxed frame with remote content blocked.
3. Trace: timeline, transport, origin of the wp_mail() call and request context.
4. Notes and explained errors for a failed email.
5. Statistics: emails over time, failure rate, heatmap and top lists.
6. Settings: access, privacy, retention, staging mode and alerts.
7. Email types: every kind of email the site sends, with volume, rhythm and status – here a renewal email that stopped after a plugin update.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
