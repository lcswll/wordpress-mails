<?php
/**
 * REST API used by the admin screen (outbox-mail-log/v1).
 *
 * Never exposes server file paths; attachment names only.
 *
 * @package OutboxMailLog
 */

namespace OutboxMailLog;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'outbox-mail-log/v1';

	/** @var Repository */
	private $repository;

	/** @var string|null */
	private $date_format;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register_routes(): void {
		$id_arg = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);

		register_rest_route(
			self::NS,
			'/mails',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $this->list_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array(
						'ids' => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'maxItems' => 500,
							'default'  => array(),
						),
						'all' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/mails/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/mails/(?P<id>\d+)/resend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resend' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => $id_arg,
			)
		);
	}

	public function can_view(): bool {
		return Settings::current_user_can_view();
	}

	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->repository->query(
			array(
				'page'     => (int) $request['page'],
				'per_page' => (int) $request['per_page'],
				'search'   => (string) $request['search'],
				'in_body'  => (bool) $request['in_body'],
				'status'   => (string) $request['status'],
				'orderby'  => (string) $request['orderby'],
				'order'    => (string) $request['order'],
				'after'    => (string) $request['after'],
				'before'   => (string) $request['before'],
			)
		);

		$response = new WP_REST_Response(
			array(
				'items'  => array_map( array( $this, 'summary' ), $result['items'] ),
				'total'  => $result['total'],
				'pages'  => (int) ceil( $result['total'] / max( 1, (int) $request['per_page'] ) ),
				'counts' => $result['counts'],
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ) {
		$row = $this->repository->find( (int) $request['id'] );
		if ( ! $row ) {
			return $this->not_found();
		}

		$item = $this->summary( $row );

		$type = strtolower( (string) $row['content_type'] );
		if ( '' === $type ) {
			// Unknown (e.g. delivered by a pre_wp_mail handler): fall back to sniffing.
			$type = preg_match( '/<(?:html|body|table|div|p|br|a)\b[^>]*>/i', $row['message'] ) ? 'text/html' : 'text/plain';
		}

		$item['message']      = $row['message'];
		$item['headers']      = $row['headers'];
		$item['content_type'] = $type;
		$item['is_html']      = false !== strpos( $type, 'html' );
		$item['sender']       = $row['sender'];

		$response = new WP_REST_Response( $item );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_items( WP_REST_Request $request ) {
		if ( $request['all'] ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'rest_forbidden', __( 'Only administrators can empty the log.', 'outbox-mail-log' ), array( 'status' => 403 ) );
			}
			$this->repository->delete_all();
			return new WP_REST_Response( array( 'deleted' => true ) );
		}
		return new WP_REST_Response( array( 'deleted' => $this->repository->delete( (array) $request['ids'] ) ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( WP_REST_Request $request ) {
		if ( ! $this->repository->delete( array( (int) $request['id'] ) ) ) {
			return $this->not_found();
		}
		return new WP_REST_Response( array( 'deleted' => 1 ) );
	}

	/**
	 * Sends a logged mail again. Attachments are only re-attached when the
	 * original file still exists inside the WordPress installation.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function resend( WP_REST_Request $request ) {
		$row = $this->repository->find( (int) $request['id'] );
		if ( ! $row ) {
			return $this->not_found();
		}

		$files   = array();
		$missing = array();
		foreach ( self::decode_attachments( $row['attachments'] ) as $attachment ) {
			if ( self::is_allowed_file( $attachment['path'] ) ) {
				$files[ $attachment['name'] ] = $attachment['path'];
			} else {
				$missing[] = $attachment['name'];
			}
		}

		$headers = array_filter( explode( "\n", (string) $row['headers'] ) );

		Logger::$source_override = 'outbox:resend';
		$sent                    = wp_mail( $row['recipients'], $row['subject'], $row['message'], $headers, $files );
		Logger::$source_override = '';

		return new WP_REST_Response(
			array(
				'sent'                => (bool) $sent,
				'missing_attachments' => $missing,
			)
		);
	}

	/**
	 * @param array<string,string> $row
	 * @return array<string,mixed>
	 */
	private function summary( array $row ): array {
		if ( null === $this->date_format ) {
			$this->date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}
		$timestamp = (int) strtotime( $row['created_at'] . ' UTC' );

		return array(
			'id'          => (int) $row['id'],
			'date'        => (string) wp_date( $this->date_format, $timestamp ),
			'date_iso'    => gmdate( 'c', $timestamp ),
			'status'      => Repository::status_slug( (int) $row['status'] ),
			'to'          => (string) $row['recipients'],
			'subject'     => (string) $row['subject'],
			'attachments' => array_column( self::decode_attachments( (string) $row['attachments'] ), 'name' ),
			'source'      => (string) $row['source'],
			'error'       => (string) $row['error'],
		);
	}

	/**
	 * @return array<int,array{name:string,path:string}>
	 */
	private static function decode_attachments( string $json ): array {
		if ( '' === $json ) {
			return array();
		}
		$list = json_decode( $json, true );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $item ) {
			if ( is_array( $item ) && isset( $item['name'], $item['path'] ) ) {
				$out[] = array(
					'name' => sanitize_file_name( (string) $item['name'] ),
					'path' => (string) $item['path'],
				);
			}
		}
		return $out;
	}

	private static function is_allowed_file( string $path ): bool {
		$real = realpath( $path );
		if ( ! $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return false;
		}
		$real = wp_normalize_path( $real );
		foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $root ) {
			$root = trailingslashit( wp_normalize_path( (string) realpath( $root ) ) );
			if ( '/' !== $root && 0 === strpos( $real, $root ) ) {
				return true;
			}
		}
		return false;
	}

	private function not_found(): WP_Error {
		return new WP_Error( 'outbox_not_found', __( 'Log entry not found.', 'outbox-mail-log' ), array( 'status' => 404 ) );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function list_args(): array {
		$date = array(
			'type'    => 'string',
			'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
			'default' => '',
		);
		return array(
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 200,
				'default' => 25,
			),
			'search'   => array(
				'type'      => 'string',
				'maxLength' => 200,
				'default'   => '',
			),
			'in_body'  => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'status'   => array(
				'type'    => 'string',
				'enum'    => array( 'all', 'sent', 'failed', 'pending' ),
				'default' => 'all',
			),
			'orderby'  => array(
				'type'    => 'string',
				'enum'    => array_keys( Repository::ORDER_COLUMNS ),
				'default' => 'date',
			),
			'order'    => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'desc',
			),
			'after'    => $date,
			'before'   => $date,
		);
	}
}
