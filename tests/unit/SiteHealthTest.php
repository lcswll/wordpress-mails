<?php
/**
 * Site Health: thresholds and statuses of the tests, who gets them, the async sender test and the Info section.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Delivery\Brake;
use Mailspur\Modules\SiteHealth\Checks;
use Mailspur\Modules\SiteHealth\Module;
use Mailspur\Modules\Types\Store;
use Mailspur\Repository;

final class SiteHealthTest extends TestCase {

	const TODAY = '2026-10-01';

	/** @var int 2026-10-01 12:00 UTC */
	private $now;

	/** @var array<string,mixed> */
	private $options = array();

	/** @var array<string,mixed> */
	private $transients = array();

	/** @var string */
	private $environment = 'production';

	/** @var bool */
	private $can = true;

	protected function setUp(): void {
		parent::setUp();
		$this->now         = (int) strtotime( self::TODAY . ' 12:00:00 UTC' );
		$this->options     = array();
		$this->transients  = array();
		$this->environment = 'production';
		$this->can         = true;
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\stubs(
			array(
				'number_format_i18n'      => static function ( $n ) {
					return (string) $n;
				},
				'size_format'             => static function ( $bytes ) {
					return $bytes . ' B';
				},
				'human_time_diff'         => static function ( $from, $to ) {
					return round( ( $to - $from ) / 86400 ) . ' days';
				},
				'wp_date'                 => static function ( $format, $time ) {
					return gmdate( $format, $time );
				},
				'admin_url'               => static function ( $path = '' ) {
					return 'https://shop.example.de/wp-admin/' . $path;
				},
				'add_query_arg'           => static function ( array $args, string $url ) {
					return $url . '?' . http_build_query( $args );
				},
				'rest_url'                => static function ( $path = '' ) {
					return 'https://shop.example.de/wp-json/' . $path;
				},
				'network_home_url'        => 'https://www.shop.example.de',
				'wp_parse_url'            => static function ( $url, $component = -1 ) {
					return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				},
				'wp_get_environment_type' => function () {
					return $this->environment;
				},
				'wp_doing_cron'           => false,
				'current_user_can'        => function () {
					return $this->can;
				},
				'get_plugins'             => array( 'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce' ) ),
				'get_mu_plugins'          => array(),
				'get_option'              => function ( $name, $fallback = false ) {
					if ( 'mailspur_settings' === $name ) {
						return $this->settings;
					}
					return $this->options[ $name ] ?? $fallback;
				},
				'get_transient'           => function ( $key ) {
					return $this->transients[ $key ] ?? false;
				},
				'set_transient'           => function ( $key, $value ) {
					$this->transients[ $key ] = $value;
					return true;
				},
			)
		);
	}

	private function checks( ?callable $resolver = null ): Checks {
		return new Checks(
			$resolver,
			function (): int {
				return $this->now;
			}
		);
	}

	/**
	 * Queues the two results of the failure test: counts per status and the statuses of the latest emails.
	 *
	 * @param int[] $latest Newest first.
	 * @return array<string,mixed>
	 */
	private function failures( int $sent, int $failed, array $latest ): array {
		$this->wpdb->results[] = array(
			array(
				'status' => '1',
				'n'      => (string) $sent,
			),
			array(
				'status' => '2',
				'n'      => (string) $failed,
			),
		);
		$this->wpdb->results[] = array_map(
			static function ( int $status ): array {
				return array( 'status' => (string) $status );
			},
			$latest
		);
		return $this->checks()->failures();
	}

	public function test_failure_thresholds(): void {
		$this->assertSame( 'good', Checks::failure_level( 0, 0, 0 ) );
		$this->assertSame( 'good', Checks::failure_level( 500, 1, 0 ) );
		$this->assertSame( 'good', Checks::failure_level( 500, 2, 0 ) ); // 0.4 %.
		$this->assertSame( 'good', Checks::failure_level( 1, 1, 1 ) ); // A single mistyped address on a quiet site.
		$this->assertSame( 'recommended', Checks::failure_level( 500, 3, 0 ) );
		$this->assertSame( 'recommended', Checks::failure_level( 10, 2, 0 ) ); // 17 %.
		$this->assertSame( 'recommended', Checks::failure_level( 100, 10, 0 ) ); // 9 %, but 10 emails.
		$this->assertSame( 'critical', Checks::failure_level( 20, 5, 0 ) ); // 20 %.
		$this->assertSame( 'critical', Checks::failure_level( 500, 2, 3 ) ); // The latest three failed.
		$this->assertSame( 'recommended', Checks::failure_level( 500, 4, 2 ) );
	}

	public function test_failures_result_texts_and_queries(): void {
		$good = $this->failures( 120, 0, array( 1, 1, 1 ) );
		$this->assertSame( 'good', $good['status'] );
		$this->assertSame( 'mailspur_failures', $good['test'] );
		$this->assertSame(
			array(
				'label' => 'Mailspur',
				'color' => 'blue',
			),
			$good['badge']
		);
		$this->assertStringContainsString( 'status=failed', $good['actions'] );
		$this->assertStringStartsWith( '<p>', $good['description'] );

		// Both queries skip Mailspur's own alerts and only look at sent and failed emails of the last 7 days.
		$this->assertCount( 2, $this->wpdb->prepared );
		foreach ( $this->wpdb->prepared as $query ) {
			$this->assertStringContainsString( 'source <> %s', $query['sql'] );
			$this->assertSame( array( Repository::table(), 1, 2, '2026-09-24 12:00:00', 'mailspur:alert' ), array_slice( $query['args'], 0, 5 ) );
		}

		$single = $this->failures( 120, 1, array( 1, 2, 1 ) );
		$this->assertSame( 'good', $single['status'] );
		$this->assertStringContainsString( '1 of 121 emails in the last 7 days could not be sent.', $single['description'] );

		$some = $this->failures( 120, 4, array( 1, 1, 2 ) );
		$this->assertSame( 'recommended', $some['status'] );
		$this->assertSame( 'Some emails could not be sent recently', $some['label'] );

		$broken = $this->failures( 120, 3, array( 2, 2, 2 ) );
		$this->assertSame( 'critical', $broken['status'] );
		$this->assertSame( 'The latest emails could not be sent', $broken['label'] );
		$this->assertStringContainsString( 'The last 3 emails all failed.', $broken['description'] );

		$many = $this->failures( 10, 10, array( 1, 2, 2 ) );
		$this->assertSame( 'critical', $many['status'] );
		$this->assertSame( 'Many emails could not be sent recently', $many['label'] );
	}

	public function test_no_emails_at_all_is_no_problem(): void {
		$this->wpdb->results[] = array();
		$this->wpdb->results[] = array();
		$result                = $this->checks()->failures();
		$this->assertSame( 'good', $result['status'] );
	}

	public function test_staging_mode_is_only_a_problem_on_a_live_site(): void {
		$off = $this->checks()->staging();
		$this->assertSame( 'good', $off['status'] );
		$this->assertStringContainsString( '#mailspur-staging', $off['actions'] );

		$this->settings = array( 'staging_mode' => 'hold' );
		$live           = $this->checks()->staging();
		$this->assertSame( 'recommended', $live['status'] );
		$this->assertStringContainsString( 'Emails are logged but not delivered.', $live['description'] );

		$this->settings = array(
			'staging_mode'        => 'redirect',
			'staging_redirect_to' => 'qa@example.net',
		);
		$redirect       = $this->checks()->staging();
		$this->assertSame( 'recommended', $redirect['status'] );
		$this->assertStringNotContainsString( 'qa@example.net', $redirect['description'] );

		foreach ( array( 'staging', 'development', 'local' ) as $environment ) {
			$this->environment = $environment;
			$this->assertSame( 'good', $this->checks()->staging()['status'], $environment );
		}
	}

	public function test_emergency_brake(): void {
		$this->assertSame( 'good', $this->checks()->brake()['status'] );

		$this->options[ Brake::STATE_OPTION ] = array( 'active' => true );
		$alert                                = $this->checks()->brake();
		$this->assertSame( 'recommended', $alert['status'] );

		$this->settings        = array( 'brake_mode' => 'hold' );
		$this->wpdb->results[] = '12';
		$held                  = $this->checks()->brake();
		$this->assertSame( 'critical', $held['status'] );
		$this->assertStringContainsString( '12 emails are waiting', $held['description'] );

		// Incident over, but held emails still wait for a decision.
		$this->options[ Brake::STATE_OPTION ] = array( 'holding' => true );
		$this->wpdb->results[]                = '0';
		$this->assertSame( 'critical', $this->checks()->brake()['status'] );
	}

	/**
	 * @param string[]|null $spf   TXT values of the domain (null: lookup failed).
	 * @param string[]|null $dmarc TXT values of _dmarc.
	 * @param int           $calls Number of lookups.
	 */
	private function resolver( ?array $spf, ?array $dmarc, int &$calls ): callable {
		return static function ( string $host ) use ( $spf, $dmarc, &$calls ) {
			++$calls;
			$values = 0 === strpos( $host, '_dmarc.' ) ? $dmarc : $spf;
			return null === $values ? null : array_map(
				static function ( string $txt ): array {
					return array( 'txt' => $txt );
				},
				$values
			);
		};
	}

	public function test_sender_domain_with_spf_and_dmarc_is_good_and_cached(): void {
		$calls  = 0;
		$checks = $this->checks( $this->resolver( array( 'v=spf1 include:_spf.example.net ~all', 'google-site-verification=x' ), array( 'v=DMARC1; p=none' ), $calls ) );
		$result = $checks->sender();
		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'The sender domain shop.example.de has SPF and DMARC records', $result['label'] );
		$this->assertSame( 2, $calls );

		$checks->sender();
		$this->assertSame( 2, $calls, 'cached' );
	}

	public function test_sender_domain_without_records(): void {
		$calls = 0;
		$none  = $this->checks( $this->resolver( array( 'google-site-verification=x' ), array(), $calls ) )->sender();
		$this->assertSame( 'recommended', $none['status'] );
		$this->assertSame( 'No SPF and no DMARC record for the sender domain shop.example.de', $none['label'] );
		$this->assertStringContainsString( '#mailspur-sender-check', $none['actions'] );

		$this->transients = array();
		$dmarc            = $this->checks( $this->resolver( array( 'v=spf1 -all' ), array( 'something else' ), $calls ) )->sender();
		$this->assertSame( 'No DMARC record for the sender domain shop.example.de', $dmarc['label'] );

		$this->transients = array();
		$spf              = $this->checks( $this->resolver( array(), array( 'v=DMARC1; p=reject' ), $calls ) )->sender();
		$this->assertSame( 'No SPF record for the sender domain shop.example.de', $spf['label'] );
	}

	public function test_sender_lookup_failures_and_local_domains_are_no_false_alarm(): void {
		$calls  = 0;
		$failed = $this->checks( $this->resolver( null, null, $calls ) )->sender();
		$this->assertSame( 'good', $failed['status'] );
		$this->assertSame( 'The sender domain could not be checked', $failed['label'] );
		$this->assertSame( 'good', Checks::sender_level( null, true ) );
		$this->assertSame( 'recommended', Checks::sender_level( null, false ) );

		Functions\when( 'network_home_url' )->justReturn( 'http://localhost:8080' );
		$calls = 0;
		$local = $this->checks( $this->resolver( array(), array(), $calls ) )->sender();
		$this->assertSame( 'good', $local['status'] );
		$this->assertSame( 0, $calls );
	}

	public function test_stopped_email_types_from_the_stored_type_data(): void {
		$this->assertSame( 'good', $this->checks()->types()['status'], 'tables not installed yet' );

		$this->options[ Store::DB_OPTION ] = Store::DB_VERSION;
		$this->wpdb->results[]             = array(
			array(
				'id'          => '4',
				'source'      => 'plugin:woocommerce',
				'pattern'     => Store::encode( array( 'New', 'order', '#{#}' ) ),
				'first_seen'  => '2026-07-01 08:00:00',
				'last_seen'   => gmdate( 'Y-m-d H:i:s', $this->now - 5 * 86400 ),
				'last_status' => '1',
				'last_notes'  => '0',
				'muted'       => '0',
				'extra'       => '',
			),
		);
		$days                              = array();
		for ( $i = 5; $i <= 50; $i++ ) {
			$days[] = array(
				'type_id' => '4',
				'day'     => gmdate( 'Y-m-d', $this->now - $i * 86400 ),
				'total'   => '3',
				'failed'  => '0',
				'held'    => '0',
			);
		}
		$this->wpdb->results[] = $days;

		$result = $this->checks()->types();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'Emails that are sent regularly have stopped (1)', $result['label'] );
		$this->assertStringContainsString( '<li>“New order #…” (WooCommerce) was last sent 5 days ago.', $result['description'] );
		$this->assertStringContainsString( 'tab=types', $result['actions'] );
	}

	public function test_tests_are_registered_only_for_users_who_may_view_the_log(): void {
		$module = new Module( new Repository() );
		$tests  = $module->tests(
			array(
				'direct' => array( 'core' => array() ),
				'async'  => array(),
			)
		);
		$this->assertSame( array( 'core', 'mailspur_failures', 'mailspur_types', 'mailspur_brake', 'mailspur_staging' ), array_keys( $tests['direct'] ) );
		$this->assertIsCallable( $tests['direct']['mailspur_failures']['test'] );
		$this->assertTrue( $tests['async']['mailspur_sender']['has_rest'] );
		$this->assertSame( 'https://shop.example.de/wp-json/mailspur-email-log/v1/site-health/sender', $tests['async']['mailspur_sender']['test'] );
		$this->assertIsCallable( $tests['async']['mailspur_sender']['async_direct_test'] );

		$this->can = false;
		$this->assertSame( array( 'direct' => array() ), $module->tests( array( 'direct' => array() ) ) );
		$this->assertSame( array( 'x' => 1 ), $module->debug( array( 'x' => 1 ) ) );
	}

	public function test_debug_information_without_addresses(): void {
		$this->settings                    = array(
			'retention_days'      => 30,
			'max_entries'         => 0,
			'staging_mode'        => 'redirect',
			'staging_redirect_to' => 'qa@example.net',
			'alert_email'         => 'owner@example.net',
			'feedback_provider'   => 'postmark',
		);
		$this->options[ Store::DB_OPTION ] = Store::DB_VERSION;
		$this->wpdb->results               = array(
			array(
				'Data_length'  => '2048',
				'Index_length' => '1024',
			),
			'1234',
			'17',
		);

		$info   = ( new Module( new Repository() ) )->debug( array() );
		$fields = $info['mailspur-email-log']['fields'];
		$this->assertSame( 'Mailspur', $info['mailspur-email-log']['label'] );
		$this->assertSame( '1234', $fields['entries']['value'] );
		$this->assertSame( '3072 B', $fields['size']['value'] );
		$this->assertSame( '30 days', $fields['retention']['value'] );
		$this->assertSame( 'No limit', $fields['max']['value'] );
		$this->assertSame( 'redirect', $fields['staging']['debug'] );
		$this->assertSame( 'alert', $fields['brake']['debug'] );
		$this->assertSame( 'Postmark', $fields['feedback']['value'] );
		$this->assertSame( 17, $fields['types']['debug'] );
		$this->assertStringNotContainsString( '@', (string) wp_json_encode( $info ) );
	}
}
