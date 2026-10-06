<?php
/**
 * "Anonymise entries after N days": instead of (or before) deleting old entries, their personal data is
 * removed while the statistics survive.
 *
 * Removed: message, headers, attachments, raw source, module data in meta (only integer/boolean values –
 * counts and codes – are kept), optionally the subject. Recipients and sender are masked
 * ("a***@example.com"), addresses in the error message too. Kept: date, status, source, content type,
 * size and notes. The row is marked with meta {"anonymised":{…}} – always the first key, which the list
 * query reads as a flag.
 *
 * Runs on the daily cleanup cron before the deletion (priority 5), in batches along the created_at
 * index with a persistent cursor. Imported entries that are already older than the limit are
 * anonymised while they are imported (mailspur_finalize_row). While it is on, entries whose own,
 * shorter retention period ended (e.g. "Keep for 7 days" of an email type) are anonymised instead of
 * deleted and go with the general deletion period.
 *
 * Direct queries: the plugin's own table.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Workflow;

use Mailspur\Cleanup;
use Mailspur\Logger;
use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Anonymiser {

	const CURSOR_OPTION = 'mailspur_anonymise_cursor';
	const BATCH         = 200;
	/** Seconds per cron run; the rest follows the next day. */
	const TIME_BUDGET = 20;
	/** JSON prefix of an anonymised row's meta. */
	const MARK = '{"anonymised":';

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_filter( 'mailspur_settings_defaults', array( $this, 'defaults' ) );
		add_filter( 'mailspur_settings_sanitize', array( $this, 'sanitize' ), 10, 2 );
		add_action( 'mailspur_settings_sections', array( $this, 'render_settings' ), 10, 2 );
		add_action( Cleanup::HOOK, array( $this, 'cron' ), 5 ); // Before the deletion (priority 10).
		add_filter( 'mailspur_finalize_row', array( $this, 'finalize_row' ), PHP_INT_MAX, 2 );
		add_filter( 'mailspur_rest_summary', array( $this, 'summary' ), 10, 2 );
		add_filter( 'mailspur_retention_expire', array( $this, 'expire' ) );
	}

	/**
	 * @param array<string,string|int|bool> $defaults
	 * @return array<string,string|int|bool>
	 */
	public function defaults( array $defaults ): array {
		$defaults['anonymise_days']    = 0;
		$defaults['anonymise_subject'] = false;
		return $defaults;
	}

	/**
	 * @param array<string,string|int|bool> $clean
	 * @param array<string,mixed>           $input
	 * @return array<string,string|int|bool>
	 */
	public function sanitize( array $clean, array $input ): array {
		$days                       = isset( $input['anonymise_days'] ) && is_scalar( $input['anonymise_days'] ) ? min( 3650, absint( $input['anonymise_days'] ) ) : 0;
		$clean['anonymise_days']    = $days;
		$clean['anonymise_subject'] = ! empty( $input['anonymise_subject'] );

		// Switched on (again): start over, so entries logged or imported while it was off are covered too.
		if ( $days > 0 && 0 === (int) Settings::get( 'anonymise_days' ) ) {
			delete_option( self::CURSOR_OPTION );
		}
		return $clean;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	public function render_settings( array $settings, string $name ): void {
		?>
		<h2><?php esc_html_e( 'Anonymisation', 'mailspur-email-log' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="mailspur-anonymise"><?php esc_html_e( 'Anonymise entries after', 'mailspur-email-log' ); ?></label></th>
				<td>
					<input type="number" class="small-text" id="mailspur-anonymise" min="0" max="3650" name="<?php echo esc_attr( $name ); ?>[anonymise_days]" value="<?php echo esc_attr( (string) (int) ( $settings['anonymise_days'] ?? 0 ) ); ?>"> <?php esc_html_e( 'days', 'mailspur-email-log' ); ?>
					<p class="description"><?php esc_html_e( '0 turns it off. Removes content, headers and attachments of older entries and masks the addresses (a***@example.com). Date, status, source, format, size and notes stay for statistics. Runs with the daily cleanup, before entries are deleted – so it only has an effect if it is shorter than the deletion period above.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Subject', 'mailspur-email-log' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[anonymise_subject]" value="1" <?php checked( ! empty( $settings['anonymise_subject'] ) ); ?>> <?php esc_html_e( 'Also remove the subject when anonymising', 'mailspur-email-log' ); ?></label>
					<p class="description"><?php esc_html_e( 'Subjects often contain names or order numbers.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/** GMT datetime before which entries are anonymised, or '' when switched off. */
	public static function cutoff( ?int $now = null ): string {
		$days = (int) Settings::get( 'anonymise_days' );
		return $days > 0 ? gmdate( 'Y-m-d H:i:s', ( $now ?? time() ) - $days * DAY_IN_SECONDS ) : '';
	}

	public function cron(): void {
		$this->run();
	}

	/**
	 * Daily cron (before the retention cleanup): anonymises everything older than the limit, in batches.
	 *
	 * @return int Number of anonymised entries.
	 */
	public function run(): int {
		$cutoff = self::cutoff();
		if ( '' === $cutoff ) {
			return 0;
		}
		global $wpdb;

		$days    = (int) Settings::get( 'anonymise_days' );
		$subject = (bool) Settings::get( 'anonymise_subject' );
		$started = time();
		$done    = 0;
		$cursor  = get_option( self::CURSOR_OPTION );
		$cursor  = is_array( $cursor ) && isset( $cursor[0], $cursor[1] ) ? array( (string) $cursor[0], (int) $cursor[1] ) : array( '1000-01-01 00:00:00', 0 );

		do {
			// Forward along the created_at index from the cursor; already anonymised rows are skipped.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE created_at < %s AND ( created_at > %s OR ( created_at = %s AND id > %d ) ) ORDER BY created_at ASC, id ASC LIMIT %d',
					Repository::table(),
					$cutoff,
					$cursor[0],
					$cursor[0],
					$cursor[1],
					self::BATCH
				),
				ARRAY_A
			);
			$got  = count( $rows );
			foreach ( $rows as $row ) {
				$cursor = array( (string) $row['created_at'], (int) $row['id'] );
				if ( self::is_anonymised( $row ) ) {
					continue;
				}
				$data         = self::anonymise( $row, $days, $subject, time() );
				$data['meta'] = Logger::encode( (array) $data['meta'] );
				$this->repository->update( (int) $row['id'], $data );
				++$done;
			}
			update_option( self::CURSOR_OPTION, $cursor, false );
		} while ( self::BATCH === $got && time() - $started < self::TIME_BUDGET );

		if ( $done > 0 ) {
			delete_transient( Sources::TRANSIENT );
		}
		return $done;
	}

	/**
	 * Entries whose own retention period ended (Cleanup): anonymised instead of deleted while anonymisation is on.
	 *
	 * @param mixed $ids Log entry ids.
	 * @return int[] Ids to delete.
	 */
	public function expire( $ids ): array {
		$ids  = array_map( 'intval', is_array( $ids ) ? $ids : array() );
		$days = (int) Settings::get( 'anonymise_days' );
		if ( $days <= 0 ) {
			return $ids;
		}
		$subject = (bool) Settings::get( 'anonymise_subject' );
		foreach ( $ids as $id ) {
			$row = $this->repository->find( $id );
			if ( null === $row || self::is_anonymised( $row ) ) {
				continue;
			}
			$data         = self::anonymise( $row, $days, $subject, time() );
			$data['meta'] = Logger::encode( (array) $data['meta'] );
			$this->repository->update( $id, $data );
		}
		delete_transient( Sources::TRANSIENT );
		return array();
	}

	/**
	 * Imported entries that are already older than the limit are stored anonymised right away
	 * (the daily run only moves forward). Live mails are never old enough.
	 *
	 * @param array<string,mixed> $data Columns to write.
	 * @param array<string,mixed> $row  Complete row.
	 * @return array<string,mixed>
	 */
	public function finalize_row( array $data, array $row ): array {
		$created = (string) ( $row['created_at'] ?? '' );
		$cutoff  = '' === $created ? '' : self::cutoff();
		if ( '' === $cutoff || $created >= $cutoff ) {
			return $data;
		}
		$row = array_merge( $row, $data );
		return array_merge( $data, self::anonymise( $row, (int) Settings::get( 'anonymise_days' ), (bool) Settings::get( 'anonymise_subject' ), time() ) );
	}

	/**
	 * List payload: flag for the badge. The list query provides "anonymised"; the detail view reads meta.
	 *
	 * @param array<string,mixed>  $item
	 * @param array<string,string> $row
	 * @return array<string,mixed>
	 */
	public function summary( array $item, array $row ): array {
		$item['anonymised'] = self::is_anonymised( $row );
		return $item;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function is_anonymised( array $row ): bool {
		if ( isset( $row['anonymised'] ) ) {
			return (bool) $row['anonymised'];
		}
		return isset( $row['meta'] ) && is_string( $row['meta'] ) && 0 === strpos( $row['meta'], self::MARK );
	}

	/**
	 * Columns that anonymise one row. Pure function (no database access).
	 *
	 * @param array<string,mixed> $row  Raw row; meta as JSON string or array.
	 * @param int                 $days Setting at the time (shown in the UI).
	 * @return array<string,mixed> Columns to update; meta as array with "anonymised" as first key.
	 */
	public static function anonymise( array $row, int $days, bool $clear_subject, int $now ): array {
		$meta = $row['meta'] ?? array();
		$meta = is_array( $meta ) ? $meta : Rest::decode_meta( is_string( $meta ) ? $meta : '' );

		// Counts and codes (integers/booleans) carry no personal data and keep statistics working.
		$kept = array();
		foreach ( $meta as $key => $value ) {
			if ( 'anonymised' !== $key && ( is_int( $value ) || is_bool( $value ) ) ) {
				$kept[ $key ] = $value;
			}
		}

		$marker      = array(
			'at'   => $now,
			'days' => $days,
		);
		$attachments = count( Exporter::attachment_names( is_string( $row['attachments'] ?? null ) ? $row['attachments'] : '' ) );
		if ( $attachments > 0 ) {
			$marker['attachments'] = $attachments;
		}

		$data = array(
			'recipients'  => self::mask_list( (string) ( $row['recipients'] ?? '' ) ),
			'sender'      => self::mask_list( (string) ( $row['sender'] ?? '' ) ),
			'message'     => '',
			'headers'     => '',
			'attachments' => '',
			'raw'         => '',
			'error'       => self::mask_text( (string) ( $row['error'] ?? '' ) ),
			'meta'        => array_merge( array( 'anonymised' => $marker ), $kept ),
		);
		if ( $clear_subject ) {
			$data['subject'] = '';
		}
		return $data;
	}

	/** "anna@example.com" → "a***@example.com". */
	public static function mask_email( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return '***';
		}
		return mb_substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/** Address list without display names, every address masked. */
	public static function mask_list( string $addresses ): string {
		if ( '' === trim( $addresses ) ) {
			return '';
		}
		$emails = Repository::extract_emails( $addresses );
		return $emails ? implode( ', ', array_map( array( self::class, 'mask_email' ), $emails ) ) : '***';
	}

	/** Masks every address inside a free text (error messages). */
	public static function mask_text( string $text ): string {
		return (string) preg_replace_callback(
			'/[^\s<>,;:"\'()\[\]]+@[^\s<>,;"\'()\[\]]+/',
			static function ( array $m ): string {
				return self::mask_email( $m[0] );
			},
			$text
		);
	}
}
