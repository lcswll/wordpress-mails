<?php
/**
 * REST: POST /answers/arrived – "Did my email arrive?" for an email address or an order number. POST, so the
 * address never ends up in a URL or a server log.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Answers;

use Mailspur\Rest;
use Mailspur\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Controller {

	/** @var Facts */
	private $facts;

	public function __construct( Facts $facts ) {
		$this->facts = $facts;
	}

	public function register_routes(): void {
		register_rest_route(
			Rest::NS,
			'/answers/arrived',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'arrived' ),
				'permission_callback' => array( Settings::class, 'current_user_can_view' ),
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						'maxLength'         => 200,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public function arrived( WP_REST_Request $request ): WP_REST_Response {
		$answer   = Sentences::arrived( $this->facts->lookup( (string) $request['q'] ), $this->facts->now() );
		$response = new WP_REST_Response( $answer );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
