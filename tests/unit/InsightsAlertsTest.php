<?php
/**
 * Insights alerts: settings sanitizing, counters at send time, state machine (cooldown, recovery), channels.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Logger;
use Mailspur\Modules\Insights\Alerts;
use Mailspur\Modules\Insights\Module;
use Mailspur\Modules\Insights\Stats;

final class InsightsAlertsTest extends TestCase {

	/** @var array<string,mixed> In-memory wp_options. */
	private $options = array();

	/** @var int */
	private $now = 1790000000;

	/** @var array<int,array<string,mixed>> */
	private $mails = array();

	/** @var array<int,array<string,mixed>> */
	private $posts = array();

	/** @var array<int,array<int,mixed>> */
	private $scheduled = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'number_format_i18n'               => static function ( $n, $decimals = 0 ) {
					return number_format( (float) $n, (int) $decimals );
				},
				'sanitize_email'                   => static function ( $email ) {
					return (string) preg_replace( '/[^a-z0-9@._+-]/i', '', (string) $email );
				},
				'esc_url_raw'                      => static function ( $url, $protocols = null ) {
					$scheme = strtolower( (string) parse_url( (string) $url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
					return is_array( $protocols ) && ! in_array( $scheme, $protocols, true ) ? '' : (string) $url;
				},
				'wp_parse_url'                     => static function ( $url, $component = -1 ) {
					return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				},
				'get_bloginfo'                     => 'Example Shop',
				'wp_specialchars_decode'           => static function ( $text ) {
					return $text;
				},
				'admin_url'                        => static function ( $path ) {
					return 'https://site.example/wp-admin/' . $path;
				},
				'add_query_arg'                    => static function ( array $args, string $url ) {
					return $url . '?' . http_build_query( $args );
				},
				'home_url'                         => 'https://site.example/',
				'wp_remote_retrieve_response_code' => static function ( $response ) {
					return $response['response']['code'];
				},
			)
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				$this->mails[] = array(
					'to'      => $to,
					'subject' => $subject,
					'body'    => $body,
					'source'  => Logger::$source_override,
				);
				return true;
			}
		);
		Functions\when( 'wp_safe_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( ...$args ) {
				$this->scheduled[] = $args;
				return true;
			}
		);
	}

	private function alerts(): Alerts {
		return new Alerts(
			function (): int {
				return $this->now;
			}
		);
	}

	/** @param array<string,mixed> $settings */
	private function configure( array $settings ): void {
		$this->options['mailspur_settings'] = array_merge(
			Alerts::defaults(),
			array( 'alert_email' => 'ops@example.com' ),
			$settings
		);
	}

	public function test_defaults_are_off(): void {
		$defaults = Alerts::defaults();
		$this->assertFalse( $defaults['alert_failures'] );
		$this->assertFalse( $defaults['alert_silence'] );
		$this->assertSame( '', $defaults['alert_email'] );
		$this->assertSame( '', $defaults['alert_webhook'] );
		$this->assertFalse( Alerts::enabled( $defaults ) );
	}

	public function test_sanitize(): void {
		$clean = Alerts::sanitize(
			array(
				'alert_failures'         => '1',
				'alert_failures_count'   => '0',
				'alert_failures_minutes' => '99999',
				'alert_silence_hours'    => 'abc',
				'alert_email'            => 'a@example.com, not-an-email; b@example.com a@example.com,c@example.com d@example.com e@example.com f@example.com',
				'alert_webhook'          => 'http://hooks.example/x',
				'alert_cooldown'         => '1',
			)
		);
		$this->assertTrue( $clean['alert_failures'] );
		$this->assertFalse( $clean['alert_silence'] );
		$this->assertFalse( $clean['alert_recovery'] );
		$this->assertSame( 1, $clean['alert_failures_count'] );
		$this->assertSame( 1440, $clean['alert_failures_minutes'] );
		$this->assertSame( 1, $clean['alert_silence_hours'] );
		$this->assertSame( 5, $clean['alert_cooldown'] );
		$this->assertSame( 'a@example.com, b@example.com, c@example.com, d@example.com, e@example.com', $clean['alert_email'] );
		$this->assertSame( '', $clean['alert_webhook'], 'plain http is rejected' );

		$this->assertSame( 'https://hooks.slack.com/services/T/B/x', Alerts::sanitize_webhook( ' https://hooks.slack.com/services/T/B/x ' ) );
		$this->assertSame( '', Alerts::sanitize_webhook( 'javascript:alert(1)' ) );
		$this->assertSame( '', Alerts::sanitize_webhook( 'https://' ) );
	}

	public function test_module_sanitize_merges_alert_keys(): void {
		Functions\expect( 'add_settings_error' )->twice(); // Invalid webhook + alerts without a channel.
		$clean = Module::sanitize(
			array( 'retention_days' => 30 ),
			array(
				'alert_failures' => '1',
				'alert_webhook'  => 'ftp://x',
			)
		);
		$this->assertSame( 30, $clean['retention_days'] );
		$this->assertTrue( $clean['alert_failures'] );
	}

	public function test_failed_mail_schedules_a_check_once_the_threshold_is_reached(): void {
		$this->configure(
			array(
				'alert_failures'       => true,
				'alert_failures_count' => 3,
			)
		);
		$alerts = $this->alerts();

		$alerts->on_logged( 1, array( 'status' => 1 ) ); // Sent: ignored.
		$alerts->on_logged( 2, array( 'status' => 2, 'source' => Stats::ALERT_SOURCE ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- own alert mail: ignored.
		$this->assertArrayNotHasKey( Alerts::FAILS_OPTION, $this->options );

		$alerts->on_logged( 3, array( 'status' => 2 ) );
		$this->now += 60;
		$alerts->on_logged( 4, array( 'status' => 2 ) );
		$this->assertSame( array(), $this->scheduled );
		$this->now += 60;
		$alerts->on_logged( 5, array( 'status' => 2 ) );

		$this->assertSame( array( array( $this->now, Alerts::HOOK_NOW ) ), $this->scheduled );
		$this->assertCount( 3, $this->options[ Alerts::FAILS_OPTION ] );
		$this->assertSame( array(), $this->wpdb->prepared, 'no query at send time' );
	}

	public function test_old_failures_leave_the_window(): void {
		$this->configure(
			array(
				'alert_failures'         => true,
				'alert_failures_count'   => 2,
				'alert_failures_minutes' => 10,
			)
		);
		$alerts = $this->alerts();
		$alerts->on_logged( 1, array( 'status' => 2 ) );
		$this->now += 11 * 60;
		$alerts->on_logged( 2, array( 'status' => 2 ) );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_failure_spike_alert_cooldown_and_recovery(): void {
		$this->configure(
			array(
				'alert_failures' => true,
				'alert_webhook'  => 'https://hooks.slack.com/services/T/B/x',
			)
		);
		$alerts = $this->alerts();

		$this->wpdb->results = array( 7 );
		$this->assertSame( array( 'failures' => true ), $alerts->check() );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'ops@example.com', $this->mails[0]['to'] );
		$this->assertSame( '[Example Shop] Email failure spike', $this->mails[0]['subject'] );
		$this->assertSame( Stats::ALERT_SOURCE, $this->mails[0]['source'], 'alert mails are marked' );
		$this->assertSame( '', Logger::$source_override, 'override is restored' );
		$this->assertStringContainsString( '7 emails failed within the last 15 minutes.', $this->mails[0]['body'] );

		$this->assertCount( 1, $this->posts );
		$this->assertSame( 5, $this->posts[0]['args']['timeout'] );
		$this->assertSame( 0, $this->posts[0]['args']['redirection'] );
		$body = json_decode( $this->posts[0]['args']['body'], true );
		$this->assertStringStartsWith( 'Example Shop – Email failure spike: 7 emails failed', $body['text'] );

		// The query excludes the plugin's own alert mails.
		$this->assertStringContainsString( 'source <> %s', $this->wpdb->prepared[0]['sql'] );
		$this->assertSame( Stats::ALERT_SOURCE, end( $this->wpdb->prepared[0]['args'] ) );

		// Still failing 15 minutes later: within the 60 minute cooldown, nothing is sent.
		$this->now          += 15 * 60;
		$this->wpdb->results = array( 9 );
		$alerts->check();
		$this->assertCount( 1, $this->mails );

		// Recovered: one recovery message.
		$this->now          += 15 * 60;
		$this->wpdb->results = array( 0 );
		$this->assertSame( array( 'failures' => false ), $alerts->check() );
		$this->assertCount( 2, $this->mails );
		$this->assertSame( '[Example Shop] Email failures resolved', $this->mails[1]['subject'] );

		// Nothing more while all is well.
		$this->wpdb->results = array( 0 );
		$alerts->check();
		$this->assertCount( 2, $this->mails );

		$history = Alerts::history();
		$this->assertCount( 2, $history );
		$this->assertSame( 'recovery', $history[0]['kind'] );
		$this->assertSame( 'alert', $history[1]['kind'] );
		$this->assertTrue( $history[1]['email'] );
		$this->assertSame( 200, $history[1]['webhook'] );
	}

	public function test_alert_repeats_after_the_cooldown(): void {
		$this->configure(
			array(
				'alert_failures' => true,
				'alert_recovery' => false,
				'alert_cooldown' => 30,
			)
		);
		$alerts              = $this->alerts();
		$this->wpdb->results = array( 5 );
		$alerts->check();
		$this->now          += 31 * 60;
		$this->wpdb->results = array( 6 );
		$alerts->check();
		$this->assertCount( 2, $this->mails );
	}

	public function test_no_channel_no_alert(): void {
		$this->configure(
			array(
				'alert_failures' => true,
				'alert_email'    => '',
			)
		);
		$this->assertSame( array(), $this->alerts()->check() );
		$this->assertSame( array(), $this->wpdb->prepared );
	}

	public function test_silence_baseline(): void {
		$this->assertNull( Alerts::expected( array_fill( 1, 14, 0 ) ) );
		// Mails on 10 of 14 days, but not on the same weekday a week ago: probably a weekend.
		$counts     = array_fill( 1, 14, 3 );
		$counts[7]  = 0;
		$counts[14] = 0;
		$this->assertNull( Alerts::expected( $counts ) );

		$counts     = array_fill( 1, 14, 4 );
		$counts[3]  = 0;
		$counts[11] = 0;
		$this->assertSame(
			array(
				'days'    => 12,
				'average' => 3.4,
			),
			Alerts::expected( $counts )
		);
	}

	public function test_silence_alert(): void {
		$this->configure(
			array(
				'alert_silence'       => true,
				'alert_silence_hours' => 4,
			)
		);
		// Recent window: 0 mails, then 14 baseline windows with mails.
		$this->wpdb->results = array_merge( array( 0 ), array_fill( 0, 14, 5 ) );
		$this->assertSame( array( 'silence' => true ), $this->alerts()->check() );
		$this->assertSame( '[Example Shop] No emails sent', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'No email was logged in the last 4 hours', $this->mails[0]['body'] );
		$this->assertCount( 15, $this->wpdb->prepared );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now - 4 * HOUR_IN_SECONDS ), $this->wpdb->prepared[0]['args'][1] );
		$this->assertSame(
			array( 'wp_mailspur', gmdate( 'Y-m-d H:i:s', $this->now - DAY_IN_SECONDS - 4 * HOUR_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $this->now - DAY_IN_SECONDS ) ),
			$this->wpdb->prepared[1]['args']
		);
	}

	public function test_mails_in_the_window_mean_no_silence(): void {
		$this->configure( array( 'alert_silence' => true ) );
		$this->wpdb->results = array( 2 );
		$this->assertSame( array( 'silence' => false ), $this->alerts()->check() );
		$this->assertCount( 1, $this->wpdb->prepared, 'baseline is only computed when needed' );
		$this->assertSame( array(), $this->mails );
	}

	public function test_webhook_payloads(): void {
		$slack = Alerts::payload( 'https://hooks.slack.com/services/T/B/x', 'failures', 'alert', 'Shop', 'Title', 'Msg', 'https://l' );
		$this->assertSame( array( 'text' => "Shop – Title: Msg\n<https://l|Open the mail log>" ), $slack );

		$discord = Alerts::payload( 'https://discord.com/api/webhooks/1/abc', 'silence', 'alert', 'Shop', 'Title', 'Msg', 'https://l' );
		$this->assertSame( array( 'content' => "Shop – Title: Msg\nhttps://l" ), $discord );

		$generic = Alerts::payload( 'https://ops.example/hook', 'silence', 'recovery', 'Shop', 'Title', 'Msg', 'https://l' );
		$this->assertSame( 'mailspur.recovery', $generic['event'] );
		$this->assertSame( 'silence', $generic['type'] );
		$this->assertSame( 'https://l', $generic['log_url'] );
		$this->assertSame( 'https://site.example/', $generic['site_url'] );
	}

	public function test_history_keeps_the_last_50(): void {
		$alerts = $this->alerts();
		for ( $i = 0; $i < 55; $i++ ) {
			$alerts->dispatch( 'test', 'test', 'Message ' . $i, array() );
		}
		$history = Alerts::history();
		$this->assertCount( Alerts::HISTORY, $history );
		$this->assertSame( 'Message 54', $history[0]['message'] );
		$this->assertNull( $history[0]['email'] );
		$this->assertNull( $history[0]['webhook'] );
	}

	public function test_webhook_errors_are_recorded(): void {
		Functions\when( 'wp_safe_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'A valid URL was not provided.' ) );
		$result = $this->alerts()->dispatch( 'test', 'test', 'x', array( 'alert_webhook' => 'https://127.0.0.1/x' ) );
		$this->assertSame( 'A valid URL was not provided.', $result['webhook'] );
	}

	public function test_cron_schedule(): void {
		$schedules = Alerts::cron_schedules( array() );
		$this->assertSame( 900, $schedules[ Alerts::SCHEDULE ]['interval'] );
	}
}
