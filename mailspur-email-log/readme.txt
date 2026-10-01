=== Mailspur – Email Log ===
Contributors: lcswll
Tags: email log, mail log, wp_mail, smtp, email
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Logs every email WordPress sends. Search, filter, preview and resend mails safely – fast, lightweight and without tracking.

== Description ==

Did the order confirmation go out? Why did the password reset never arrive? Mailspur records every email sent through `wp_mail()` – no matter which plugin triggered it or which SMTP or API mailer delivers it – and shows it in a fast, filterable log.

= What makes it different =

Most email logs treat the log as a simple archive. Mailspur treats it as sensitive data and a potential attack surface:

* **Logged emails cannot attack you.** HTML emails are shown in a sandboxed frame with an opaque origin: injected scripts, phishing forms and tracking pixels in a logged email stay inert. Many logs render stored HTML directly in the admin area.
* **The log cannot be used to take over accounts.** One-time secrets in links (password resets, activation keys, order keys) are masked *before* they are written to the database.
* **It records what was actually sent** – the final body after template plugins, the real sender and content type – and which plugin or theme sent the email, also for API mailers that bypass PHPMailer.
* **Built for large logs:** an indexed table, live search and status counts in a single query, retention in small batches. No upsells, no external services, no tracking.

= Find any email in seconds =

* Live search over recipient and subject, optionally also the content.
* Status tabs with counts: sent, failed, unknown.
* Date range, sorting, pagination. Filters are part of the URL, so a filtered view can be bookmarked or shared with a colleague.
* Keyboard friendly: `/` jumps to the search, `j`/`k` move between emails, `Esc` closes the preview.

= See exactly what was sent =

* Preview, source and headers of every email.
* Records the body that is actually handed to PHPMailer – including changes made by email template plugins.
* Shows the real sender, content type, attachments and which plugin or theme sent the email.
* Delivery status and error message, also for mailers that bypass PHPMailer via `pre_wp_mail`.
* Resend an email or delete entries – one by one, in bulk or all at once.

= Safe by design =

* HTML emails are rendered in a sandboxed frame without scripts, forms or access to your admin area.
* Remote images and fonts are blocked until you allow them, so tracking pixels cannot see when you open an entry.
* Password-reset, activation and access keys in links are masked before they are stored, so the log cannot be used to take over accounts.
* Only administrators see the log by default. You can grant access to editors or shop managers.

= Fast and lightweight =

* Its own indexed database table; the list never loads email bodies.
* Two small queries per sent email, nothing extra on normal page views.
* No external services, no tracking, no ads, no upsells. Scripts and styles load only on the log screen.

= Privacy =

The log contains personal data (recipients and content of emails). Mailspur:

* deletes entries automatically after a configurable retention period (default: 90 days) and/or above a maximum number of entries,
* integrates with the WordPress personal data export and erasure tools (Tools → Export/Erase Personal Data),
* suggests a paragraph for your privacy policy (Settings → Privacy),
* sends no data anywhere.

= For developers =

* `mailspur_should_log` (filter, bool, `$atts`) – return false to skip logging a specific email.
* `mailspur_redact_params` (filter, string[]) – URL query parameters whose values are masked.

= Source code =

Development happens on GitHub: https://github.com/lcswll/wordpress-mails – issues and pull requests are welcome.

== Installation ==

1. Install the plugin via Plugins → Add New (search for "Mailspur Email Log") or upload the ZIP file.
2. Activate it. Logging starts immediately.
3. Open "Mail Log" in the admin menu. Adjust retention and access under the "Settings" tab.

== Frequently Asked Questions ==

= Does it work with my SMTP plugin? =

Yes. Mailspur hooks into `wp_mail()` itself, so it works with any SMTP or API mailer that uses WordPress' mail function – including those that send through `pre_wp_mail`.

= Can the log be used to hijack accounts via logged password-reset links? =

Not with the default settings. Secret keys in links (`key=`, `token=` and similar) are replaced with `[redacted]` before the email is stored. If you resend such an email, the masked version is sent.

= Why are images missing in the preview? =

Remote images and fonts are blocked on purpose: otherwise every preview would tell the sender's tracking service that the email was opened. Click "Load remote content" above the preview, or enable "Always load remote images" in the settings.

= Are attachments stored? =

No. Only the file names are logged. When you resend an email, attachments are re-attached only if the original files still exist on the server.

= Does it slow down my site? =

No. Nothing runs on normal page views. Each sent email costs two small database queries, and old entries are removed in small batches once a day.

= What happens when I delete the plugin? =

By default the log table and all settings are removed. You can keep the data by unchecking "Delete the log table and settings when the plugin is deleted" before deleting the plugin.

= Does it support multisite? =

Yes. Each site keeps its own log.

== Screenshots ==

1. The log: live search, status tabs with counts, date filter and sortable columns.
2. Email preview in a sandboxed frame with remote content blocked.
3. Settings: access, privacy and retention.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
