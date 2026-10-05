<?php
/**
 * Email types: learning which types WP-Cron sends (from the trace meta) and the cause line of a stopped cron type.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Types\Cron;
use Mailspur\Modules\Types\Indexer;
use Mailspur\Modules\Types\Monitor;

final class TypesCronTest extends TestCase {

	const NOW = 1790000000;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'human_time_diff'    => static function ( $from, $to ) {
					$diff = abs( $to - $from );
					return $diff >= 86400 ? round( $diff / 86400 ) . ' days' : round( $diff / 3600 ) . ' hours';
				},
				'number_format_i18n' => static function ( $n ) {
					return (string) $n;
				},
				'get_plugins'        => array( 'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce' ) ),
				'get_mu_plugins'     => array(),
			)
		);
	}

	/**
	 * @param array<int,string[]> $events Hooks by seconds from now.
	 * @return array<int|string,mixed> Shape of _get_cron_array().
	 */
	private function crons( array $events ): array {
		$out = array( 'version' => 2 );
		foreach ( $events as $offset => $hooks ) {
			foreach ( $hooks as $hook ) {
				$out[ self::NOW + $offset ][ $hook ][ md5( $hook ) ] = array(
					'schedule' => 'daily',
					'args'     => array(),
				);
			}
		}
		return $out;
	}

	public function test_unscheduled_event(): void {
		$d = Cron::diagnose( 'shop_send_reminders', $this->crons( array( 600 => array( 'wp_version_check' ) ) ), self::NOW, false );
		$this->assertSame( 'unscheduled', $d['code'] );
		$this->assertSame( 'Sent by the cron event shop_send_reminders – this event is no longer scheduled.', $d['text'] );
	}

	public function test_stalled_cron_with_and_without_disable_wp_cron(): void {
		$crons = $this->crons(
			array(
				-2 * 86400 => array( 'wp_version_check' ),
				-86400     => array( 'shop_send_reminders' ),
			)
		);
		$d     = Cron::diagnose( 'shop_send_reminders', $crons, self::NOW, true );
		$this->assertSame( 'stalled', $d['code'] );
		$this->assertSame( 'Sent by the cron event shop_send_reminders. WP-Cron has not run for 2 days (DISABLE_WP_CRON is set – check your server cron job).', $d['text'] );

		$d = Cron::diagnose( 'shop_send_reminders', $crons, self::NOW, false );
		$this->assertSame( 'stalled', $d['code'] );
		$this->assertStringContainsString( 'WP-Cron has not run for 2 days – it only runs when the site gets visits', $d['text'] );

		$d = Cron::diagnose( '', $crons, self::NOW, true );
		$this->assertStringStartsWith( 'Sent by WP-Cron. WP-Cron has not run for 2 days', $d['text'] );
	}

	public function test_overdue_event_while_cron_runs_other_events(): void {
		// WP-Cron ran a minute ago, but our event is 5 hours late (failing, rescheduled?).
		$crons = $this->crons(
			array(
				-5 * 3600 => array( 'shop_send_reminders' ),
				300       => array( 'wp_version_check' ),
			)
		);
		$d     = Cron::diagnose( 'shop_send_reminders', $crons, self::NOW, false );
		$this->assertSame( 'overdue', $d['code'] );
		$this->assertSame( 'Sent by the cron event shop_send_reminders – it is overdue by 5 hours while WP-Cron runs other events; it may fail with an error.', $d['text'] );

		$crons = $this->crons(
			array(
				-60  => array( 'wp_version_check' ),
				3600 => array( 'shop_send_reminders' ),
			)
		);
		$d     = Cron::diagnose( 'shop_send_reminders', $crons, self::NOW, false );
		$this->assertSame( 'running', $d['code'] );
		$this->assertSame( 'Sent by the cron event shop_send_reminders – it is scheduled (next run in 1 hours) and WP-Cron runs, so the event no longer sends this email.', $d['text'] );

		$d = Cron::diagnose( '', $crons, self::NOW, false );
		$this->assertSame( 'Sent by WP-Cron. WP-Cron runs normally.', $d['text'] );
	}

	public function test_overdue_single_event(): void {
		$crons = array(
			'version'        => 2,
			self::NOW - 7200 => array(
				'shop_send_reminders' => array( 'x' => array( 'args' => array() ) ),
			),
		);
		// Nothing else is due: no sign of a stalled WP-Cron, only this event is late.
		$this->assertSame( 'overdue', Cron::diagnose( 'shop_send_reminders', $crons, self::NOW, false )['code'] );
	}

	public function test_cron_hook_from_trace_meta(): void {
		$this->assertNull( Indexer::cron_hook( '' ) );
		$this->assertNull( Indexer::cron_hook( '{"anonymised":{"at":1}}' ) );
		$this->assertFalse( Indexer::cron_hook( '{"trace":{"request":{"type":"frontend","path":"/checkout/"}}}' ) );
		$this->assertSame( 'shop_send_reminders', Indexer::cron_hook( '{"trace":{"request":{"type":"cron","hook":"shop_send_reminders"},"hooks":["other"]}}' ) );
		$this->assertSame( 'action_scheduler_run_queue', Indexer::cron_hook( '{"trace":{"request":{"type":"cron"},"hooks":["action_scheduler_run_queue","woocommerce_scheduled_subscription_payment"]}}' ), 'Older traces: outermost hook.' );
		$this->assertSame( '', Indexer::cron_hook( '{"trace":{"request":{"type":"cron"}}}' ) );
	}

	public function test_cron_type_is_the_majority_of_traced_emails(): void {
		$this->assertNull( Indexer::cron_of( array() ) );
		$this->assertSame(
			'shop_send_reminders',
			Indexer::cron_of(
				array(
					'traced'    => 10,
					'cron_n'    => 9,
					'cron_hook' => 'shop_send_reminders',
				)
			)
		);
		$this->assertNull(
			Indexer::cron_of(
				array(
					'traced'    => 10,
					'cron_n'    => 5,
					'cron_hook' => 'x',
				)
			),
			'Half from cron is not a cron type.'
		);
	}

	public function test_alert_text_includes_the_cause(): void {
		$item = array(
			'pattern'   => array( 'Your', 'reminder' ),
			'source'    => 'core',
			'last_seen' => self::NOW - 3 * 86400,
			'rhythm'    => array( 'expected' => 2 ),
			'updates'   => array(),
			'cause'     => array(
				'code' => 'unscheduled',
				'text' => 'Sent by the cron event shop_send_reminders – this event is no longer scheduled.',
			),
		);
		$text = Monitor::message( $item, self::NOW );
		$this->assertStringEndsWith( 'at least every 2 days. Sent by the cron event shop_send_reminders – this event is no longer scheduled.', $text );

		$item['cause'] = null;
		$this->assertStringEndsWith( 'at least every 2 days.', Monitor::message( $item, self::NOW ) );
	}
}
