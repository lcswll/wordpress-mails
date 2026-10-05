<?php
/**
 * Delivery module: problem recipients – which errors count as hard failures, the threshold, deduplication,
 * expiry, "Allow again", holding further mails and the personal data export / erasure.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mailspur\Logger;
use Mailspur\Modules\Delivery\Problems;
use Mailspur\Modules\Delivery\Staging;
use Mailspur\Repository;

final class DeliveryProblemsTest extends TestCase {

	const NOW = 1790001000;

	/** @var array<string,mixed> */
	private $options = array();

	/** @var array<string,mixed> */
	private $transients = array();

	/** @var int */
	private $now = self::NOW;

	/** @var Problems */
	private $problems;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
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
		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'get_date_from_gmt' )->returnArg();

		Problems::reset_request();
		Staging::reset();
		Logger::$source_override            = '';
		$this->options['mailspur_settings'] = array( 'retention_days' => 30 );
		$this->problems                     = new Problems(
			function (): int {
				return $this->now;
			}
		);
	}

	protected function tearDown(): void {
		Problems::reset_request();
		Logger::$source_override = '';
		parent::tearDown();
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function errors(): array {
		return array(
			'550 user unknown'    => array( 'SMTP Error: The following recipients failed: anna@example.com: 550 5.1.1 <anna@example.com>: Recipient address rejected: User unknown', Problems::REJECTED ),
			'551 mailbox'         => array( '551 5.1.6 mailbox unavailable', Problems::REJECTED ),
			'553 mailbox name'    => array( '553 5.1.3 mailbox name not allowed, recipient invalid', Problems::REJECTED ),
			'no such user'        => array( 'No such user here', Problems::REJECTED ),
			'domain not found'    => array( '550 5.1.2 Host or domain name not found. Name service error', Problems::NO_MX ),
			'null mx'             => array( '556 5.1.10 Recipient address has null MX', Problems::NULL_MX ),
			'temporary 450'       => array( '450 4.2.0 mailbox unavailable, try again later', '' ),
			'4.x.x enhanced code' => array( 'The following recipients failed: anna@example.com: 550 4.1.1 user unknown', '' ),
			'sender rejected 553' => array( '553 5.7.1 Sender address rejected: not owned by user', '' ),
			'relay denied'        => array( '550 5.7.1 Relaying denied', '' ),
			'mailbox full'        => array( '552 5.2.2 Mailbox full', '' ),
			'login failed'        => array( 'SMTP Error: Could not authenticate.', '' ),
			'connection'          => array( 'SMTP connect() failed.', '' ),
			'empty'               => array( '', '' ),
		);
	}

	/**
	 * @dataProvider errors
	 */
	public function test_classify( string $error, string $expected ): void {
		$this->assertSame( $expected, Problems::classify( $error ) );
	}

	public function test_failed_addresses(): void {
		$this->assertSame( array( 'ben@example.com' ), Problems::failed_addresses( 'recipients failed: ben@example.com: 550 user unknown', 'Anna <anna@example.com>, ben@example.com' ) );
		$this->assertSame( array( 'anna@example.com' ), Problems::failed_addresses( '550 user unknown', 'Anna <Anna@Example.com>' ), 'the only recipient' );
		$this->assertSame( array(), Problems::failed_addresses( '550 user unknown', 'anna@example.com, ben@example.com' ), 'ambiguous' );
		$this->assertSame( array(), Problems::failed_addresses( 'eve@evil.test: 550 user unknown', 'anna@example.com, ben@example.com' ), 'never an address outside the mail' );
	}

	public function test_threshold_and_deduplication(): void {
		$this->problems->record( array( 'anna@example.com' ), Problems::REJECTED, '1' );
		$this->assertFalse( Problems::is_problem( 'anna@example.com' ), 'one failure is not enough' );
		$this->assertSame( array(), Problems::problems() );

		$this->problems->record( array( 'Anna@Example.com' ), Problems::REJECTED, '1' );
		$this->assertFalse( Problems::is_problem( 'anna@example.com' ), 'the same entry never counts twice' );

		$this->now += 60;
		$this->problems->record( array( 'anna@example.com', 'not-an-address' ), Problems::BOUNCE, '2' );
		$this->assertTrue( Problems::is_problem( 'ANNA@example.com' ) );
		$this->assertSame(
			array(
				array(
					'email' => 'anna@example.com',
					'count' => 2,
					'first' => self::NOW,
					'last'  => self::NOW + 60,
					'why'   => Problems::BOUNCE,
				),
			),
			Problems::problems()
		);
		$this->assertCount( 1, Problems::all(), 'invalid addresses are not stored' );
	}

	public function test_logged_failed_mail_counts(): void {
		$row = array(
			'status'     => Repository::STATUS_FAILED,
			'recipients' => 'anna@example.com',
			'error'      => '550 5.1.1 user unknown',
		);
		$this->problems->logged( 5, $row );
		$this->problems->logged( 6, $row );
		$this->problems->logged( 7, array( 'status' => Repository::STATUS_SENT ) + $row );
		$this->assertSame( array( '5', '6' ), Problems::all()['anna@example.com']['ids'] );
	}

	public function test_logged_uses_cached_mx_state_only(): void {
		$this->transients[ 'mailspur_notes_mx_' . md5( 'nomail.test' ) ] = 'null';
		$this->problems->logged(
			8,
			array(
				'status'     => Repository::STATUS_FAILED,
				'recipients' => 'anna@nomail.test, ben@example.com',
				'error'      => 'Could not instantiate mail function.',
			)
		);
		$all = Problems::all();
		$this->assertSame( array( 'anna@nomail.test' ), array_keys( $all ) );
		$this->assertSame( Problems::NULL_MX, $all['anna@nomail.test']['why'] );
	}

	public function test_allow_again_and_expiry(): void {
		$this->problems->record( array( 'anna@example.com', 'ben@example.com' ), Problems::REJECTED, '1' );
		$this->assertTrue( Problems::allow( 'Anna@example.com' ) );
		$this->assertFalse( Problems::allow( 'anna@example.com' ) );
		$this->assertSame( array( 'ben@example.com' ), array_keys( Problems::all() ) );

		$this->now += 31 * DAY_IN_SECONDS;
		$this->problems->record( array( 'cara@example.com' ), Problems::REJECTED, '2' );
		$this->assertSame( 1, $this->problems->expire(), 'older than the retention period of the log' );
		$this->assertSame( array( 'cara@example.com' ), array_keys( Problems::all() ) );

		Problems::allow( 'cara@example.com' );
		$this->assertArrayNotHasKey( Problems::OPTION, $this->options, 'empty list removes the option' );
	}

	/** Makes anna@example.com a problem recipient. */
	private function anna_is_a_problem(): void {
		$this->problems->record( array( 'anna@example.com' ), Problems::REJECTED, '1' );
		$this->problems->record( array( 'anna@example.com' ), Problems::REJECTED, '2' );
	}

	/** @param array<string,mixed> $atts */
	private function send( array $atts ): ?bool {
		$this->problems->meta( array(), 'capture' );
		return $this->problems->hold( null, $atts );
	}

	public function test_hold_is_off_by_default(): void {
		$this->anna_is_a_problem();
		$this->assertNull( $this->send( array( 'to' => 'anna@example.com' ) ) );
	}

	public function test_hold_when_every_recipient_is_a_problem(): void {
		$this->anna_is_a_problem();
		$this->options['mailspur_settings']['problem_hold'] = true;

		$this->assertNull( $this->send( array( 'to' => array( 'anna@example.com', 'ben@example.com' ) ) ), 'one good recipient: sent' );
		$this->assertTrue( $this->send( array( 'to' => 'Anna <anna@example.com>' ) ) );

		$data = $this->problems->finalize( array( 'meta' => array() ) );
		$this->assertSame( Repository::STATUS_HELD, $data['status'] );
		$this->assertSame( Problems::HELD, $data['meta']['delivery']['held'] );
		$this->assertArrayNotHasKey( 'status', $this->problems->finalize( array() ), 'consumed' );
	}

	public function test_hold_exceptions(): void {
		$this->anna_is_a_problem();
		$this->options['mailspur_settings']['problem_hold'] = true;

		$this->problems->password_reset( 'message' );
		$this->assertNull( $this->send( array( 'to' => 'anna@example.com' ) ), 'password reset' );

		Logger::$source_override = 'mailspur:alert';
		$this->assertNull( $this->send( array( 'to' => 'anna@example.com' ) ), 'own mails' );
		Logger::$source_override = '';

		Staging::$release = 3;
		$this->assertNull( $this->send( array( 'to' => 'anna@example.com' ) ), '"Send now"' );
		Staging::$release = 0;

		$this->assertNull( $this->problems->hold( null, array( 'to' => 'anna@example.com' ) ), 'not logged' );
		$this->assertTrue( $this->send( array( 'to' => 'anna@example.com' ) ) );
		$this->assertFalse( $this->problems->hold( false, array( 'to' => 'anna@example.com' ) ), 'already handled' );

		Filters\expectApplied( 'mailspur_brake_exempt' )->once()->andReturn( true );
		$this->assertNull( $this->send( array( 'to' => 'anna@example.com' ) ), 'mailspur_brake_exempt' );
	}

	public function test_detail_marks_problem_recipients(): void {
		$this->anna_is_a_problem();
		$item = $this->problems->rest_item(
			array( 'id' => 9 ),
			array(
				'id'         => 9,
				'recipients' => 'anna@example.com, ben@example.com',
			)
		);
		$this->assertSame( array( 'anna@example.com' ), $item['problem_recipients'] );
	}

	public function test_detail_counts_domain_without_mail_server_once(): void {
		$this->transients[ 'mailspur_notes_mx_' . md5( 'nomail.test' ) ] = 'none';
		$row = array(
			'id'         => 11,
			'status'     => Repository::STATUS_SENT,
			'recipients' => 'anna@nomail.test',
		);
		$this->problems->rest_item( array(), $row );
		$this->problems->rest_item( array(), $row );
		$held = array(
			'id'     => 12,
			'status' => Repository::STATUS_HELD,
		);
		$this->problems->rest_item( array(), $held + $row );
		$this->assertSame( array( '11' ), Problems::all()['anna@nomail.test']['ids'], 'reopened entry and held mails do not count' );
		$this->assertSame( Problems::NO_MX, Problems::all()['anna@nomail.test']['why'] );
	}

	public function test_privacy_export_and_erase(): void {
		$this->anna_is_a_problem();
		$exporters = $this->problems->add_exporter( array() );
		$erasers   = $this->problems->add_eraser( array() );
		$this->assertArrayHasKey( 'mailspur-problem-recipients', $exporters );
		$this->assertArrayHasKey( 'mailspur-problem-recipients', $erasers );

		$export = $this->problems->export( 'Anna@example.com' );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$values = array_column( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'anna@example.com', $values['Recipient'] );
		$this->assertSame( '2', $values['Hard failures'] );
		$this->assertSame( array(), $this->problems->export( 'ben@example.com' )['data'] );

		$this->assertTrue( $this->problems->erase( 'anna@example.com' )['items_removed'] );
		$this->assertFalse( $this->problems->erase( 'anna@example.com' )['items_removed'] );
		$this->assertSame( array(), Problems::all() );
	}
}
