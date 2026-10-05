<?php
/**
 * Human-readable texts of the note codes. Only codes and params are stored with a mail; the texts
 * are looked up when the log is displayed, so they follow the current user's language.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Notes;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	/** @var array<string,array{title:string,text:string,fix:string}>|null */
	private static $texts;

	/**
	 * Every known code with title, explanation and how to fix it. Placeholders %1$s, %2$s … are
	 * filled with the note's params.
	 *
	 * @return array<string,array{title:string,text:string,fix:string}>
	 */
	public static function texts(): array {
		if ( null !== self::$texts ) {
			return self::$texts;
		}
		$texts = array(
			'html_in_plain'        => array(
				'title' => __( 'HTML sent as plain text', 'mailspur-email-log' ),
				'text'  => __( 'The body contains HTML tags, but the email was sent as text/plain (the WordPress default). Recipients see the raw tags instead of formatted text.', 'mailspur-email-log' ),
				'fix'   => __( 'Send the email with the header "Content-Type: text/html; charset=UTF-8" or set the content type with the wp_mail_content_type filter. Many form and shop plugins have an "HTML email" option.', 'mailspur-email-log' ),
			),
			'gmail_clip'           => array(
				/* translators: %1$s: size in KB */
				'title' => __( 'Gmail will clip this email (%1$s KB)', 'mailspur-email-log' ),
				'text'  => __( 'Gmail cuts off HTML emails larger than 102 KB and shows "[Message clipped]". Everything after the cut – often the footer, unsubscribe link or legal notice – is hidden.', 'mailspur-email-log' ),
				'fix'   => __( 'Reduce the HTML: remove unused CSS, inline styles that repeat, comments and long tracking URLs. Images are not counted, only the HTML itself.', 'mailspur-email-log' ),
			),
			'relative_urls'        => array(
				/* translators: 1: number of links, 2: example, e.g. "/wp-content/uploads/logo.png" */
				'title' => __( 'Relative links or images (%1$s), e.g. %2$s', 'mailspur-email-log' ),
				'text'  => __( 'Links and images starting with "/" only work on the website. In a mail client there is no website address to complete them, so images stay empty and links lead nowhere.', 'mailspur-email-log' ),
				'fix'   => __( 'Use absolute URLs including https:// and the domain, e.g. with home_url() or wp_get_attachment_url() in the template.', 'mailspur-email-log' ),
			),
			'dev_url'              => array(
				/* translators: %s: host name, e.g. "localhost" */
				'title' => __( 'Link to a development address: %1$s', 'mailspur-email-log' ),
				'text'  => __( 'This production site sent a link to a local or test address. Recipients cannot open it.', 'mailspur-email-log' ),
				'fix'   => __( 'Search the email template, plugin settings and database for the old address (e.g. after migrating the site) and replace it with the live domain.', 'mailspur-email-log' ),
			),
			'foreign_wp_host'      => array(
				/* translators: 1: other host, 2: this site's host */
				'title' => __( 'Links to another copy of the site: %1$s (this site: %2$s)', 'mailspur-email-log' ),
				'text'  => __( 'The email links to WordPress files on a related host. This typically happens on a staging copy that still contains URLs of the live site – or the other way round.', 'mailspur-email-log' ),
				'fix'   => __( 'Run a search & replace of the old domain in the database and check hard-coded URLs in email templates and plugin settings.', 'mailspur-email-log' ),
			),
			'placeholder'          => array(
				/* translators: %s: placeholders found, e.g. "{first_name}, %s" */
				'title' => __( 'Unreplaced placeholders: %1$s', 'mailspur-email-log' ),
				'text'  => __( 'The email contains template variables that were not filled in. Recipients see them literally.', 'mailspur-email-log' ),
				'fix'   => __( 'Check the spelling of the placeholder in the template and whether the plugin supports it for this email type. Shortcodes are not processed in emails unless the sending plugin runs do_shortcode().', 'mailspur-email-log' ),
			),
			'mojibake'             => array(
				/* translators: %s: broken characters, e.g. "Ã¤" */
				'title' => __( 'Broken characters (encoding): %1$s', 'mailspur-email-log' ),
				'text'  => __( 'Umlauts or quotes appear as garbled characters such as "Ã¤" or "â€™". UTF-8 text was read as a different character set somewhere on the way – in a template, an import or the database.', 'mailspur-email-log' ),
				'fix'   => __( 'Make sure the email is sent with "charset=UTF-8", template files are saved as UTF-8 and the database tables use utf8mb4. Fix texts that were already stored broken.', 'mailspur-email-log' ),
			),
			'no_from'              => array(
				'title' => __( 'No sender address recorded', 'mailspur-email-log' ),
				'text'  => __( 'Neither a From header nor a sender was recorded. The mailer falls back to its default, often wordpress@yourdomain.', 'mailspur-email-log' ),
				'fix'   => __( 'Set a sender address on your domain in your SMTP plugin or via the wp_mail_from filter.', 'mailspur-email-log' ),
			),
			'from_localhost'       => array(
				/* translators: %s: sender address */
				'title' => __( 'Invalid sender address: %1$s', 'mailspur-email-log' ),
				'text'  => __( 'The sender has no public domain (e.g. wordpress@localhost). Most mail servers reject such emails or put them in spam.', 'mailspur-email-log' ),
				'fix'   => __( 'Set a sender address on your real domain, e.g. in your SMTP plugin or via the wp_mail_from filter, and check the site address under Settings → General.', 'mailspur-email-log' ),
			),
			'from_free_mailer'     => array(
				/* translators: %1$s: sender address */
				'title' => __( 'Sent as a free mail address: %1$s', 'mailspur-email-log' ),
				/* translators: %2$s: domain of the free mailer, e.g. "gmail.com" */
				'text'  => __( 'Providers like %2$s publish a strict DMARC policy. An email "from" their domain that was sent by your web server fails that check and is rejected or lands in spam.', 'mailspur-email-log' ),
				'fix'   => __( 'Use a sender address on your own domain and put the free address in Reply-To. Only if you really send through that provider\'s own SMTP server with its login is this fine.', 'mailspur-email-log' ),
			),
			'from_domain_mismatch' => array(
				/* translators: 1: sender domain, 2: site domain */
				'title' => __( 'Sender domain %1$s differs from the site (%2$s)', 'mailspur-email-log' ),
				'text'  => __( 'That is fine if your mail server is allowed to send for that domain. Otherwise SPF and DMARC fail and the email lands in spam.', 'mailspur-email-log' ),
				'fix'   => __( 'Check the SPF, DKIM and DMARC records of the sender domain, or use a sender address on the domain you send from.', 'mailspur-email-log' ),
			),
			'bulk_no_unsubscribe'  => array(
				/* translators: %s: number of recipients */
				'title' => __( 'Bulk email without unsubscribe header (%1$s recipients)', 'mailspur-email-log' ),
				'text'  => __( 'Since 2024 Gmail and Yahoo require a one-click unsubscribe (List-Unsubscribe header) for bulk mail. Without it newsletters are more likely to be rejected or filtered.', 'mailspur-email-log' ),
				'fix'   => __( 'Send newsletters with a newsletter plugin or service that adds List-Unsubscribe and List-Unsubscribe-Post headers, and do not send to many people in one email via Bcc.', 'mailspur-email-log' ),
			),
			'recipient_typo'       => array(
				/* translators: 1: recipient domain, 2: suggested domain */
				'title' => __( 'Typo in recipient domain? %1$s → %2$s', 'mailspur-email-log' ),
				'text'  => __( 'The recipient domain looks like a misspelling of a popular email provider. Such emails bounce – or reach a stranger who registered the typo domain.', 'mailspur-email-log' ),
				'fix'   => __( 'Correct the address (e.g. in the user profile or order) and consider an email validation in your forms.', 'mailspur-email-log' ),
			),
			'link_mismatch'        => array(
				/* translators: 1: domain shown in the link text, 2: actual link target */
				'title' => __( 'Link text shows %1$s but leads to %2$s', 'mailspur-email-log' ),
				'text'  => __( 'Links whose visible address differs from the real target look like phishing. Spam filters penalize them and some mail clients warn the reader.', 'mailspur-email-log' ),
				'fix'   => __( 'Use a descriptive link text (e.g. "View order") or show the address the link really leads to.', 'mailspur-email-log' ),
			),
			'subject_empty'        => array(
				'title' => __( 'Empty subject', 'mailspur-email-log' ),
				'text'  => __( 'Emails without a subject look like spam and are hard to find in the inbox.', 'mailspur-email-log' ),
				'fix'   => __( 'Set a subject in the template or form settings of the sending plugin.', 'mailspur-email-log' ),
			),
			'subject_long'         => array(
				/* translators: %s: number of characters */
				'title' => __( 'Very long subject (%1$s characters)', 'mailspur-email-log' ),
				'text'  => __( 'Inboxes show roughly the first 40–80 characters; the rest is cut off, on phones even earlier.', 'mailspur-email-log' ),
				'fix'   => __( 'Put the important information first and keep the subject under about 80 characters.', 'mailspur-email-log' ),
			),
			'img_no_alt'           => array(
				/* translators: %s: number of images */
				'title' => __( 'Images without alt text (%1$s)', 'mailspur-email-log' ),
				'text'  => __( 'Many mail clients block images until the reader allows them. Without alt text the reader only sees empty boxes; screen readers announce nothing useful.', 'mailspur-email-log' ),
				'fix'   => __( 'Add a short alt attribute to every image in the template, e.g. alt="Logo Example Shop".', 'mailspur-email-log' ),
			),
			'secret_password'      => array(
				'title' => __( 'Password sent in plain text', 'mailspur-email-log' ),
				'text'  => __( 'The email contains a password in readable form. Emails stay in inboxes, backups and forwarded threads for years – anyone who gets hold of one of them can log in. The email log stores it as well, unless "Redact secrets" is on (then the log keeps a masked copy, but the recipient still got the password).', 'mailspur-email-log' ),
				'fix'   => __( 'Send a link where the user sets a password instead – WordPress core\'s new-user email does exactly that. Look for an option such as "Send password by email" or "Include password" in the plugin that sent this email and turn it off.', 'mailspur-email-log' ),
			),
			'secret_key'           => array(
				/* translators: %s: masked key, e.g. "sk_live_…4f2a" */
				'title' => __( 'API key or private key in plain text: %1$s', 'mailspur-email-log' ),
				'text'  => __( 'The email contains an access key (e.g. for Stripe, GitHub, Slack, AWS, Google or SendGrid) or a private key. Whoever reads the email – now or years later in an inbox or backup – can use it. The email log stores it as well, unless "Redact secrets" is on.', 'mailspur-email-log' ),
				'fix'   => __( 'Revoke the key in the service\'s dashboard and create a new one. Then find out why it ended up in an email (debug or error reports, a form that echoes settings) and stop that.', 'mailspur-email-log' ),
			),
			'secret_card'          => array(
				/* translators: %s: last four digits, e.g. "…1111" */
				'title' => __( 'Payment card number in plain text: %1$s', 'mailspur-email-log' ),
				'text'  => __( 'The email contains what looks like a full payment card number (it passes the check-digit test). Card numbers must never be sent by email (PCI DSS): inboxes keep them for years. The email log stores it as well, unless "Redact secrets" is on.', 'mailspur-email-log' ),
				'fix'   => __( 'Remove the card field from the notification of the form or plugin that sent this email – card data belongs to the payment provider only. Show at most the last four digits. Ask the recipient to delete the email.', 'mailspur-email-log' ),
			),
			'open_recipients'      => array(
				/* translators: %s: number of recipients outside the site's domain */
				'title' => __( 'Open distribution list: %1$s external recipients see each other\'s addresses', 'mailspur-email-log' ),
				'text'  => __( 'The email went to several people outside your domain in To or Cc. Every recipient sees all other addresses. With customers or members this discloses personal data to strangers – under the GDPR usually a data breach.', 'mailspur-email-log' ),
				'fix'   => __( 'Send such emails to each recipient individually or put the recipients in Bcc. For newsletters use a newsletter plugin that sends one email per recipient. If customers\' addresses were disclosed, check with your data protection officer whether the incident has to be reported.', 'mailspur-email-log' ),
			),
			'subject_caps'         => array(
				'title' => __( 'Subject mostly in capital letters', 'mailspur-email-log' ),
				'text'  => __( 'Subjects in capitals read like shouting and are common in spam. Spam filters may count this against the email, which can make delivery worse.', 'mailspur-email-log' ),
				'fix'   => __( 'Write the subject in normal upper and lower case and emphasize at most a single word.', 'mailspur-email-log' ),
			),
			'subject_exclamations' => array(
				/* translators: %s: number of exclamation marks */
				'title' => __( 'Many exclamation marks in the subject (%1$s)', 'mailspur-email-log' ),
				'text'  => __( 'Several exclamation marks are typical of advertising spam. Spam filters may count this against the email, which can make delivery worse.', 'mailspur-email-log' ),
				'fix'   => __( 'Use one exclamation mark at most.', 'mailspur-email-log' ),
			),
			'image_only'           => array(
				'title' => __( 'Email consists almost only of images', 'mailspur-email-log' ),
				'text'  => __( 'The HTML email contains images but hardly any text. Spam filters cannot read images and distrust such emails, which can make delivery worse. If the mail client blocks images, the reader sees an almost empty email.', 'mailspur-email-log' ),
				'fix'   => __( 'Put the important content into real text and use images only in addition to it.', 'mailspur-email-log' ),
			),
			'link_shortener'       => array(
				/* translators: %s: shortener domain, e.g. "bit.ly" */
				'title' => __( 'Link via a URL shortener: %1$s', 'mailspur-email-log' ),
				'text'  => __( 'Shortened links hide where they lead. Spammers use them a lot, so spam filters may count them against the email, which can make delivery worse.', 'mailspur-email-log' ),
				'fix'   => __( 'Link to the full address directly. For click tracking, use UTM parameters on your own domain instead.', 'mailspur-email-log' ),
			),
			'dead_link'            => array(
				/* translators: %s: path of the link, e.g. "/old-page/" */
				'title' => __( 'Link to a page that does not exist on your site: %1$s', 'mailspur-email-log' ),
				'text'  => __( 'The email links to an address on this site that answers "page not found" (404). This typically happens after a page or product was renamed or deleted: the template still points to the old address and every recipient lands on an error page.', 'mailspur-email-log' ),
				'fix'   => __( 'Update the link in the email template or in the settings of the plugin that sends this email, or add a redirect from the old address to the new page (e.g. with a redirection plugin).', 'mailspur-email-log' ),
			),
			'no_mx'                => array(
				/* translators: %s: domain */
				'title' => __( 'Recipient domain cannot receive email: %1$s', 'mailspur-email-log' ),
				'text'  => __( 'The domain has neither an MX nor an A record in DNS. Emails to it bounce. Usually the address is misspelled or the domain no longer exists.', 'mailspur-email-log' ),
				'fix'   => __( 'Check the address with the recipient and correct it where it is stored (user profile, order, form entry).', 'mailspur-email-log' ),
			),
			'null_mx'              => array(
				/* translators: %s: domain */
				'title' => __( 'Recipient domain does not accept email: %1$s', 'mailspur-email-log' ),
				'text'  => __( 'The domain publishes a "null MX" record, which explicitly says that it receives no email. Every email to it bounces.', 'mailspur-email-log' ),
				'fix'   => __( 'Ask the recipient for a working address.', 'mailspur-email-log' ),
			),
			'duplicate'            => array(
				/* translators: 1: number of other emails, 2: minutes */
				'title' => __( 'Sent repeatedly: %1$s more identical emails within %2$s minutes', 'mailspur-email-log' ),
				'text'  => __( 'The same subject went to the same recipients several times in a short time. Typical causes are double order confirmations, a form submitted twice, a cron job running in parallel or a loop in a plugin.', 'mailspur-email-log' ),
				'fix'   => __( 'Compare the sources of these entries. Check whether two plugins send the same notification, or whether a hook (e.g. order status change) fires more than once.', 'mailspur-email-log' ),
			),
		);

		/**
		 * Texts for note codes of custom rules (see mailspur_note_rules):
		 * $texts['my_code'] = array( 'title' => …, 'text' => …, 'fix' => … ).
		 *
		 * @param array<string,array{title:string,text:string,fix:string}> $texts
		 */
		self::$texts = self::normalize( apply_filters( 'mailspur_note_texts', $texts ) );
		return self::$texts;
	}

	/**
	 * @param mixed $texts Filtered texts (may come from third-party code).
	 * @return array<string,array{title:string,text:string,fix:string}>
	 */
	private static function normalize( $texts ): array {
		$out = array();
		foreach ( is_array( $texts ) ? $texts : array() as $code => $entry ) {
			if ( is_string( $code ) && is_array( $entry ) ) {
				$out[ $code ] = array(
					'title' => (string) ( $entry['title'] ?? $code ),
					'text'  => (string) ( $entry['text'] ?? '' ),
					'fix'   => (string) ( $entry['fix'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/** Forgets the cached texts (e.g. after switching the locale). */
	public static function reset(): void {
		self::$texts = null;
	}

	/**
	 * A stored note with its texts in the current language.
	 *
	 * @param array{code:string,severity:string,params:array<int,string>} $note
	 * @return array{code:string,severity:string,title:string,text:string,fix:string}
	 */
	public static function render( array $note ): array {
		$texts  = self::texts();
		$entry  = $texts[ $note['code'] ] ?? array(
			'title' => $note['code'],
			'text'  => '',
			'fix'   => '',
		);
		$params = array_pad( $note['params'], 5, '' );
		return array(
			'code'     => $note['code'],
			'severity' => $note['severity'],
			'title'    => self::fill( $entry['title'], $params ),
			'text'     => self::fill( $entry['text'], $params ),
			'fix'      => self::fill( $entry['fix'], $params ),
		);
	}

	/**
	 * Replaces %1$s … %5$s and plain %s (in order). Unlike vsprintf() it never throws on a broken
	 * placeholder in a (third-party) translation, and a literal "%" stays as it is.
	 *
	 * @param array<int,string> $params
	 */
	private static function fill( string $text, array $params ): string {
		if ( false === strpos( $text, '%' ) ) {
			return $text;
		}
		$next = 0;
		return (string) preg_replace_callback(
			'/%(?:([1-9])\$)?s/',
			static function ( array $m ) use ( $params, &$next ): string {
				$index = isset( $m[1] ) ? (int) $m[1] - 1 : $next++; // A trailing unmatched group is absent.
				return $params[ $index ] ?? '';
			},
			$text
		);
	}
}
