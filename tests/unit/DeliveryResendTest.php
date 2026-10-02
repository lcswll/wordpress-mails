<?php
/**
 * Resend to another address (Rest::resend() "to" parameter) and the release route.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Logger;
use Mailspur\Modules\Delivery\Controller;
use Mailspur\Modules\Delivery\Staging;
use Mailspur\Repository;
use Mailspur\Rest;
use WP_Error;
use WP_REST_Request;

require_once dirname( __DIR__ ) . '/stubs/delivery-rest.php';

final class DeliveryResendTest extends TestCase {

	/** @var array<int,array<int,mixed>> */
	private $sent = array();

	/** @var bool */
	private $admin = true;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Staging::reset();
		$this->sent  = array();
		$this->admin = true;
		Functions\when( 'current_user_can' )->alias(
			function (): bool {
				return $this->admin;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( ...$args ): bool {
				$this->sent[] = array_merge( $args, array( Logger::$source_override, Staging::$release ) );
				return true;
			}
		);
	}

	/**
	 * @param array<string,mixed> $params
	 * @return mixed
	 */
	private function resend( array $params ) {
		$this->wpdb->results[] = array(
			'id'          => '7',
			'status'      => '1',
			'recipients'  => 'anna@example.com',
			'subject'     => 'Invoice',
			'message'     => 'Hello',
			'headers'     => "From: Shop <shop@example.com>\nCc: boss@example.com\nbcc : audit@example.com",
			'attachments' => '',
		);
		return ( new Rest( new Repository() ) )->resend( new WP_REST_Request( 'POST', '/', $params + array( 'id' => 7 ) ) );
	}

	public function test_without_to_the_original_mail_is_sent_unchanged(): void {
		$response = $this->resend( array() );
		$this->assertSame(
			array(
				'sent'                => true,
				'missing_attachments' => array(),
			),
			$response->get_data()
		);
		$this->assertSame( 'anna@example.com', $this->sent[0][0] );
		$this->assertSame( array( 'From: Shop <shop@example.com>', 'Cc: boss@example.com', 'bcc : audit@example.com' ), $this->sent[0][3] );
		$this->assertSame( 'mailspur:resend', $this->sent[0][5] );
	}

	public function test_to_sends_only_to_the_new_addresses(): void {
		$response = $this->resend( array( 'to' => array( 'qa@example.net', 'dev@example.net' ) ) );
		$this->assertSame( array( 'qa@example.net', 'dev@example.net' ), $response->get_data()['to'] );
		$this->assertSame( array( 'qa@example.net', 'dev@example.net' ), $this->sent[0][0] );
		$this->assertSame( array( 'From: Shop <shop@example.com>' ), $this->sent[0][3], 'Cc/Bcc of the original are dropped' );
		$this->assertSame( 'Invoice', $this->sent[0][1] );
	}

	public function test_to_requires_an_administrator(): void {
		$this->admin = false;
		$response    = ( new Rest( new Repository() ) )->resend(
			new WP_REST_Request(
				'POST',
				'/',
				array(
					'id' => 7,
					'to' => array( 'x@example.net' ),
				)
			)
		);
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'Only administrators can send emails to other addresses.', $response->get_error_message() );
		$this->assertSame( array(), $this->sent );
	}

	public function test_parse_recipients(): void {
		$this->assertSame( array( 'a@example.com', 'b@example.com' ), Rest::parse_recipients( ' a@example.com, b@example.com;A@example.com,' ) );
		$this->assertSame( array( 'a@example.com' ), Rest::parse_recipients( array( 'a@example.com', '' ) ) );

		foreach ( array( '', 'nope', array( 'a@example.com', 'b@' ), 42, array( array( 'a@example.com' ) ), implode( ',', array_map( static fn( int $i ): string => "u{$i}@example.com", range( 1, 11 ) ) ) ) as $bad ) {
			$this->assertInstanceOf( WP_Error::class, Rest::parse_recipients( $bad ), (string) wp_json_encode( $bad ) );
		}
	}

	public function test_release_only_for_held_mails(): void {
		$controller = new Controller( new Repository() );

		$this->wpdb->results[] = null;
		$missing               = $controller->release( new WP_REST_Request( 'POST', '/', array( 'id' => 3 ) ) );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'Log entry not found.', $missing->get_error_message() );

		$this->wpdb->results[] = array(
			'id'     => '3',
			'status' => (string) Repository::STATUS_SENT,
		);
		$sent                  = $controller->release( new WP_REST_Request( 'POST', '/', array( 'id' => 3 ) ) );
		$this->assertInstanceOf( WP_Error::class, $sent );
		$this->assertSame( 'Only held emails can be released.', $sent->get_error_message() );
	}

	public function test_release_resends_with_staging_bypassed(): void {
		$seen = null;
		Functions\when( 'rest_do_request' )->alias(
			static function () use ( &$seen ) {
				$seen = Staging::$release;
				return new \WP_REST_Response( array( 'sent' => true ) );
			}
		);
		Functions\when( 'rest_ensure_response' )->returnArg();

		$this->wpdb->results[] = array(
			'id'     => '3',
			'status' => (string) Repository::STATUS_HELD,
		);
		$response              = ( new Controller( new Repository() ) )->release( new WP_REST_Request( 'POST', '/', array( 'id' => 3 ) ) );

		$this->assertSame( array( 'sent' => true ), $response->get_data() );
		$this->assertSame( 3, $seen );
		$this->assertSame( 0, Staging::$release, 'bypass ends after the send' );
	}
}
