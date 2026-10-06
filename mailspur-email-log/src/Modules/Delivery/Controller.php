<?php
/**
 * REST routes of the delivery module (administrators only):
 *   GET  /delivery/check         cached sender check result (or null)
 *   POST /delivery/check         run the sender check (DNS)
 *   POST /mails/{id}/release     send a held mail now, bypassing staging mode once
 *   POST /delivery/hint          dismiss the "enable staging mode" suggestion
 *   GET  /delivery/brake         emergency brake status
 *   POST /delivery/brake/release send the next batch of mails held by the emergency brake
 *   POST /delivery/brake/discard discard all mails held by the emergency brake
 *   POST /delivery/brake/reset   end the incident ("this is fine"), held mails stay held
 *   DELETE /delivery/problems    "Allow again": forget a problem recipient (address in the body)
 *
 * Public (secret in the URL, see Feedback::authorize()):
 *   POST /delivery/webhook/{provider}/{key}  delivery status reported by the email provider
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Delivery;

use Mailspur\Repository;
use Mailspur\Rest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Controller {

	const HINT_OPTION = 'mailspur_delivery_hint_dismissed';

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register_routes(): void {
		$selector = array(
			'type'      => 'string',
			'pattern'   => '^[A-Za-z0-9._-]{0,63}$',
			'default'   => '',
			'maxLength' => 63,
		);

		register_rest_route(
			Rest::NS,
			'/delivery/check',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'cached_check' ),
					'permission_callback' => array( $this, 'is_admin' ),
					'args'                => array( 'selector' => $selector ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_check' ),
					'permission_callback' => array( $this, 'is_admin' ),
					'args'                => array(
						'selector' => $selector,
						'force'    => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			Rest::NS,
			'/mails/(?P<id>\d+)/release',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'release' ),
				'permission_callback' => array( $this, 'is_admin' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			Rest::NS,
			'/delivery/brake',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'brake_status' ),
				'permission_callback' => array( $this, 'is_admin' ),
			)
		);
		register_rest_route(
			Rest::NS,
			'/delivery/brake/(?P<action>release|discard|reset)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'brake_action' ),
				'permission_callback' => array( $this, 'is_admin' ),
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'release', 'discard', 'reset' ),
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			Rest::NS,
			'/delivery/problems',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'allow_recipient' ),
				'permission_callback' => array( $this, 'is_admin' ),
				'args'                => array(
					'email' => array(
						'type'      => 'string',
						'maxLength' => 254,
						'required'  => true,
					),
				),
			)
		);

		$feedback = new Feedback();
		register_rest_route(
			Rest::NS,
			'/delivery/webhook/(?P<provider>' . implode( '|', Feedback::PROVIDERS ) . ')/(?P<key>[A-Za-z0-9]{32})',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $feedback, 'receive' ),
				'permission_callback' => array( $feedback, 'authorize' ),
				'args'                => array(
					'provider' => array(
						'type'     => 'string',
						'enum'     => Feedback::PROVIDERS,
						'required' => true,
					),
					'key'      => array(
						'type'     => 'string',
						'pattern'  => '^[A-Za-z0-9]{32}$',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			Rest::NS,
			'/delivery/hint',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_hint' ),
				'permission_callback' => array( $this, 'is_admin' ),
			)
		);
	}

	public function is_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	public function cached_check( WP_REST_Request $request ): WP_REST_Response {
		$check = new SenderCheck();
		$data  = SenderCheck::cached( $check->domains(), (string) $request['selector'] );
		return self::no_store( new WP_REST_Response( $data ) );
	}

	public function run_check( WP_REST_Request $request ): WP_REST_Response {
		$check = new SenderCheck();
		return self::no_store( new WP_REST_Response( $check->run( $check->domains(), (string) $request['selector'], (bool) $request['force'] ) ) );
	}

	/**
	 * Sends a held mail to its original recipients (via the core resend route, so attachments are checked
	 * the same way) with staging mode bypassed for this one send. Logged as a new entry.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function release( WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$row = $this->repository->find( $id );
		if ( ! $row ) {
			return new WP_Error( 'mailspur_not_found', __( 'Log entry not found.', 'mailspur-email-log' ), array( 'status' => 404 ) );
		}
		if ( Repository::STATUS_HELD !== (int) $row['status'] ) {
			return new WP_Error( 'mailspur_not_held', __( 'Only held emails can be released.', 'mailspur-email-log' ), array( 'status' => 409 ) );
		}

		Staging::$release = $id;
		try {
			$response = rest_do_request( new WP_REST_Request( 'POST', '/' . Rest::NS . '/mails/' . $id . '/resend' ) );
		} finally {
			Staging::$release = 0;
		}
		Brake::released( $row );
		/**
		 * A held email was sent on its own ("Send now"), e.g. so the daily digest does not list it again.
		 *
		 * @param array<string,mixed> $row The held log row.
		 */
		do_action( 'mailspur_held_released', $row );
		return rest_ensure_response( $response );
	}

	public function brake_status(): WP_REST_Response {
		return self::no_store( new WP_REST_Response( ( new Brake() )->status() ) );
	}

	public function brake_action( WP_REST_Request $request ): WP_REST_Response {
		$brake = new Brake();
		switch ( (string) $request['action'] ) {
			case 'release':
				$data = $brake->release_batch();
				break;
			case 'discard':
				$data = array( 'discarded' => $brake->discard() );
				break;
			default:
				$brake->reset();
				$data = array( 'reset' => true );
		}
		return self::no_store( new WP_REST_Response( $data ) );
	}

	public function allow_recipient( WP_REST_Request $request ): WP_REST_Response {
		return self::no_store( new WP_REST_Response( array( 'allowed' => Problems::allow( (string) $request['email'] ) ) ) );
	}

	public function dismiss_hint(): WP_REST_Response {
		update_option( self::HINT_OPTION, 1, false );
		return new WP_REST_Response( array( 'dismissed' => true ) );
	}

	private static function no_store( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
