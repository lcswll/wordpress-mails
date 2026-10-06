<?php
/**
 * Email inventory ("Mail-Verzeichnis"): every email type as a list for a record of processing activities or an
 * agency handover – as CSV and as a printable page.
 *
 * Per type: subject pattern, sending plugin, recipient group, rhythm, last sent, emails in 30 days, retention in
 * the log (the type's own period, see Retention) and the categories of personal data it contains. Nothing extra is stored and no contents or addresses
 * are exported:
 * - recipient groups (administrators / registered users / other recipients) are derived at export time from the
 *   latest SAMPLE emails of each sender – addresses are only compared with the site's users, never output;
 * - data categories are detected in the latest email of each type (subject and text), only the category names
 *   are output.
 *
 * Direct queries: the plugin's own table.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Admin;
use Mailspur\Modules\Workflow\Exporter;
use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Inventory {

	const ACTION = 'mailspur_types_inventory';
	const NONCE  = 'mailspur_types_inventory';

	/** Latest emails per sender whose recipients are classified. */
	const SAMPLE = 300;

	/** Recipient groups, in output order. */
	const GROUPS = array( 'admin', 'user', 'external' );

	/** Data categories, in output order. */
	const CATEGORIES = array( 'email', 'postal', 'phone', 'order', 'freetext' );

	/** @var Store */
	private $store;

	/** @var Repository */
	private $repository;

	public function __construct( Store $store, ?Repository $repository = null ) {
		$this->store      = $store;
		$this->repository = $repository ?? new Repository();
	}

	/** Download URL (nonce-protected GET, so the printable page can open in a new tab). */
	public static function url( string $format ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'format' => 'csv' === $format ? 'csv' : 'html',
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE
		);
	}

	/** The export link in the tab's toolbar. */
	public static function link(): void {
		?>
		<a class="mst-export" href="<?php echo esc_url( self::url( 'html' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Email inventory', 'mailspur-email-log' ); ?></a>
		<?php
	}

	/** admin-post.php?action=mailspur_types_inventory&format=csv|html */
	public function download(): void {
		if ( ! Settings::current_user_can_view() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'mailspur-email-log' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified just above.
		$format = isset( $_GET['format'] ) && 'csv' === sanitize_key( wp_unslash( $_GET['format'] ) ) ? 'csv' : 'html';

		$rows = $this->rows( time() );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'csv' === $format ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="email-inventory-' . wp_date( 'Y-m-d' ) . '.csv"' );
			echo self::csv( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file download, not HTML; served as attachment with nosniff, cells defused in csv().
		} else {
			header( 'Content-Type: text/html; charset=utf-8' );
			echo self::html( $rows, wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), (string) wp_date( (string) get_option( 'date_format' ) ), self::url( 'csv' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped in html().
		}
		exit;
	}

	/**
	 * One row per email type (ignored types and catch-all types included), sorted by sender and pattern.
	 *
	 * @return array<int,array<string,string|int>> Keys: type, sender, recipients, rhythm, last_sent, emails_30_days, retention, data.
	 */
	public function rows( int $now ): array {
		$types      = $this->store->types();
		$items      = Report::current( $this->store, $now, $types );
		$recipients = $this->recipients( $types );
		$days       = (int) Settings::get( 'retention_days' );
		$anonymise  = (int) Settings::get( 'anonymise_days' );
		$format     = (string) get_option( 'date_format' );

		$rows = array();
		foreach ( $items as $item ) {
			$id     = (int) $item['id'];
			$rows[] = array(
				'type'           => Report::text( (array) $item['pattern'] ),
				'sender'         => Report::source_label( (string) $item['source'] ),
				'recipients'     => self::groups_text( $recipients[ $id ] ?? array() ),
				'rhythm'         => Page::rhythm_text( $item['rhythm'] ),
				'last_sent'      => (string) wp_date( $format, (int) $item['last_seen'] ),
				'emails_30_days' => (int) $item['total'],
				'retention'      => self::retention( ...Retention::effective( (int) ( $types[ $id ]['keep'] ?? 0 ), $days, $anonymise ) ),
				'data'           => self::categories_text( $this->categories( (int) $item['last_id'], ! empty( $types[ $id ]['extra']['content']['var'] ) ) ),
			);
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $a['sender'], $a['type'] ) <=> array( $b['sender'], $b['type'] );
			}
		);
		return $rows;
	}

	/**
	 * Recipient groups per type from the latest emails of each sender.
	 *
	 * @param array<int,array<string,mixed>> $types Store::types().
	 * @return array<int,array<string,int>> Emails per group, by type id.
	 */
	public function recipients( array $types ): array {
		global $wpdb;
		$by_source = array();
		foreach ( $types as $id => $type ) {
			$by_source[ (string) $type['source'] ][ $id ] = $type;
		}
		$lookup = self::user_lookup();
		$out    = array();
		foreach ( $by_source as $source => $list ) {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT recipients, subject FROM %i WHERE source = %s ORDER BY id DESC LIMIT %d', Repository::table(), $source, self::SAMPLE ),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$id = Indexer::find( $list, (string) $row['subject'] );
				if ( null === $id ) {
					continue;
				}
				foreach ( self::classify( (string) $row['recipients'], $lookup ) as $group ) {
					$out[ $id ][ $group ] = ( $out[ $id ][ $group ] ?? 0 ) + 1;
				}
			}
		}
		return $out;
	}

	/**
	 * Groups of one email's recipients (each group once).
	 *
	 * @param callable(string):string $lookup Address (lowercase) → admin | user | external.
	 * @return string[]
	 */
	public static function classify( string $recipients, callable $lookup ): array {
		if ( ! preg_match_all( '/[^\s<>,;"\']+@[^\s<>,;"\']+/u', $recipients, $matches ) ) {
			return array();
		}
		$groups = array();
		foreach ( $matches[0] as $address ) {
			$group = (string) $lookup( strtolower( $address ) );
			if ( in_array( $group, self::GROUPS, true ) ) {
				$groups[ $group ] = true;
			}
		}
		return array_keys( $groups );
	}

	/**
	 * Site lookup: the admin email address and users who can manage options are administrators, other users
	 * registered users, everything else other recipients. Cached per export.
	 *
	 * @return callable(string):string
	 */
	private static function user_lookup(): callable {
		$cache = array( strtolower( (string) get_option( 'admin_email' ) ) => 'admin' );
		return static function ( string $address ) use ( &$cache ): string {
			if ( ! isset( $cache[ $address ] ) ) {
				$user              = get_user_by( 'email', $address );
				$cache[ $address ] = $user ? ( user_can( $user, 'manage_options' ) ? 'admin' : 'user' ) : 'external';
			}
			return $cache[ $address ];
		};
	}

	/**
	 * "Registered users, other recipients" – largest group first.
	 *
	 * @param array<string,int> $counts Emails per group.
	 */
	public static function groups_text( array $counts ): string {
		$counts = array_filter( array_intersect_key( $counts, array_flip( self::GROUPS ) ) );
		if ( ! $counts ) {
			return __( 'Unknown', 'mailspur-email-log' );
		}
		$order = array_flip( self::GROUPS );
		uksort(
			$counts,
			static function ( string $a, string $b ) use ( $counts, $order ): int {
				return array( $counts[ $b ], $order[ $a ] ) <=> array( $counts[ $a ], $order[ $b ] );
			}
		);
		$labels = array(
			'admin'    => __( 'Administrators', 'mailspur-email-log' ),
			'user'     => __( 'Registered users', 'mailspur-email-log' ),
			'external' => __( 'Other recipients (e.g. guests)', 'mailspur-email-log' ),
		);
		return implode( ', ', array_intersect_key( array_replace( $counts, $labels ), $counts ) );
	}

	/**
	 * Data categories of the latest email of a type (null when it is gone or anonymised).
	 *
	 * @return string[]|null
	 */
	private function categories( int $log_id, bool $variable ): ?array {
		$row = $log_id > 0 ? $this->repository->find( $log_id ) : null;
		if ( null === $row || '' === (string) $row['message'] || ! empty( Rest::decode_meta( (string) ( $row['meta'] ?? '' ) )['anonymised'] ) ) {
			return null;
		}
		$message = (string) $row['message'];
		$text    = implode( "\n", Content::lines( $message, Content::is_html( (string) $row['content_type'], $message ) ) );
		return self::detect( (string) $row['subject'] . "\n" . $text, $variable );
	}

	/**
	 * Categories of personal data in an email text. The recipient's address is always one (it is in the log).
	 *
	 * @param string $text     Subject and readable body text.
	 * @param bool   $variable The type's content is free-form (Content: almost every email differs).
	 * @return string[] Codes from CATEGORIES.
	 */
	public static function detect( string $text, bool $variable = false ): array {
		$found = array( 'email' );

		$postal = array(
			'/\b(?:billing|shipping|delivery|postal)\s+address\b|\b(?:rechnungs|liefer|versand)adresse\b|\banschrift\b/iu',
			'/\b\p{L}+(?:straße|strasse|str\.|weg|gasse|platz|allee|ring)\s+\d+\s?[a-z]?\b/iu',
			'/\b\d+\s+(?:\p{Lu}\p{L}*\s){1,3}(?:street|st\.|road|rd\.|avenue|ave\.|lane|drive|boulevard|blvd\.?)(?=\W|$)/u',
			'/(?:^|\n)\s*(?:[A-Z]{1,2}-)?\d{4,5}\s+\p{Lu}\p{Ll}+/u',
			'/\b[A-Z]{1,2}\d[A-Z\d]?\s\d[A-Z]{2}\b/u',
			'/,\s*[A-Z]{2}\s+\d{5}(?:-\d{4})?\b/u',
		);
		$phone  = array(
			'/\btel:/iu',
			'/\b(?:phone|telephone|tel\.?|telefon|mobile|mobil|handy|cell)\s*(?:number|nummer|nr\.?)?\s*[:.]?\s*\+?[\d(][\d\s()\/.-]{5,}\d/iu',
			'/(?<![\w.])\+\d{1,3}[\s\d()\/-]{7,}\d/u',
		);
		$order  = array(
			'/\b(?:order|bestellung|invoice|rechnung|auftrag)\s*(?:#|no\.?|nr\.?|number|nummer)?\s*:?\s*#?\d{2,}/iu',
			'/\b(?:subtotal|total|zwischensumme|gesamtsumme|summe|gesamt|amount|betrag)\b[^\n]{0,40}?(?:[€$£]\s?\d|\d[\d.,]*\s?(?:€|eur|usd|chf|gbp|\$|£))/iu',
		);
		$free   = array(
			'/(?:^|\n)\s*(?:message|your message|comments?|enquiry|inquiry|nachricht|ihre nachricht|deine nachricht|kommentar|anfrage|bemerkung)\s*:/iu',
		);

		foreach ( array(
			'postal'   => $postal,
			'phone'    => $phone,
			'order'    => $order,
			'freetext' => $free,
		) as $category => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $text ) ) {
					$found[] = $category;
					break;
				}
			}
		}
		if ( $variable && ! in_array( 'freetext', $found, true ) ) {
			$found[] = 'freetext';
		}
		return array_values( array_intersect( self::CATEGORIES, $found ) );
	}

	/**
	 * @param string[]|null $codes
	 */
	public static function categories_text( ?array $codes ): string {
		if ( null === $codes ) {
			return __( 'Email address (the latest email is no longer in the log)', 'mailspur-email-log' );
		}
		$labels = array(
			'email'    => __( 'Email address', 'mailspur-email-log' ),
			'postal'   => __( 'Postal address', 'mailspur-email-log' ),
			'phone'    => __( 'Phone number', 'mailspur-email-log' ),
			'order'    => __( 'Order data', 'mailspur-email-log' ),
			'freetext' => __( 'Free text from forms', 'mailspur-email-log' ),
		);
		return implode( ', ', array_values( array_intersect_key( $labels, array_flip( $codes ) ) ) );
	}

	/** "90 days" / "content anonymised after 30 days, deleted after 90 days" / "Unlimited". */
	public static function retention( int $days, int $anonymise ): string {
		/* translators: %s: number of days */
		$deleted = $days > 0 ? sprintf( __( 'deleted after %s days', 'mailspur-email-log' ), number_format_i18n( $days ) ) : __( 'kept until deleted manually', 'mailspur-email-log' );
		if ( $anonymise > 0 && ( 0 === $days || $anonymise < $days ) ) {
			/* translators: 1: number of days, 2: "deleted after 90 days" */
			return sprintf( __( 'Content anonymised after %1$s days, %2$s', 'mailspur-email-log' ), number_format_i18n( $anonymise ), $deleted );
		}
		/* translators: %s: "deleted after 90 days" */
		return sprintf( __( 'Log entries %s', 'mailspur-email-log' ), $deleted );
	}

	/**
	 * Column titles.
	 *
	 * @return array<string,string>
	 */
	public static function columns(): array {
		return array(
			'type'           => __( 'Email type', 'mailspur-email-log' ),
			'sender'         => __( 'Sent by', 'mailspur-email-log' ),
			'recipients'     => __( 'Recipients', 'mailspur-email-log' ),
			'rhythm'         => __( 'Rhythm', 'mailspur-email-log' ),
			'last_sent'      => __( 'Last sent', 'mailspur-email-log' ),
			'emails_30_days' => __( 'Emails in 30 days', 'mailspur-email-log' ),
			'retention'      => __( 'Retention in the log', 'mailspur-email-log' ),
			'data'           => __( 'Personal data', 'mailspur-email-log' ),
		);
	}

	/**
	 * CSV with BOM, every text cell defused against formula injection (Workflow Exporter).
	 *
	 * @param array<int,array<string,string|int>> $rows
	 */
	public static function csv( array $rows ): string {
		$out = "\xEF\xBB\xBF" . Exporter::csv_line( self::columns() );
		foreach ( $rows as $row ) {
			$out .= Exporter::csv_line( array_values( array_merge( array_fill_keys( array_keys( self::columns() ), '' ), $row ) ) );
		}
		return $out;
	}

	/**
	 * Printable stand-alone page.
	 *
	 * @param array<int,array<string,string|int>> $rows
	 */
	public static function html( array $rows, string $site, string $date, string $csv_url ): string {
		$columns = self::columns();
		$head    = '';
		foreach ( $columns as $title ) {
			$head .= '<th scope="col">' . esc_html( $title ) . '</th>';
		}
		$body = '';
		foreach ( $rows as $row ) {
			$body .= '<tr>';
			foreach ( array_keys( $columns ) as $key ) {
				$body .= '<td>' . esc_html( (string) ( $row[ $key ] ?? '' ) ) . '</td>';
			}
			$body .= '</tr>';
		}
		if ( '' === $body ) {
			$body = '<tr><td colspan="' . count( $columns ) . '">' . esc_html__( 'No emails logged yet. Email types appear here as soon as your site sends emails.', 'mailspur-email-log' ) . '</td></tr>';
		}
		$title = __( 'Email inventory', 'mailspur-email-log' );

		return '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
			. '<title>' . esc_html( $title . ' – ' . $site ) . '</title>'
			. '<style>body{margin:24px;font:13px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327}'
			. 'h1{font-size:20px;margin:0 0 4px}p{margin:0 0 12px;color:#50575e;max-width:900px}'
			. 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #c3c4c7;padding:6px 8px;text-align:left;vertical-align:top}'
			. 'th{background:#f6f7f7}.actions{margin:0 0 16px}.actions a,.actions button{margin-right:12px;font:inherit}'
			. '@media print{.actions{display:none}body{margin:0}}</style></head><body>'
			. '<h1>' . esc_html( $title ) . '</h1>'
			/* translators: 1: site name, 2: date */
			. '<p>' . esc_html( sprintf( __( '%1$s · as of %2$s', 'mailspur-email-log' ), $site, $date ) ) . '</p>'
			. '<p>' . esc_html__( 'Every kind of email this site sends, generated from the Mailspur email log – e.g. for a record of processing activities or a handover. Recipient groups are derived from the latest emails of each sender, data categories from the latest email of each type. The list contains no email contents and no addresses.', 'mailspur-email-log' ) . '</p>'
			. '<p class="actions"><button type="button" onclick="window.print()">' . esc_html__( 'Print', 'mailspur-email-log' ) . '</button>'
			. '<a href="' . esc_url( $csv_url ) . '">' . esc_html__( 'Download as CSV', 'mailspur-email-log' ) . '</a>'
			. '<a href="' . esc_url( Admin::url( array( 'tab' => Page::TAB ) ) ) . '">' . esc_html__( 'Back to the email types', 'mailspur-email-log' ) . '</a></p>'
			. '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>'
			. '</body></html>';
	}
}
