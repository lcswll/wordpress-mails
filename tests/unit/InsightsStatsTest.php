<?php
/**
 * Insights statistics: time zone / DST bucketing, top lists, queries and caching.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use DateTimeZone;
use Mailspur\Modules\Insights\Controller;
use Mailspur\Modules\Insights\Dashboard;
use Mailspur\Modules\Insights\Page;
use Mailspur\Modules\Insights\Stats;
use Mailspur\Repository;

final class InsightsStatsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'number_format_i18n' => static function ( $n, $decimals = 0 ) {
					return number_format( (float) $n, (int) $decimals );
				},
				'determine_locale'   => 'en_US',
			)
		);
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
	}

	public function test_utc_range_follows_the_site_time_zone_across_dst(): void {
		$berlin = new DateTimeZone( 'Europe/Berlin' );
		// 29 March 2026: clocks go forward, the local day has 23 hours.
		$this->assertSame( array( '2026-03-28 23:00:00', '2026-03-29 22:00:00' ), Stats::utc_range( '2026-03-29', '2026-03-29', $berlin ) );
		$this->assertSame( array( '2026-01-31 23:00:00', '2026-02-02 23:00:00' ), Stats::utc_range( '2026-02-01', '2026-02-02', $berlin ) );
		$this->assertSame( 3, Stats::days_between( '2026-03-28', '2026-03-30' ) );
	}

	public function test_aggregate_converts_utc_hours_to_local_days_and_hours(): void {
		$buckets = array(
			array(
				'h'      => '2026-03-28 22', // 23:00 CET, Saturday 28th.
				'status' => '1',
				'n'      => '3',
			),
			array(
				'h'      => '2026-03-28 23', // 00:00 CET, Sunday 29th.
				'status' => '2',
				'n'      => '1',
			),
			array(
				'h'      => '2026-03-29 01', // 03:00 CEST (after the switch), Sunday 29th.
				'status' => '1',
				'n'      => '4',
			),
			array(
				'h'      => '2026-03-29 22', // 00:00 CEST, Monday 30th.
				'status' => '3',
				'n'      => '2',
			),
			array(
				'h'      => '2026-03-30 10',
				'status' => '0',
				'n'      => '1',
			),
		);

		$data = Stats::aggregate( $buckets, '2026-03-28', '2026-03-30', new DateTimeZone( 'Europe/Berlin' ) );

		$this->assertSame( array( 'all' => 11, 'sent' => 7, 'failed' => 1, 'held' => 2, 'pending' => 1, 'rate' => 9.1 ), $data['totals'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( '2026-03-28', '2026-03-29', '2026-03-30' ), array_column( $data['days'], 'date' ) );
		$this->assertSame( 3, $data['days'][0]['sent'] );
		$this->assertSame( 4, $data['days'][1]['sent'] );
		$this->assertSame( 1, $data['days'][1]['failed'] );
		$this->assertSame( 2, $data['days'][2]['held'] );
		$this->assertSame( 1, $data['days'][2]['pending'] );

		$this->assertSame( 3, $data['heatmap'][6][23] ); // Saturday 23:00.
		$this->assertSame( 1, $data['heatmap'][0][0] );  // Sunday 00:00.
		$this->assertSame( 4, $data['heatmap'][0][3] );  // Sunday 03:00 – 02:00 does not exist that day.
		$this->assertSame( 2, $data['heatmap'][1][0] );  // Monday 00:00.
		$this->assertSame( 1, $data['heatmap'][1][12] ); // Monday 12:00 CEST.
		$this->assertSame( 11, array_sum( array_map( 'array_sum', $data['heatmap'] ) ) );

		$this->assertSame(
			array(
				'date'  => '2026-03-29',
				'count' => 5,
			),
			$data['busiest']
		);
		$this->assertSame( 3.7, $data['average'] );
	}

	public function test_half_hour_time_zones_keep_the_first_bucket_in_range(): void {
		$kolkata       = new DateTimeZone( 'Asia/Kolkata' );
		list( $start ) = Stats::utc_range( '2026-05-10', '2026-05-10', $kolkata );
		$this->assertSame( '2026-05-09 18:30:00', $start );

		$data = Stats::aggregate(
			array(
				array(
					'h'      => '2026-05-09 18', // Bucket starts 23:30 local, rows are ≥ 00:00 local.
					'status' => '1',
					'n'      => '2',
				),
			),
			'2026-05-10',
			'2026-05-10',
			$kolkata
		);
		$this->assertSame( 2, $data['totals']['all'] );
		$this->assertSame( 2, $data['days'][0]['sent'] );
	}

	public function test_empty_range(): void {
		$data = Stats::aggregate( array(), '2026-01-01', '2026-01-07', new DateTimeZone( 'UTC' ) );
		$this->assertSame( 0, $data['totals']['all'] );
		$this->assertSame( 0.0, $data['totals']['rate'] );
		$this->assertNull( $data['busiest'] );
		$this->assertCount( 7, $data['days'] );
	}

	public function test_previous_period(): void {
		$prev = Stats::previous(
			array(
				array(
					'status' => '1',
					'n'      => '30',
				),
				array(
					'status' => '2',
					'n'      => '10',
				),
			),
			'2026-03-08',
			7
		);
		$this->assertSame(
			array(
				'from'   => '2026-03-01',
				'to'     => '2026-03-07',
				'all'    => 40,
				'failed' => 10,
				'rate'   => 25.0,
			),
			$prev
		);
	}

	public function test_subjects_are_grouped_without_numbers(): void {
		$this->assertSame( '[Example Shop] Your order #… has been received', Stats::normalize_subject( '[Example Shop] Your order #125600 has been received' ) );
		$this->assertSame( 'Invoice … from …', Stats::normalize_subject( "Invoice  2026-0042\tfrom 01.03.2026" ) );
		$this->assertSame( '…', Stats::normalize_subject( '12 34' ) );
		$this->assertSame( '', Stats::normalize_subject( '   ' ) );
		$this->assertSame( '[Example Shop] Your order #', Stats::search_term( '[Example Shop] Your order #… has been received' ) );
	}

	public function test_top_lists(): void {
		$rows = array(
			array(
				'source'     => 'plugin:woo',
				'status'     => '2',
				'recipients' => 'Anna <anna@Shop.example>, bob@shop.example',
				'subject'    => 'Order #1',
			),
			array(
				'source'     => 'plugin:woo',
				'status'     => '1',
				'recipients' => 'carl@other.example',
				'subject'    => 'Order #2',
			),
			array(
				'source'     => 'core',
				'status'     => '1',
				'recipients' => 'dora@shop.example',
				'subject'    => 'Password reset',
			),
		);
		Functions\when( 'get_plugins' )->justReturn( array( 'woo/woo.php' => array( 'Name' => 'WooCommerce' ) ) );

		$top = Stats::top_lists( $rows );

		$this->assertSame(
			array(
				array(
					'key'    => 'plugin:woo',
					'label'  => 'WooCommerce',
					'count'  => 2,
					'failed' => 1,
				),
				array(
					'key'    => 'core',
					'label'  => 'WordPress',
					'count'  => 1,
					'failed' => 0,
				),
			),
			$top['sources']
		);
		// A mail to two addresses of the same domain counts once.
		$this->assertSame( array( 'shop.example', 'other.example' ), array_column( $top['domains'], 'label' ) );
		$this->assertSame( array( 2, 1 ), array_column( $top['domains'], 'count' ) );
		$this->assertSame( '@shop.example', $top['domains'][0]['search'] );
		$this->assertSame( 'Order #…', $top['subjects'][0]['label'] );
		$this->assertSame( 2, $top['subjects'][0]['count'] );
		$this->assertSame( 'Order #', $top['subjects'][0]['search'] );
	}

	public function test_top_lists_are_capped(): void {
		$rows = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$rows[] = array(
				'source'     => 'theme:t' . $i,
				'status'     => '1',
				'recipients' => "x@d{$i}.example",
				'subject'    => 'Subject ' . chr( 65 + $i % 26 ) . chr( 65 + intdiv( $i, 26 ) ),
			);
		}
		Functions\when( 'wp_get_theme' )->justReturn(
			new class() {
				public function exists(): bool {
					return false;
				}
			}
		);
		$top = Stats::top_lists( $rows );
		$this->assertCount( Stats::TOP, $top['sources'] );
		$this->assertCount( Stats::TOP, $top['domains'] );
		$this->assertCount( Stats::TOP, $top['subjects'] );
	}

	public function test_source_labels(): void {
		$this->assertSame( 'WordPress', Stats::source_label( 'core' ) );
		$this->assertSame( 'Resent from log', Stats::source_label( 'mailspur:resend' ) );
		$this->assertSame( 'Mailspur alerts', Stats::source_label( Stats::ALERT_SOURCE ) );
		$this->assertSame( 'My Mailer', Stats::source_label( 'mu-plugin:my-mailer' ) );
		$this->assertSame( 'Imported from WP Mail Logging', Stats::source_label( 'import:wp-mail-logging' ) );
	}

	public function test_compute_uses_covering_index_queries(): void {
		$this->wpdb->results = array(
			array(
				array(
					'h'      => '2026-03-10 08',
					'status' => '1',
					'n'      => '5',
				),
			),
			array(
				array(
					'status' => '1',
					'n'      => '4',
				),
			),
			array(
				array(
					'source'     => 'core',
					'status'     => '1',
					'recipients' => 'a@b.example',
					'subject'    => 'Hi',
				),
			),
		);

		$data = ( new Stats( new Repository() ) )->compute( '2026-03-10', '2026-03-16', new DateTimeZone( 'UTC' ) );

		$series = $this->wpdb->prepared[0];
		$this->assertStringContainsString( 'status IN (0,1,2,3) AND created_at >= %s AND created_at < %s GROUP BY h, status', $series['sql'] );
		$this->assertStringContainsString( 'FROM %i', $series['sql'] );
		$this->assertSame( array( 'wp_mailspur', '2026-03-10 00:00:00', '2026-03-17 00:00:00' ), $series['args'] );
		// Previous period: the 7 days before.
		$this->assertSame( array( 'wp_mailspur', '2026-03-03 00:00:00', '2026-03-10 00:00:00' ), $this->wpdb->prepared[1]['args'] );
		$this->assertStringContainsString( 'LIMIT %d', $this->wpdb->prepared[2]['sql'] );
		$this->assertSame( Stats::SAMPLE, $this->wpdb->prepared[2]['args'][3] );

		$this->assertSame( 5, $data['totals']['all'] );
		$this->assertSame( 4, $data['previous']['all'] );
		$this->assertSame( 7, $data['range']['days'] );
		$this->assertFalse( $data['sample']['limited'] );
		$this->assertSame( 'core', $data['top']['sources'][0]['key'] );
	}

	public function test_get_serves_from_the_transient_cache(): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );
		Functions\when( 'get_transient' )->justReturn( array( 'cached' => true ) );
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( array( 'cached' => true ), ( new Stats( new Repository() ) )->get( '2026-01-01', '2026-01-31' ) );
		$this->assertSame( array(), $this->wpdb->prepared );
	}

	public function test_past_ranges_are_cached_longer_than_ranges_with_today(): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );
		Functions\when( 'get_transient' )->justReturn( false );
		$ttls = array();
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $ttl ) use ( &$ttls ) {
				$ttls[] = $ttl;
				return true;
			}
		);
		$stats = new Stats( new Repository() );
		$stats->get( '2020-01-01', '2020-01-31' );
		$today = gmdate( 'Y-m-d' );
		$stats->get( $today, $today );

		$this->assertSame( array( 12 * HOUR_IN_SECONDS, 2 * MINUTE_IN_SECONDS ), $ttls );
	}

	public function test_flush_bumps_the_generation(): void {
		$written = array();
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$written ) {
				$written[ $name ] = $value;
				return true;
			}
		);
		Stats::flush();
		$this->assertSame( array( Stats::GENERATION_OPTION => 1 ), $written );
	}

	public function test_resolve_range(): void {
		$this->assertSame( array( '2026-09-03', '2026-10-02' ), Controller::resolve_range( '', '', '2026-10-02' ) );
		$this->assertSame( array( '2026-01-01', '2026-01-31' ), Controller::resolve_range( '2026-01-01', '2026-01-31', '2026-10-02' ) );
		$this->assertInstanceOf( \WP_Error::class, Controller::resolve_range( '2026-02-30', '2026-03-01', '2026-10-02' ) );
		$this->assertInstanceOf( \WP_Error::class, Controller::resolve_range( '2026-03-02', '2026-03-01', '2026-10-02' ) );
		$this->assertInstanceOf( \WP_Error::class, Controller::resolve_range( '2020-01-01', '2026-03-01', '2026-10-02' ) );
	}

	public function test_dashboard_bar_path_rounds_only_the_data_end(): void {
		$this->assertSame( 'M0 100V10H24V100Z', Dashboard::bar_path( 0, 10, 24, 90, 0 ) );
		$this->assertSame( 'M0 100V14Q0 10 4 10H20Q24 10 24 14V100Z', Dashboard::bar_path( 0, 10, 24, 90, 4 ) );
	}

	public function test_alert_history_delivery_text(): void {
		$this->assertSame(
			'Email: sent · Webhook: HTTP 200',
			Page::delivery(
				array(
					'email'   => true,
					'webhook' => 200,
				)
			)
		);
		$this->assertSame(
			'Webhook: cURL error 28',
			Page::delivery(
				array(
					'email'   => null,
					'webhook' => 'cURL error 28',
				)
			)
		);
	}
}
