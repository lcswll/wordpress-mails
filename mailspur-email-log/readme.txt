=== Mailspur – Email Log ===
Contributors: lcswll
Tags: email log, mail log, wp_mail, smtp, email
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Logs every email WordPress sends – with search, safe preview, delivery diagnostics, charts and alerts. Private and lightweight.

== Description ==

Did the order confirmation go out? Why did the password reset never arrive? Mailspur records every email sent through `wp_mail()` – no matter which plugin triggered it or which SMTP or API mailer delivers it – and shows not just *that* an email was sent, but its whole trail ("Spur"): where it came from, how it left your server and what could stop it from arriving.

= What makes it different =

* **Logged emails cannot attack you.** HTML emails are shown in a sandboxed frame with an opaque origin: injected scripts, phishing forms and tracking pixels in a logged email stay inert, and remote content is not even requested until you allow it.
* **The log cannot be used to take over accounts.** One-time secrets in links (password resets, activation keys, order keys) are masked *before* they are written to the database.
* **It explains problems instead of just listing them.** Every email is checked for issues that keep it from arriving or displaying correctly, and failed emails come with a plain-language explanation and concrete steps.
* **It records what was actually sent** – the final body after template plugins, the real sender, the SMTP server or API plugin that delivered it, and the exact code that called `wp_mail()`.
* **Built for large logs:** an indexed table, live search, two small queries per email. No upsells, no external services, no tracking.

= Find any email in seconds =

* Live search over recipient and subject, optionally also the content.
* Status tabs with counts (sent, failed, held, unknown), date range, sorting, and filters for the sending plugin or theme, HTML/plain text, attachments and notes. Every filter is part of the URL, so a filtered view can be bookmarked.
* Keyboard friendly: `/` jumps to the search, `j`/`k` move between emails, `Esc` closes the preview.
* Export the current view as CSV (Excel-ready, safe against formula injection) or JSON.

= The trail of every email =

* **Trace:** how long sending took, which mailer and SMTP server (host, port, encryption, masked username) or which API plugin delivered it, the file, line and function that called `wp_mail()`, the active hooks and the request (cron, REST, Ajax, admin, front end, CLI – no IP addresses, no query strings).
* **SMTP transcript** for failed emails (optional for all): the conversation with the SMTP server, with login data and message content always masked.
* **Download as .eml** to open any logged email in your mail program.

= Notes: problems found in your emails =

* HTML sent as plain text, Gmail clipping (> 102 KB), relative links and images, development or staging URLs, unreplaced placeholders like `{first_name}` or `%s`, broken encoding (Ã¤), risky sender addresses (wordpress@localhost, gmail.com sent from your server), bulk mail without `List-Unsubscribe`, typos in recipient domains (gmial.com), misleading link texts and more – shown as a badge in the list.
* When you open an entry: whether the recipient domain can receive email at all (MX lookup) and whether the same email was sent several times within minutes.
* **Error messages explained:** PHPMailer errors, SMTP reply codes (421, 450, 535, 550, 554 …), Gmail and Microsoft 365 login problems and API mailer errors (invalid key, unverified domain, quota) with step-by-step fixes.

= Statistics, charts and alerts =

* Emails over time (sent / failed / held), failure rate, a weekday × hour heatmap and the top senders, recipient domains and subjects – click any bar to open those emails. Accessible charts (keyboard, data tables), no external libraries.
* Dashboard widget with the last 7 days.
* **Monitoring alerts (opt-in):** get notified by email or webhook (Slack, Discord or any JSON endpoint) when emails fail repeatedly or when your site unexpectedly stops sending emails.

= Deliverability and staging =

* **Sender check:** SPF, DKIM, DMARC and MX of the domains your site sends from, with a traffic light per record and ready-to-paste record suggestions.
* **Staging mode:** hold every email (log only) or redirect all emails to test addresses on staging and development copies – a clear warning shows while it is active, held emails can be sent one by one.
* **Send to another address**, e.g. to forward a lost order confirmation, or resend the original.

= Bring your old log along =

Switching from another plugin? Mailspur imports existing logs of WP Mail Logging, Email Log, Check & Log Email, FluentSMTP, Post SMTP, SureMail, WP Mail Catcher and WP Mail Log. The import can be resumed and repeated, skips emails that are already in the log, converts time zones correctly and masks secrets in old emails as well. The other plugin's data is only read, never changed.

