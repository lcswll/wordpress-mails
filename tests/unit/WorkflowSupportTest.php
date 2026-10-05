<?php
/**
 * "Copy for support": one plain-language sentence per status (sent, failed, held by staging or brake, unknown),
 * site date/time format, full recipient address, never technical details.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Workflow\Support;
use Mailspur\Repository;

final class WorkflowSupportTest extends TestCase {

	/** @var int 2026-10-03 14:02 UTC */
	private $time;

	protected function setUp(): void {
		parent::setUp();
		$this->time = (int) strtotime( '2026-10-03 14:02:00 UTC' );
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'get_option' => static function ( $name, $fallback = false ) {
					$formats = array(
						'date_format' => 'j F',
						'time_format' => 'H:i',
					);
					return $formats[ $name ] ?? $fallback;
				},
				'wp_date'    => static function ( $format, $timestamp = null ) {
					return gmdate( $format, $timestamp ?? time() );
				},
			)
		);
	}

	public function test_sent_names_subject_recipient_date_time_and_spam_folder(): void {
		$text = Support::sentence( Repository::STATUS_SENT, 'Your invoice #1042', 'anna@example.com', $this->time );
		$this->assertSame( 'The email “Your invoice #1042” was sent to anna@example.com on 3 October at 14:02 and accepted by the mail server. Please also check your spam folder.', $text );
	}

	public function test_failed_promises_to_send_again(): void {
		$text = Support::sentence( Repository::STATUS_FAILED, 'Your invoice', 'anna@example.com', $this->time );
		$this->assertStringContainsString( 'could not be delivered', $text );
		$this->assertStringContainsString( 'We will send it again.', $text );
		$this->assertStringNotContainsString( 'spam', $text );
	}

	public function test_held_variants_for_staging_brake_and_released(): void {
		$staging  = Support::sentence( Repository::STATUS_HELD, 'Welcome', 'anna@example.com', $this->time, 'staging' );
		$brake    = Support::sentence( Repository::STATUS_HELD, 'Welcome', 'anna@example.com', $this->time, 'brake' );
		$discard  = Support::sentence( Repository::STATUS_HELD, 'Welcome', 'anna@example.com', $this->time, 'brake_discarded' );
		$released = Support::sentence( Repository::STATUS_HELD, 'Welcome', 'anna@example.com', $this->time, 'brake_released' );

		$this->assertStringContainsString( 'paused on our side', $staging );
		$this->assertStringContainsString( 'held back by a safety check', $brake );
		$this->assertSame( $brake, $discard );
		$this->assertStringContainsString( 'sent afterwards', $released );
		foreach ( array( $staging, $brake, $released ) as $text ) {
			$this->assertStringContainsString( 'anna@example.com', $text );
			$this->assertStringContainsString( '3 October at 14:02', $text );
		}
	}

	public function test_unknown_status_does_not_claim_delivery(): void {
		$text = Support::sentence( Repository::STATUS_PENDING, 'Welcome', 'anna@example.com', $this->time );
		$this->assertStringContainsString( 'cannot confirm whether it was delivered', $text );
		$this->assertStringNotContainsString( 'accepted', $text );
	}

	public function test_recipients_become_bare_addresses_and_empty_subject_is_named(): void {
		$text = Support::sentence( Repository::STATUS_SENT, "  \n ", 'Anna Example <anna@example.com>, ben@example.org', $this->time );
		$this->assertStringContainsString( '“(no subject)”', $text );
		$this->assertStringContainsString( 'sent to anna@example.com, ben@example.org on', $text );
		$this->assertStringNotContainsString( 'Anna Example', $text );
		$this->assertSame( 'undisclosed-recipients', Support::addresses( ' undisclosed-recipients ' ) );
	}

	public function test_rest_item_adds_the_sentence_without_technical_details(): void {
		$row  = array(
			'status'     => '2',
			'subject'    => 'Order #7',
			'recipients' => 'anna@example.com',
			'created_at' => '2026-10-03 14:02:00',
			'error'      => 'SMTP connect() failed. smtp.example.net:587',
			'meta'       => '{"trace":{"smtp":{"host":"smtp.example.net"}}}',
		);
		$item = Support::rest_item( array( 'id' => 7 ), $row );

		$this->assertStringContainsString( '“Order #7” to anna@example.com on 3 October at 14:02 could not be delivered', $item['support'] );
		$this->assertStringNotContainsString( 'smtp', strtolower( $item['support'] ) );

		$held = Support::rest_item(
			array(),
			array_merge(
				$row,
				array(
					'status' => '3',
					'meta'   => '{"delivery":{"held":"brake"}}',
				)
			)
		);
		$this->assertStringContainsString( 'safety check', $held['support'] );
		$this->assertSame( 'unchanged', Support::rest_item( 'unchanged', $row ) );
	}
}
