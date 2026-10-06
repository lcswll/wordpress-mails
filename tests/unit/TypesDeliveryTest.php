<?php
/**
 * Email types: statuses reported by the email provider per type – summed over the 30 days of the report, moved
 * between the day counters when a status changes after indexing (counters only, never recipients), and left to
 * the index for emails it has not read yet.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Types\Indexer;
use Mailspur\Modules\Types\Report;
use Mailspur\Modules\Types\Store;

final class TypesDeliveryTest extends TestCase {

	const TODAY = '2026-10-01';

	/** @var array<string,mixed> */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'get_plugins'    => array(),
				'get_mu_plugins' => array(),
				'wp_date'        => static function ( $format, $time ) {
					return gmdate( $format, $time );
				},
				'get_option'     => function ( $name, $fallback = false ) {
					return $this->options[ $name ] ?? $fallback;
				},
			)
		);
	}

	/** @return array<string,mixed> */
	private function type(): array {
		return array(
			'id'          => 7,
			'source'      => 'plugin:woocommerce',
			'pattern'     => array( 'Your', 'order', '#{#}' ),
			'first_seen'  => '2026-07-01 08:00:00',
			'last_seen'   => '2026-09-30 08:00:00',
			'last_status' => 1,
			'last_notes'  => 0,
			'muted'       => false,
			'extra'       => array(),
		);
	}

	/** @return array{total:int,failed:int,held:int,reported:int,bounced:int,complaint:int} */
	private function day( int $total, int $reported, int $bounced, int $complaint ): array {
		return array(
			'total'     => $total,
			'failed'    => 0,
			'held'      => 0,
			'reported'  => $reported,
			'bounced'   => $bounced,
			'complaint' => $complaint,
		);
	}

	public function test_report_sums_the_provider_statuses_of_30_days(): void {
		$days  = array(
			7 => array(
				'2026-09-30' => $this->day( 50, 40, 2, 0 ),
				'2026-09-10' => $this->day( 90, 80, 1, 1 ),
				'2026-08-01' => $this->day( 99, 99, 50, 9 ), // Outside the 30 days.
			),
		);
		$items = Report::build( array( 7 => $this->type() ), $days, self::TODAY, (int) strtotime( self::TODAY . ' 12:00:00 UTC' ) );
		$this->assertSame(
			array(
				'reported'  => 120,
				'bounced'   => 3,
				'complaint' => 1,
			),
			$items[0]['delivery']
		);

		// Day rows from before the counters existed.
		$items = Report::build(
			array( 7 => $this->type() ),
			array(
				7 => array(
					'2026-09-30' => array(
						'total'  => 5,
						'failed' => 0,
						'held'   => 0,
					),
				),
			),
			self::TODAY,
			(int) strtotime( self::TODAY . ' 12:00:00 UTC' )
		);
		$this->assertSame( 0, $items[0]['delivery']['reported'] );
	}

	public function test_a_status_change_moves_the_day_counters(): void {
		$store = new Store();
		$store->delivery_changed( 7, '2026-09-30', 'bounced', '' );
		$store->delivery_changed( 7, '2026-09-30', 'complaint', 'bounced' );
		$store->delivery_changed( 7, '2026-09-30', 'delivered', 'soft_bounce' );

		$this->assertCount( 2, $this->wpdb->prepared, 'soft bounce → delivered changes no counter' );
		$this->assertStringStartsWith( 'UPDATE %i SET reported = reported + %d, bounced = CASE WHEN bounced >= %d THEN bounced - %d ELSE 0 END + %d', $this->wpdb->prepared[0]['sql'] );
		// Table, reported +1, bounced -0 +1, complaint -0 +0, type, day.
		$this->assertSame( array( 'wp_mailspur_type_days', 1, 0, 0, 1, 0, 0, 0, 7, '2026-09-30' ), $this->wpdb->prepared[0]['args'] );
		// Bounce → complaint: bounced -1, complaint +1, still one reported email.
		$this->assertSame( array( 'wp_mailspur_type_days', 0, 1, 1, 0, 0, 0, 1, 7, '2026-09-30' ), $this->wpdb->prepared[1]['args'] );
	}

	public function test_only_indexed_emails_are_moved_by_the_hook(): void {
		$this->options[ Indexer::CURSOR ] = 100;
		$indexer                          = new Indexer( new Store() );
		$row                              = array(
			'id'         => 120,
			'created_at' => '2026-09-30 23:30:00',
			'source'     => 'plugin:woocommerce',
			'subject'    => 'Your order #1234',
		);

		$indexer->delivery_changed( 120, 'bounced', '', $row );
		$this->assertSame( array(), $this->wpdb->prepared, 'not indexed yet: the index counts the status itself' );

		$this->wpdb->results = array(
			array(
				array(
					'id'          => '7',
					'source'      => 'plugin:woocommerce',
					'pattern'     => 'Your order #{#}',
					'first_seen'  => '2026-07-01 08:00:00',
					'last_seen'   => '2026-09-30 08:00:00',
					'last_status' => '1',
					'last_notes'  => '0',
					'muted'       => '0',
					'extra'       => '',
				),
			),
		);
		$indexer->delivery_changed( 90, 'bounced', '', array( 'id' => 90 ) + $row );
		$update = end( $this->wpdb->prepared );
		$this->assertStringStartsWith( 'UPDATE %i SET reported = reported + %d', $update['sql'] );
		$this->assertSame( array( 'wp_mailspur_type_days', 1, 0, 0, 1, 0, 0, 0, 7, '2026-09-30' ), $update['args'] );

		$before = count( $this->wpdb->prepared );
		$indexer->delivery_changed( 90, 'bounced', '', array( 'source' => 'mailspur:resend' ) + $row );
		$this->assertCount( $before, $this->wpdb->prepared, 'resends are no type' );
	}
}