= Privacy =

The log contains personal data (recipients and content of emails). Mailspur:

* deletes entries automatically after a configurable retention period (default: 90 days) and/or above a maximum number of entries – or anonymises them first, keeping only what the statistics need,
* integrates with the WordPress personal data export and erasure tools,
* suggests a paragraph for your privacy policy,
* contacts no external service. The only exception is a webhook URL you enter yourself for monitoring alerts (off by default), which receives a short alert text – never email contents.

= External services =

Mailspur works without any external service. There is exactly one optional exception:

* **Alert webhook (off by default).** If you enable monitoring alerts and enter a webhook URL, your site sends a short JSON POST to exactly that URL when an alert fires (or when you click "Send test alert"): site name, site URL, the alert text, a link to the log and a timestamp – never email contents or recipient addresses. Where the data goes depends on the URL you enter. For Slack see the [terms](https://slack.com/terms-of-service) and [privacy policy](https://slack.com/privacy-policy), for Discord the [terms](https://discord.com/terms) and [privacy policy](https://discord.com/privacy).

The sender check (SPF/DKIM/DMARC/MX) and the recipient-domain check only ask your server's own DNS resolver, on demand, and contact no third-party service.

= For developers =

* WP-CLI: `wp mailspur list|show|resend|stats|purge|export|import`.
* Filters: `mailspur_should_log` (skip logging an email), `mailspur_redact_params` (masked URL parameters), `mailspur_import_duplicate_window` (seconds), `mailspur_note_rules` and `mailspur_note_texts` (own checks), `mailspur_staging_subject`.

= Source code =

Development happens on GitHub: https://github.com/lcswll/wordpress-mails – issues and pull requests are welcome.

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

= Does the SMTP transcript contain my SMTP password? =

No. Everything sent after "AUTH" (usernames, passwords, OAuth tokens) and the server's login challenges are replaced with [hidden], and the message content is omitted.

= What is the difference between the reconstructed and the exact .eml file? =

By default the .eml file is rebuilt from the logged data: secrets such as password-reset keys stay redacted and attachments are not included. If you enable "Store the exact raw MIME source" (and confirm the warning), the message is stored exactly as it was sent – not redacted, and roughly doubling the storage per email.

= What does staging mode do? =

"Log only" logs every email but delivers none (status "Held"). "Redirect" delivers every email to the addresses you enter instead of the real recipients; Cc and Bcc are removed and the subject shows the original recipients. Held emails can be sent individually with "Send now". On sites whose environment type is not "production", Mailspur suggests turning it on.

= Does the sender check contact an external service? =

No. It only asks your server's own DNS resolver for the SPF, DMARC, DKIM and MX records of your sender domains, and only when an administrator clicks "Check sender domains". If no DKIM key is found although your emails are signed, enter your selector (the s= value of the DKIM-Signature header) and check again.

= How does the "silence" alert avoid false alarms at night or on weekends? =

It only alerts when the last 14 days show that your site normally sends emails in that time window (on at least 10 of 14 days, including the same weekday one and two weeks ago).

= Can I keep my history when switching from WP Mail Logging or another log plugin? =

Yes. Under Mail Log → Settings → "Import from other plugins" Mailspur lists every supported log it finds on the site (also from deactivated plugins) and imports it with one click. Entries older than your retention period are skipped, so raise the retention first if you want the complete history.

= What if two plugins logged the same email, or Mailspur already logged it? =

It is imported only once. Before each entry is imported, Mailspur looks for the same recipients and subject sent within two minutes from another source – Mailspur itself or another import. Mailspur's own entry always wins because it is the most accurate one.

= What does "Anonymise entries after" do? =

Once an entry is older than the configured number of days, the daily cleanup removes its content, headers, attachments and module data and masks the addresses (a***@example.com). Date, status, source, size and notes are kept, so statistics keep working. Anonymised entries cannot be resent.

= Are attachments stored? =

No. Only the file names are logged. When you resend an email, attachments are re-attached only if the original files still exist on the server.

= Does it slow down my site? =

No. Nothing runs on normal page views. Each sent email costs two small database queries; statistics are computed only when you open them and are cached.

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

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
