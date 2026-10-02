<?php
/**
 * GET mailspur-email-log/v1/mails/<id>/eml – the message as .eml file (message/rfc822).
 *
 * Authenticated like every other route of the plugin (REST nonce, log capability). The REST server
 * would JSON-encode the body, so rest_pre_serve_request streams this one route's string as is.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Trace;

use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Settings;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Download {

	const ROUTE_PATTERN = '#^/mailspur-email-log/v1/mails/\d+/eml$#';

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register_routes(): void {
		register_rest_route(
			Rest::NS,
			'/mails/(?P<id>\d+)/eml',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download' ),
				'permission_callback' => array( Settings::class, 'current_user_can_view' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function download( WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$row = $this->repository->find( $id );
		if ( ! $row ) {
			return new WP_Error( 'mailspur_not_found', __( 'Log entry not found.', 'mailspur-email-log' ), array( 'status' => 404 ) );
		}

		$mime  = Eml::unpack( (string) ( $row['raw'] ?? '' ) );
		$exact = '' !== $mime;
		if ( ! $exact ) {
			$mime = Eml::reconstruct( $row );
		}

		$response = new WP_REST_Response( $mime );
		$response->header( 'Content-Type', 'message/rfc822' );
		$response->header( 'Content-Disposition', 'attachment; filename="mailspur-' . $id . '.eml"' );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Mailspur-Source', $exact ? 'exact' : 'reconstructed' );
		return $response;
	}

	/**
	 * Filter rest_pre_serve_request: sends the .eml body unencoded.
	 *
	 * @param mixed $served
	 * @param mixed $result
	 * @param mixed $request
	 * @return mixed
	 */
	public function serve( $served, $result, $request ) {
		if ( $served || ! $result instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request ) {
			return $served;
		}
		if ( 200 !== $result->get_status() || ! is_string( $result->get_data() ) || ! preg_match( self::ROUTE_PATTERN, $request->get_route() ) ) {
			return $served;
		}
		/*
		 * Not HTML output: the body is a MIME message (.eml) served as a file download –
		 * Content-Type message/rfc822, Content-Disposition attachment and X-Content-Type-Options: nosniff –
		 * so browsers never render it. Escaping would corrupt the file. Only for this route, only after the
		 * REST permission check (log viewers) and nonce passed.
		 */
		header( 'X-Content-Type-Options: nosniff' );
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download, see above.
		return true;
	}
}
