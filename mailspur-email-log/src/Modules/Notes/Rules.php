<?php
/**
 * Built-in static rules. They run while a mail is logged (and for imported mails), so they are pure:
 * no network, no database, only string checks on the (capped) mail – keep every rule cheap.
 *
 * A rule is any callable( Mail $mail ): array returning zero or more notes created with Rules::note().
 * Add your own via the mailspur_note_rules filter (see Engine::rules()).
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Notes;

use Mailspur\Secrets;

defined( 'ABSPATH' ) || exit;

final class Rules {

	const ERROR   = 'error';
	const WARNING = 'warning';
	const INFO    = 'info';

	/** Gmail clips HTML bodies larger than 102 KB ("[Message clipped]"). */
	const GMAIL_CLIP_BYTES = 104448;

	/** Subjects longer than this are cut off in most inboxes. */
	const SUBJECT_MAX = 100;

	/** Recipients (To + Cc + Bcc) from which a mail counts as bulk. */
	const BULK_RECIPIENTS = 20;

	/** Bcc addresses from which a mail counts as bulk. */
	const BULK_BCC = 10;

	/** Outside parties in To/Cc from which recipients reveal each other's addresses. */
	const OPEN_PARTIES = 2;

	/** Letters a subject needs before "mostly capitals" counts ("OK", "FYI" never do). */
	const CAPS_MIN_LETTERS = 10;

	/** Share of capital letters from which a subject counts as written in capitals. */
	const CAPS_RATIO = 0.8;

	/** Exclamation marks in the subject from which it looks like advertising. */
	const SUBJECT_EXCLAMATIONS = 3;

	/** Visible letters below which an HTML email with images counts as "only an image". */
	const IMAGE_ONLY_LETTERS = 40;

	/** Public URL shorteners: they hide the link target, so spam filters distrust them. */
	const SHORTENERS = array( 'bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly', 'rebrand.ly', 'cutt.ly', 'shorturl.at', 'tiny.cc', 'rb.gy', 't.ly', 'v.gd', 'bl.ink', 'lnkd.in', 's.id' );

	/**
	 * Built-in rules, keyed by id.
	 *
	 * @return array<string,callable(Mail):array<int,array{code:string,severity:string,params:array<int,string>}>>
	 */
	public static function defaults(): array {
		return array(
			'html_in_plain'    => array( self::class, 'html_in_plain' ),
			'gmail_clip'       => array( self::class, 'gmail_clip' ),
			'relative_urls'    => array( self::class, 'relative_urls' ),
			'dev_urls'         => array( self::class, 'dev_urls' ),
			'placeholders'     => array( self::class, 'placeholders' ),
			'mojibake'         => array( self::class, 'mojibake' ),
			'sender'           => array( self::class, 'sender' ),
			'bulk_unsubscribe' => array( self::class, 'bulk_unsubscribe' ),
			'recipient_typos'  => array( self::class, 'recipient_typos' ),
			'link_mismatch'    => array( self::class, 'link_mismatch' ),
			'subject'          => array( self::class, 'subject' ),
			'img_alt'          => array( self::class, 'img_alt' ),
			'secrets'          => array( self::class, 'secrets' ),
			'open_recipients'  => array( self::class, 'open_recipients' ),
			'spam_signs'       => array( self::class, 'spam_signs' ),
		);
	}

	/**
	 * @param array<int,string|int> $params Values for the placeholders of the note's text.
	 * @return array{code:string,severity:string,params:array<int,string>}
	 */
	public static function note( string $code, string $severity, array $params = array() ): array {
		return array(
			'code'     => $code,
			'severity' => $severity,
			'params'   => array_map( 'strval', array_values( $params ) ),
		);
	}

	/**
	 * Sent as text/plain (WordPress' default) but the body contains HTML: recipients see raw tags.
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function html_in_plain( Mail $mail ): array {
		if ( false === strpos( $mail->content_type, 'text/plain' ) ) {
			return array();
		}
		$tags = preg_match_all( '#</?(?:html|body|head|p|br|div|span|table|tr|td|a|b|strong|em|i|u|h[1-6]|ul|ol|li|img|font|center)\b[^>]*>#i', $mail->body );
		return $tags >= 2 ? array( self::note( 'html_in_plain', self::ERROR ) ) : array();
	}

	/**
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function gmail_clip( Mail $mail ): array {
		if ( ! $mail->is_html || $mail->size <= self::GMAIL_CLIP_BYTES ) {
			return array();
		}
		return array( self::note( 'gmail_clip', self::WARNING, array( (int) ceil( $mail->size / 1024 ) ) ) );
	}

	/**
	 * href="/…" or src="/wp-content/…" only work on the website, not in a mail client.
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function relative_urls( Mail $mail ): array {
		if ( ! $mail->is_html ) {
			return array();
		}
		$n = preg_match_all( '#\s(?:href|src|background)\s*=\s*(["\']?)\s*((?:\.{1,2})?/(?!/)[^"\'\s>]*)#i', $mail->text(), $m );
		if ( ! $n ) {
			return array();
		}
		return array( self::note( 'relative_urls', self::ERROR, array( $n, self::clip( $m[2][0] ) ) ) );
	}

	/**
	 * Links to development hosts (localhost, *.local, *.test …) from a production site, or links to a
	 * related WordPress host (staging copy ↔ live site).
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function dev_urls( Mail $mail ): array {
		$home = self::bare_host( $mail->site['home'] );
		if ( ! preg_match_all( '#\bhttps?://([a-z0-9.-]+|\[[0-9a-f:]+\])(?::\d+)?(/[^\s"\'<>]*)?#i', $mail->text(), $m, PREG_SET_ORDER ) ) {
			return array();
		}

		$notes = array();
		$dev   = '';
		$other = '';
		foreach ( array_slice( $m, 0, 200 ) as $match ) {
			$host = strtolower( $match[1] );
			$bare = self::bare_host( $host );
			if ( $bare === $home ) {
				continue;
			}
			if ( '' === $dev && preg_match( '/^(?:localhost|127\.\d+\.\d+\.\d+|\[::1\]|0\.0\.0\.0)$|\.(?:local|test|localhost|invalid)$/', $host ) ) {
				$dev = $host;
			}
			$path = (string) ( $match[2] ?? '' );
			if ( '' === $other && '' !== $home && preg_match( '#^/(?:wp-content|wp-admin|wp-includes|wp-json)/|^/wp-login\.php#', $path ) && self::related_hosts( $home, $bare ) ) {
				$other = $host;
			}
		}

		if ( '' !== $dev && 'production' === $mail->site['environment'] ) {
			$notes[] = self::note( 'dev_url', self::ERROR, array( $dev ) );
		}
		// Subdomain multisites link between related hosts on purpose.
		if ( '' !== $other && ! $mail->site['multisite'] ) {
			$notes[] = self::note( 'foreign_wp_host', self::WARNING, array( $other, $home ) );
		}
		return $notes;
	}

	/**
	 * Unreplaced template variables: {{name}}, {first_name}, %s / %1$s, [order_number].
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function placeholders( Mail $mail ): array {
		$text  = $mail->subject . "\n" . ( $mail->is_html ? (string) preg_replace( '/<[^>]+>/', ' ', $mail->text() ) : $mail->body );
		$found = array();
		/*
		 * In this order: double-brace variables, single-brace tokens with underscores allowed,
		 * printf placeholders (case-sensitive, so URL encoding like %3D never matches), bracket tokens
		 * that contain an underscore (order_number – plain words like a site name in brackets do not
		 * count), and shortcodes with attributes that were never processed.
		 */
		$res = array(
			'/\{\{\s*[\w.|: -]{1,40}?\s*\}\}/u',
			'/\{[a-z][a-z0-9]*(?:_[a-z0-9]+)*\}/',
			'/(?<![\w%])%(?:\d\$)?[sd](?![\w])/',
			'/\[\/?[a-z][a-z0-9]*(?:_[a-z0-9]+)+\]/',
			'/\[[a-z][a-z0-9_-]*\s+[a-z_-]+=["\'][^\]]{0,100}\]/',
		);
		foreach ( $res as $re ) {
			if ( preg_match_all( $re, $text, $m ) ) {
				foreach ( $m[0] as $token ) {
					$found[ self::clip( $token, 40 ) ] = true;
				}
			}
		}
		if ( ! $found ) {
			return array();
		}
		return array( self::note( 'placeholder', self::WARNING, array( implode( ', ', array_slice( array_keys( $found ), 0, 3 ) ) ) ) );
	}

	/**
	 * UTF-8 text decoded as Latin-1/Windows-1252 somewhere on the way ("Ã¤" instead of "ä").
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function mojibake( Mail $mail ): array {
		$text = $mail->subject . "\n" . $mail->text();
		foreach ( array( 'Ã¤', 'Ã¶', 'Ã¼', 'Ã„', 'Ã–', 'Ãœ', 'ÃŸ', 'Ã©', 'Ã¨', 'Ã¡', 'Ã³', 'Ã±', 'Ã§', 'â€™', 'â€œ', 'â€“', 'â€”', 'â€¦', 'â‚¬', "\u{FFFD}" ) as $needle ) {
			if ( false !== strpos( $text, $needle ) ) {
				return array( self::note( 'mojibake', self::WARNING, array( $needle ) ) );
			}
		}
		return array();
	}

	/**
	 * From address problems that hurt deliverability (SPF/DMARC).
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function sender( Mail $mail ): array {
		if ( '' === $mail->from ) {
			return array( self::note( 'no_from', self::INFO ) );
		}
		$domain = Mail::domain( $mail->from );
		if ( '' === $domain || false === strpos( $domain, '.' ) || preg_match( '/(?:^|\.)(?:localhost|localdomain|local)$|^\[?[\d.:]+\]?$/', $domain ) ) {
			return array( self::note( 'from_localhost', self::ERROR, array( $mail->from ) ) );
		}
		if ( Domains::is_free_mailer( $domain ) ) {
			return array( self::note( 'from_free_mailer', self::WARNING, array( $mail->from, $domain ) ) );
		}
		$home = self::bare_host( $mail->site['home'] );
		if ( '' !== $home && ! Domains::is_reserved( $home ) && ! self::same_site( $home, $domain ) ) {
			return array( self::note( 'from_domain_mismatch', self::INFO, array( $domain, $home ) ) );
		}
		return array();
	}

	/**
	 * Bulk-looking mail without List-Unsubscribe (required by Gmail/Yahoo for bulk senders since 2024).
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function bulk_unsubscribe( Mail $mail ): array {
		if ( isset( $mail->headers['list-unsubscribe'] ) ) {
			return array();
		}
		$total = count( $mail->to ) + count( $mail->copies );
		$bulk  = $total >= self::BULK_RECIPIENTS || $mail->bcc >= self::BULK_BCC
			|| preg_match( '/^plugin:(?:mailpoet|newsletter|mailster|email-subscribers|sendpress|wysija-newsletters|newsletter-optin-box|noptin)$/', $mail->source );
		return $bulk ? array( self::note( 'bulk_no_unsubscribe', self::WARNING, array( $total ) ) ) : array();
	}

	/**
	 * gmial.com, hotmial.com, gmx.dee … – such mails bounce or reach a stranger.
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function recipient_typos( Mail $mail ): array {
		$notes = array();
		foreach ( array_slice( $mail->recipient_domains(), 0, 50 ) as $domain ) {
			$suggestion = Domains::typo_of( $domain );
			if ( '' !== $suggestion ) {
				$notes[] = self::note( 'recipient_typo', self::ERROR, array( $domain, $suggestion ) );
			}
			if ( count( $notes ) >= 3 ) {
				break;
			}
		}
		return $notes;
	}

	/**
	 * Link text shows a domain other than the link target – a classic phishing signal for spam filters.
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function link_mismatch( Mail $mail ): array {
		if ( ! $mail->is_html || ! preg_match_all( '#<a\b[^>]*\shref\s*=\s*["\']?\s*https?://([a-z0-9.-]+)[^>]*>(.*?)</a>#is', $mail->text(), $m, PREG_SET_ORDER ) ) {
			return array();
		}
		foreach ( array_slice( $m, 0, 100 ) as $match ) {
			$label = trim( html_entity_decode( (string) preg_replace( '/<[^>]+>/', '', $match[2] ), ENT_QUOTES, 'UTF-8' ) );
			if ( ! preg_match( '#^(https?://|www\.)?((?:[a-z0-9-]+\.)+([a-z]{2,}))(?:[/:?\#].*)?$#i', $label, $shown ) ) {
				continue;
			}
			// A bare "Node.js" is not a domain: without scheme or www. only common TLDs count.
			if ( '' === $shown[1] && ! in_array( strtolower( $shown[3] ), array( 'com', 'net', 'org', 'de', 'at', 'ch', 'eu', 'io', 'co', 'uk', 'info', 'biz', 'shop', 'app', 'fr', 'it', 'es', 'nl' ), true ) ) {
				continue;
			}
			$shown_host  = self::bare_host( $shown[2] );
			$target_host = self::bare_host( $match[1] );
			if ( ! self::same_site( $shown_host, $target_host ) ) {
				return array( self::note( 'link_mismatch', self::WARNING, array( $shown_host, $target_host ) ) );
			}
		}
		return array();
	}

	/**
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function subject( Mail $mail ): array {
		$subject = trim( $mail->subject );
		if ( '' === $subject ) {
			return array( self::note( 'subject_empty', self::WARNING ) );
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $subject, 'UTF-8' ) : strlen( $subject );
		return $length > self::SUBJECT_MAX ? array( self::note( 'subject_long', self::INFO, array( $length ) ) ) : array();
	}

	/**
	 * Images without alt text (tracking pixels excluded): blank boxes when images are blocked.
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function img_alt( Mail $mail ): array {
		if ( ! $mail->is_html || ! preg_match_all( '#<img\b[^>]*>#i', $mail->text(), $m ) ) {
			return array();
		}
		$missing = 0;
		foreach ( $m[0] as $tag ) {
			if ( preg_match( '/\salt\s*=/i', $tag ) || preg_match( '/\s(?:width|height)\s*=\s*["\']?[01]\b/i', $tag ) ) {
				continue;
			}
			++$missing;
		}
		return $missing ? array( self::note( 'img_no_alt', self::INFO, array( $missing ) ) ) : array();
	}

	/**
	 * Passwords, API keys, private keys or card numbers in plain text. Looks at the body and at what was
	 * found before the log masked the body (Mail::$secrets). Notes carry a masked hint at most.
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function secrets( Mail $mail ): array {
		$codes = array(
			Secrets::PASSWORD    => array( 'secret_password', self::ERROR ),
			Secrets::PRIVATE_KEY => array( 'secret_key', self::ERROR ),
			Secrets::API_KEY     => array( 'secret_key', self::WARNING ),
			Secrets::CARD        => array( 'secret_card', self::ERROR ),
		);
		$notes = array();
		foreach ( array_merge( $mail->secrets, Secrets::find( $mail->body ) ) as $secret ) {
			if ( ! isset( $codes[ $secret['kind'] ] ) ) {
				continue;
			}
			list( $code, $severity ) = $codes[ $secret['kind'] ];

			$params = Secrets::PASSWORD === $secret['kind'] ? array() : array( self::clip( $secret['hint'], 60 ) );

			$notes[ $code . "\0" . implode( '', $params ) ] = self::note( $code, $severity, $params );
		}
		return array_slice( array_values( $notes ), 0, 3 );
	}

	/**
	 * Several outside recipients in To/Cc see each other's addresses – with customers a data breach
	 * (GDPR). Addresses on the site's domain and the sender's domain are the team and do not count.
	 * A party is a company domain or a single mailbox at a public provider (two gmail.com addresses are
	 * two people, two colleagues at customer.de are one party). Bcc is invisible and never counts.
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function open_recipients( Mail $mail ): array {
		$visible = array_values( array_unique( array_merge( $mail->to, $mail->cc ) ) );
		if ( count( $visible ) < self::OPEN_PARTIES ) {
			return array();
		}
		$own  = array( self::bare_host( $mail->site['home'] ) );
		$from = Mail::domain( $mail->from );
		if ( '' !== $from && ! Domains::is_free_mailer( $from ) ) {
			$own[] = $from; // A free mailer as sender is no team domain: its users are strangers.
		}

		$external = 0;
		$parties  = array();
		foreach ( array_slice( $visible, 0, 500 ) as $email ) {
			$domain = Mail::domain( $email );
			if ( Domains::is_reserved( $domain ) ) {
				continue; // Test addresses (example.com, *.test, localhost) belong to nobody.
			}
			foreach ( $own as $host ) {
				if ( '' !== $host && self::same_site( $host, $domain ) ) {
					continue 2;
				}
			}
			++$external;
			if ( in_array( $domain, Domains::PROVIDERS, true ) ) {
				$parties[ $email ] = true;
				continue;
			}
			foreach ( array_keys( $parties ) as $party ) {
				if ( false === strpos( (string) $party, '@' ) && self::same_site( (string) $party, $domain ) ) {
					continue 2; // sales.customer.de belongs to customer.de.
				}
			}
			$parties[ $domain ] = true;
		}
		return count( $parties ) >= self::OPEN_PARTIES ? array( self::note( 'open_recipients', self::WARNING, array( $external ) ) ) : array();
	}

	/**
	 * Cheap local signs that spam filters may count against a mail: a subject in capitals or with many
	 * exclamation marks, an HTML mail that is little more than an image, links via URL shorteners.
	 * No score – each sign is a hint of its own.
	 *
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function spam_signs( Mail $mail ): array {
		$notes = array();

		// \p{Lu}/\p{Ll} include umlauts and other scripts; invalid UTF-8 counts as no letters at all.
		$upper = (int) preg_match_all( '/\p{Lu}/u', $mail->subject );
		$lower = (int) preg_match_all( '/\p{Ll}/u', $mail->subject );
		if ( $upper + $lower >= self::CAPS_MIN_LETTERS && $upper >= self::CAPS_RATIO * ( $upper + $lower ) ) {
			$notes[] = self::note( 'subject_caps', self::INFO );
		}

		$exclamations = substr_count( $mail->subject, '!' );
		if ( $exclamations >= self::SUBJECT_EXCLAMATIONS ) {
			$notes[] = self::note( 'subject_exclamations', self::INFO, array( $exclamations ) );
		}

		$text = $mail->text();
		if ( $mail->is_html && preg_match_all( '#<img\b[^>]*>#i', $text, $m ) ) {
			$images = 0;
			foreach ( $m[0] as $tag ) {
				if ( ! preg_match( '/\s(?:width|height)\s*=\s*["\']?[01]\b/i', $tag ) ) {
					++$images; // Tracking pixels do not count.
				}
			}
			$visible = html_entity_decode( (string) preg_replace( '/<[^>]+>/', ' ', $text ), ENT_QUOTES, 'UTF-8' );
			if ( $images > 0 && (int) preg_match_all( '/\p{L}/u', $visible ) < self::IMAGE_ONLY_LETTERS ) {
				$notes[] = self::note( 'image_only', self::INFO );
			}
		}

		$hosts = implode( '|', array_map( 'preg_quote', self::SHORTENERS ) );
		if ( preg_match( '#\bhttps?://(?:www\.)?(' . $hosts . ')(?=[/?\#:\s"\'<>]|$)#i', $text, $short ) ) {
			$notes[] = self::note( 'link_shortener', self::INFO, array( strtolower( $short[1] ) ) );
		}
		return $notes;
	}

	/** Host without "www." and port, lower-cased. */
	public static function bare_host( string $host ): string {
		$host = strtolower( trim( $host ) );
		return (string) preg_replace( '/^www\.|:\d+$/', '', $host );
	}

	/** Same host or one is a subdomain of the other (mail.example.com ↔ example.com). */
	public static function same_site( string $a, string $b ): bool {
		$a = self::bare_host( $a );
		$b = self::bare_host( $b );
		return $a === $b || self::ends_with( $a, '.' . $b ) || self::ends_with( $b, '.' . $a );
	}

	/**
	 * Different hosts that look like copies of the same site: one is a subdomain of the other, or one
	 * of them carries a staging label (staging., dev., stage. …) and both share the site's name
	 * (shop.de ↔ shop-staging.kinsta.cloud).
	 */
	private static function related_hosts( string $home, string $other ): bool {
		if ( $home === $other ) {
			return false;
		}
		if ( self::same_site( $home, $other ) ) {
			return true;
		}
		$staging = '/(?:^|[.-])(?:staging|stage|stg|dev|test)(?:[.-]|$)/';
		if ( ! preg_match( $staging, $home ) && ! preg_match( $staging, $other ) ) {
			return false;
		}
		$labels = static function ( string $host ): array {
			return array_filter(
				(array) preg_split( '/[.-]/', $host ),
				static function ( $label ): bool {
					return strlen( (string) $label ) >= 4 && ! in_array( $label, array( 'staging', 'stage', 'test', 'online', 'cloud', 'host', 'site', 'info', 'mail', 'wpengine', 'kinsta', 'local' ), true );
				}
			);
		};
		return (bool) array_intersect( $labels( $home ), $labels( $other ) );
	}

	private static function ends_with( string $haystack, string $needle ): bool {
		return '' !== $needle && strlen( $haystack ) > strlen( $needle ) && substr( $haystack, -strlen( $needle ) ) === $needle;
	}

	private static function clip( string $text, int $max = 80 ): string {
		$text = trim( $text );
		return strlen( $text ) > $max ? substr( $text, 0, $max ) . '…' : $text;
	}
}
