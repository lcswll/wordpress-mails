<?php
/**
 * Weekly report: only sent when there is something to report, composition (numbers, previous week, type lists,
 * escaping, links), type and brake events of the week, schedule and the setting.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Insights\Module;
use Mailspur\Modules\Insights\Weekly;

final class InsightsWeeklyTest extends TestCase {

	/** @var int Monday 2026-10-05 00:00 UTC */
	private $from;

	protected function setUp(): void {
		parent::setUp();
		$this->from = (int) strtotime( '2026-09-28 00:00:00 UTC' );
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\stubs(
			array(
				'number_format_i18n' => static function ( $n, $decimals = 0 ) {
					return number_format( (float) $n, (int) $decimals );
				},
				'wp_date'            => static function ( $format, $timestamp = null ) {
					return gmdate( $format, $timestamp ?? time() );
				},
				'get_option'         => static function ( $name, $fallback = false ) {
					return 'date_format' === $name ? 'j M Y' : $fallback;
				},
				'wp_timezone'        => static function () {
					return new \DateTimeZone( 'Europe/Berlin' );
				},
				'get_plugins'        => array( 'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce' ) ),
				'get_mu_plugins'     => array(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function data( array $extra = array() ): array {
		$counts = static function ( int $total, int $failed ): array {
			return array(
				'total'   => $total,
				'sent'    => $total - $failed,
				'failed'  => $failed,
				'held'    => 0,
				'pending' => 0,
			);
		};
		return array_merge(
			array(
				'from'     => $this->from,
				'to'       => $this->from + 7 * 86400 - 1,
				'week'     => $counts( 200, 5 ),
				'previous' => $counts( 100, 1 ),
				'notes'    => 0,
				'new'      => array(),
				'stopped'  => array(),
				'changed'  => array(),
				'brake'    => 0,
				'links'    => array(
					'log'    => 'https://site.example/wp-admin/admin.php?page=mailspur-email-log',
					'failed' => 'https://site.example/wp-admin/admin.php?page=mailspur-email-log&status=failed',
					'notes'  => 'https://site.example/wp-admin/admin.php?page=mailspur-email-log&notes=1',
					'types'  => 'https://site.example/wp-admin/admin.php?page=mailspur-email-log&tab=types',
				),
			),
			$extra
		);
	}

	public function test_nothing_to_report_sends_nothing_unless_forced(): void {
		$zero  = array(
			'total'   => 0,
			'sent'    => 0,
			'failed'  => 0,
			'held'    => 0,
			'pending' => 0,
		);
		$quiet = $this->data(
			array(
				'week'     => $zero,
				'previous' => $zero,
			)
		);
		$this->assertNull( Weekly::compose( $quiet, 'Shop' ) );

		$forced = Weekly::compose( $quiet, 'Shop', true );
		$this->assertNotNull( $forced );
		$this->assertStringContainsString( 'No emails were logged in the last two weeks.', $forced['body'] );

		// Silence after a busy week, or a brake incident alone, is worth a report.
		$this->assertNotNull( Weekly::compose( $this->data( array( 'week' => $zero ) ), 'Shop' ) );
		$this->assertNotNull(
			Weekly::compose(
				$this->data(
					array(
						'week'     => $zero,
						'previous' => $zero,
						'brake'    => 1,
					)
				),
				'Shop'
			)
		);
	}

	public function test_volume_and_failure_rate_compared_with_previous_week(): void {
		$mail = Weekly::compose( $this->data( array( 'notes' => 3 ) ), 'Shop' );

		$this->assertSame( '[Shop] Weekly email report 28 Sep 2026 – 4 Oct 2026', $mail['subject'] );
		$this->assertStringContainsString( '200 (previous week: 100)', $mail['body'] );
		$this->assertStringContainsString( '5 – failure rate 2.5 % (previous week: 1.0 %)', $mail['body'] );
		$this->assertStringContainsString( 'status=failed', $mail['body'] );
		$this->assertStringContainsString( 'Emails with notes', $mail['body'] );
		$this->assertStringContainsString( 'notes=1', $mail['body'] );
		$this->assertStringNotContainsString( 'Emergency brake', $mail['body'] );
		$this->assertStringNotContainsString( 'Held', $mail['body'] );
		$this->assertStringContainsString( 'Open the mail log', $mail['body'] );
	}

	public function test_type_lists_are_escaped_capped_and_linked(): void {
		$stopped = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$stopped[] = '“Reminder ' . $i . '” (Plugin)';
		}
		$mail = Weekly::compose(
			$this->data(
				array(
					'new'     => array( '“<script>alert(1)</script>” (Forms)' ),
					'stopped' => $stopped,
					'brake'   => 2,
				)
			),
			'Shop & Co'
		);

		$this->assertStringContainsString( 'Stopped email types', $mail['body'] );
		$this->assertStringContainsString( 'New email types', $mail['body'] );
		$this->assertStringNotContainsString( 'Content changed', $mail['body'] );
		$this->assertStringNotContainsString( '<script>', $mail['body'] );
		$this->assertStringContainsString( '&lt;script&gt;', $mail['body'] );
		$this->assertStringContainsString( 'Reminder 10', $mail['body'] );
		$this->assertStringNotContainsString( 'Reminder 11', $mail['body'] );
		$this->assertStringContainsString( 'and 2 more', $mail['body'] );
		$this->assertStringContainsString( 'tab=types', $mail['body'] );
		$this->assertStringContainsString( 'triggered 2 times', $mail['body'] );
		$this->assertStringContainsString( 'Shop &amp; Co', $mail['body'] );
	}

	public function test_types_of_the_week(): void {
		$item  = static function ( int $id, string $state, int $first, ?int $changed = null, bool $muted = false ): array {
			return array(
				'id'         => $id,
				'source'     => 'plugin:woocommerce',
				'pattern'    => array( 'Order', '{#}', $id ),
				'other'      => false,
				'muted'      => $muted,
				'state'      => $state,
				'first_seen' => $first,
				'change'     => null === $changed ? null : array( 'after_at' => $changed ),
			);
		};
		$old   = $this->from - 30 * 86400;
		$types = Weekly::types(
			array(
				$item( 1, 'new', $this->from + 3600 ),
				$item( 2, 'silent', $old ),
				$item( 3, 'ok', $old, $this->from + 86400 ),
				$item( 4, 'ok', $old, $this->from - 86400 ),
				$item( 5, 'silent', $old, null, true ),
			),
			$this->from
		);

		$this->assertSame( array( '“Order … 1” (WooCommerce)' ), $types['new'] );
		$this->assertSame( array( '“Order … 2” (WooCommerce)' ), $types['stopped'] );
		$this->assertSame( array( '“Order … 3” (WooCommerce)' ), $types['changed'] );
	}

	public function test_brake_incidents_that_started_this_week(): void {
		$to    = $this->from + 7 * 86400;
		$state = array(
			'history' => array( array( $this->from - 3600, $this->from + 60 ), array( $this->from + 7200, $this->from + 9000 ) ),
			'active'  => true,
			'start'   => $to - 600,
		);
		$this->assertSame( 2, Weekly::incidents( $state, $this->from, $to ) );
		$this->assertSame( 0, Weekly::incidents( array(), $this->from, $to ) );
	}

	public function test_first_run_is_next_monday_morning_site_time(): void {
		$sunday = (int) strtotime( '2026-10-04 20:00:00 UTC' );
		$this->assertSame( (int) strtotime( '2026-10-05 06:00:00 UTC' ), Weekly::first_run( $sunday ) ); // 08:00 CEST.
	}

	public function test_setting_is_off_by_default_and_needs_an_email_recipient(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( null );

		$this->assertFalse( Module::defaults( array() )['weekly_report'] );
		$this->assertTrue( Module::sanitize( array(), array( 'weekly_report' => '1' ) )['weekly_report'] );
		$this->assertFalse( Module::sanitize( array(), array() )['weekly_report'] );

		$this->assertFalse( Weekly::enabled( array( 'weekly_report' => true ) ) );
		$this->assertFalse(
			Weekly::enabled(
				array(
					'weekly_report' => false,
					'alert_email'   => 'ops@example.com',
				)
			)
		);
		$this->assertTrue(
			Weekly::enabled(
				array(
					'weekly_report' => true,
					'alert_email'   => 'ops@example.com',
				)
			)
		);
	}
}
