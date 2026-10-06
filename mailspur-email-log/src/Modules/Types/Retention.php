<?php
/**
 * Retention per email type ("Keep for …" in the type's menu): password resets for 7 days, invoices for a year.
 *
 * The period is stored per type (Store, column keep_days); the daily cleanup (Cleanup, filter
 * mailspur_retention_rules) reads only the entries of senders with an own period and sorts them by subject, the
 * same way the detail view finds an entry's type (Indexer::find). With anonymisation on, a shorter period
 * anonymises the entries instead of deleting them. No addresses are stored for this.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Admin;
use Mailspur\Cleanup;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Retention {

	const ACTION = 'mailspur_types_keep';
	const NONCE  = 'mailspur_types_keep';

	/** Periods offered in the menu (days); 0 = the log's period, Cleanup::UNTIL_LIMIT = until the log limit. */
	const CHOICES = array( 0, 7, 30, 90, 365, Cleanup::UNTIL_LIMIT );

	/** @var Store */
	private $store;

	public function __construct( Store $store ) {
		$this->store = $store;
	}

	/**
	 * Rules for the daily cleanup: one per type with an own period.
	 *
	 * @param mixed $rules Rules of other modules.
	 * @return array<int,mixed>
	 */
	public function rules( $rules ): array {
		$rules = is_array( $rules ) ? array_values( $rules ) : array();
		try {
			Store::maybe_install();
			$types = $this->store->types();
		} catch ( \Throwable $e ) { // The cleanup must run anyway.
			return $rules;
		}
		$by_source = array();
		foreach ( $types as $id => $type ) {
			$by_source[ $type['source'] ][ $id ] = $type;
		}
		foreach ( $types as $id => $type ) {
			if ( 0 === $type['keep'] || array( Fingerprint::OTHER ) === $type['pattern'] ) {
				continue;
			}
			$siblings = $by_source[ $type['source'] ];
			$rules[]  = array(
				'source' => $type['source'],
				'days'   => $type['keep'],
				'match'  => static function ( string $subject ) use ( $siblings, $id ): bool {
					return Indexer::find( $siblings, $subject ) === $id;
				},
			);
		}
		return $rules;
	}

	/** Valid stored value of a choice. */
	public static function sanitize( int $days ): int {
		return in_array( $days, self::CHOICES, true ) ? $days : 0;
	}

	/** Menu label of a choice. */
	public static function label( int $days ): string {
		if ( 0 === $days ) {
			$log = (int) Settings::get( 'retention_days' );
			return $log > 0
				/* translators: %s: number of days */
				? sprintf( __( 'Default (%s days)', 'mailspur-email-log' ), number_format_i18n( $log ) )
				: __( 'Default (no time limit)', 'mailspur-email-log' );
		}
		if ( Cleanup::UNTIL_LIMIT === $days ) {
			return __( 'Until the log limit', 'mailspur-email-log' );
		}
		/* translators: %s: number of days */
		return sprintf( __( '%s days', 'mailspur-email-log' ), number_format_i18n( $days ) );
	}

	/**
	 * Effective periods of a type: after how many days its entries are deleted (0 = no time limit) and anonymised
	 * (0 = never). With anonymisation on, a period shorter than the log's anonymises instead of deleting.
	 *
	 * @param int $keep      The type's choice (0 = the log's period).
	 * @param int $days      The log's retention period (0 = no time limit).
	 * @param int $anonymise The anonymisation period (0 = off).
	 * @return array{0:int,1:int}
	 */
	public static function effective( int $keep, int $days, int $anonymise ): array {
		if ( 0 === $keep ) {
			return array( $days, $anonymise );
		}
		if ( Cleanup::UNTIL_LIMIT === $keep ) {
			return array( 0, $anonymise );
		}
		if ( $anonymise > 0 && ( 0 === $days || $keep < $days ) ) {
			return array( $days, min( $anonymise, $keep ) );
		}
		return array( $keep, $anonymise );
	}

	/** admin-post.php?action=mailspur_types_keep */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'mailspur-email-log' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$id   = isset( $_POST['type'] ) ? absint( $_POST['type'] ) : 0;
		$days = isset( $_POST['keep'] ) ? self::sanitize( (int) sanitize_text_field( wp_unslash( $_POST['keep'] ) ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( $id ) {
			$this->store->keep( $id, $days );
		}
		wp_safe_redirect(
			Admin::url(
				array(
					'tab'        => Page::TAB,
					'types-done' => 'kept',
				)
			) . '#mailspur-type-' . $id
		);
		exit;
	}
}
