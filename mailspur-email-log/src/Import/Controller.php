<?php
/**
 * REST endpoints for the importer (administrators only):
 *
 *   GET    /mailspur-email-log/v1/import              sources found on this site + progress
 *   POST   /mailspur-email-log/v1/import/<source>     import the next batch
 *   DELETE /mailspur-email-log/v1/import/<source>     remove everything imported from <source>
 *
 * @package Mailspur
 */

namespace Mailspur\Import;

use Mailspur\Rest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Controller {

	/** @var Importer */
	private $importer;

	public function __construct( Importer $importer ) {
		$this->importer = $importer;
	}

	public function register_routes(): void {
		register_rest_route(
			Rest::NS,
			'/import',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'overview' ),
				'permission_callback' => array( $this, 'can_import' ),
			)
		);

		register_rest_route(
			Rest::NS,
			'/import/(?P<source>[a-z0-9-]+)',
			array(
				'args' => array(
					'source' => array(
						'type'     => 'string',
						'enum'     => array_keys( Importer::sources() ),
						'required' => true,
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run' ),
					'permission_callback' => array( $this, 'can_import' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'undo' ),
					'permission_callback' => array( $this, 'can_import' ),
				),
			)
		);
	}

	/** Importing reads other plugins' data and writes the log: administrators only. */
	public function can_import(): bool {
		return current_user_can( 'manage_options' );
	}

	public function overview(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'sources'          => $this->importer->overview(),
				'retention_cutoff' => Importer::retention_cutoff(),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function run( WP_REST_Request $request ) {
		$source = $this->source( $request );
		if ( $source instanceof WP_Error ) {
			return $source;
		}
		$result = $this->importer->run( $source );
		if ( null === $result ) {
			return new WP_Error( 'mailspur_import_running', __( 'This import is already running in another tab.', 'mailspur-email-log' ), array( 'status' => 409 ) );
		}
		return new WP_REST_Response( $result );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo( WP_REST_Request $request ) {
		$source = $this->source( $request );
		if ( $source instanceof WP_Error ) {
			return $source;
		}
		return new WP_REST_Response( array( 'deleted' => $this->importer->undo( $source ) ) );
	}

	/**
	 * @return Source|WP_Error
	 */
	private function source( WP_REST_Request $request ) {
		$source = Importer::source( (string) $request['source'] );
		if ( ! $source || ! $source->available() ) {
			return new WP_Error( 'mailspur_import_unknown', __( 'No log of this plugin was found on this site.', 'mailspur-email-log' ), array( 'status' => 404 ) );
		}
		return $source;
	}
}
