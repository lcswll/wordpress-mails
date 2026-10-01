=== Outbox – Mail Log ===
Contributors: lcswll02
Tags: email log, mail log, wp_mail, smtp, email
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Logs every outgoing email. Search, filter and inspect mails safely – fast, lightweight, no tracking.

== Description ==

Outbox records every email sent through `wp_mail()` – no matter which plugin or SMTP/API mailer delivers it – and shows it in a fast, filterable log.

* Live search over recipient and subject (optionally content), status tabs with counts, date range, sorting, pagination – filters are kept in the URL.
* Detail view with Preview, Source and Headers, keyboard navigation (j/k, Esc, "/" to search).
* Accurate: records the body PHPMailer actually sends (template plugins that rewrite the body in `phpmailer_init` are respected), the real content type and sender, and which plugin or theme triggered the mail.
* Correct delivery status, including mailers that short-circuit via `pre_wp_mail`.
* Resend and delete (single, bulk, all).
* Retention by age and/or maximum entries (daily cron, batched deletes).
* GDPR: personal data exporter/eraser and privacy policy text.

= Security =

* HTML mails render in a sandboxed iframe with an opaque origin: no scripts, no forms, no access to wp-admin.
* Remote images/fonts are blocked by a Content Security Policy until you allow them – tracking pixels can't see you open an entry.
* Password-reset, activation and access keys in links are masked before they are stored (configurable).
* Mail data is only inserted into the admin page as text, never as HTML.
* All queries are prepared, ORDER BY is whitelisted, REST endpoints check capability and nonce. Server file paths of attachments never reach the browser; resend only re-attaches files inside the WordPress installation.

= Performance =

* Own table with indexes; the list never loads message bodies.
* One grouped query provides the tab counts and the total.
* Two queries per sent mail (insert + update), nothing on normal page loads besides one autoloaded option.
* No dependencies, no build step, assets only on the plugin screen.

= Developer hooks =

* `outbox_should_log` (filter, bool, `$atts`) – skip logging individual mails.
* `outbox_redact_params` (filter, string[]) – query parameters whose values are masked.

== Changelog ==

= 1.0.0 =
* Initial release.
