<?php
/**
 * "This email type stopped" alerts: after each hourly indexing run, every regular type that is overdue
 * by its own rhythm triggers one alert through the channels of the monitoring alerts (email / webhook);
 * a recovery message follows when the type is sent again. Opt-in (setting "alert_types").
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Modules\Insights\Alerts;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Monitor {

	const STATE = 'mailspur_types_alerts';

	/** @var Store */
	private $store;

	/** @var callable Alerts::dispatch( $type, $kind, $message, $settings ). */
	private $dispatch;

	/** @var callable():int */
	private $now;

	public function __construct( Store $store, callable $dispatch, ?callable $now = null ) {
		$this->store    = $store;
		$this->dispatch = $dispatch;
		$this->now      = $now ?? 'time';
	}

	/** Cron callback (after the indexer). */
	public function run(): void {
		$settings = Settings::all();
		if ( empty( $settings['alert_types'] ) || ! Alerts::has_channel( $settings ) ) {
			delete_option( self::STATE );
			return;
		}
		$this->check( Report::current( $this->store, (int) call_user_func( $this->now ) ), $settings );
	}

	/**
	 * @param array<int,array<string,mixed>> $items    Report items.
	 * @param array<string,mixed>            $settings Plugin settings.
	 * @return array<string,int[]> Ids alerted and recovered (for tests).
	 */
	public function check( array $items, array $settings ): array {
		$now   = (int) call_user_func( $this->now );
		$state = get_option( self::STATE, array() );
		$state = is_array( $state ) ? $state : array();
		$done  = array(
			'alert'    => array(),
			'recovery' => array(),
		);

		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item['id'] ] = $item;
		}

		foreach ( $by_id as $id => $item ) {
			if ( 'silent' === $item['state'] && ! isset( $state[ $id ] ) ) {
				call_user_func( $this->dispatch, 'type', 'alert', self::message( $item, $now ), $settings );
				$state[ $id ]    = (int) $item['last_seen'];
				$done['alert'][] = $id;
			}
		}

		// State: last_seen at the time of the alert. A newer email of the type means it is back.
		foreach ( $state as $id => $last_seen ) {
			$item = $by_id[ (int) $id ] ?? null;
			if ( null === $item || $item['muted'] ) {
				unset( $state[ $id ] );
			} elseif ( (int) $item['last_seen'] > (int) $last_seen ) {
				if ( ! empty( $settings['alert_recovery'] ) ) {
					call_user_func( $this->dispatch, 'type', 'recovery', self::recovery( $item ), $settings );
					$done['recovery'][] = (int) $id;
				}
				unset( $state[ $id ] );
			} elseif ( ! $item['rhythm']['regular'] ) {
				unset( $state[ $id ] ); // No longer a regular type: forget it without a message.
			}
		}

		update_option( self::STATE, $state, false );
		return $done;
	}

	/**
	 * Alert text: names the type, its sender and rhythm and what was updated since – never recipients or contents.
	 *
	 * @param array<string,mixed> $item Report item.
	 */
	public static function message( array $item, int $now ): string {
		$text = sprintf(
			/* translators: 1: email type, e.g. "New order #…", 2: plugin name, 3: time span, e.g. "3 days", 4: number of days */
			__( '“%1$s” (%2$s) was last sent %3$s ago. Until then it was sent at least every %4$s days.', 'mailspur-email-log' ),
			Report::text( (array) $item['pattern'] ),
			Report::source_label( (string) $item['source'] ),
			human_time_diff( (int) $item['last_seen'], $now ),
			number_format_i18n( (int) $item['rhythm']['expected'] )
		);
		$updates = array_column( (array) $item['updates'], 'label' );
		if ( $updates ) {
			/* translators: %s: comma-separated list of updated plugins/themes */
			$text .= ' ' . sprintf( __( 'Updated since then: %s.', 'mailspur-email-log' ), implode( ', ', array_unique( $updates ) ) );
		}
		return $text;
	}

	/**
	 * @param array<string,mixed> $item Report item.
	 */
	public static function recovery( array $item ): string {
		return sprintf(
			/* translators: 1: email type, 2: plugin name */
			__( '“%1$s” (%2$s) is being sent again.', 'mailspur-email-log' ),
			Report::text( (array) $item['pattern'] ),
			Report::source_label( (string) $item['source'] )
		);
	}
}
