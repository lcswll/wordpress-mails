<?php
/**
 * REST API used by the admin screen (mailspur-email-log/v1).
 *
 * Never exposes server file paths; attachment names only.
 *
 * @package Mailspur
 */

namespace Mailspur;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'mailspur-email-log/v1';

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
					'args'                => self::list_args(),
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
				'args'                => $id_arg + array(
					// Optional other recipients (array or comma list, max. 10). Administrators only.
					'to' => array(
						'description'       => 'Send to these addresses instead of the original recipients.',
						'validate_callback' => static function ( $value ) {
							$parsed = self::parse_recipients( $value );
							return is_wp_error( $parsed ) ? $parsed : true;
						},
						'sanitize_callback' => static function ( $value ) {
							return self::parse_recipients( $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Validated recipient list of the resend "to" parameter.
	 *
	 * @param mixed $value Array of addresses or a comma/semicolon-separated string.
	 * @return array<int,string>|WP_Error
	 */
	public static function parse_recipients( $value ) {
		if ( is_string( $value ) ) {
			$value = (array) preg_split( '/[,;]/', $value );
		}
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Enter one or more email addresses.', 'mailspur-email-log' ), array( 'status' => 400 ) );
		}
		$out = array();
		foreach ( $value as $address ) {
			$address = is_scalar( $address ) ? trim( (string) $address ) : '';
			if ( '' === $address ) {
				continue;
			}
			if ( ! is_email( $address ) ) {
				return new WP_Error(
					'rest_invalid_param',
					/* translators: %s: invalid email address */
					sprintf( __( 'Invalid email address: %s', 'mailspur-email-log' ), $address ),
					array( 'status' => 400 )
				);
			}
			if ( ! isset( $out[ strtolower( $address ) ] ) ) {
				$out[ strtolower( $address ) ] = $address;
			}
		}
		if ( ! $out || count( $out ) > 10 ) {
			return new WP_Error( 'rest_invalid_param', __( 'Enter between 1 and 10 email addresses.', 'mailspur-email-log' ), array( 'status' => 400 ) );
		}
		return array_values( $out );
	}

	public function can_view(): bool {
		return Settings::current_user_can_view();
	}

	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->repository->query(
			array(
				'page'        => (int) $request['page'],
				'per_page'    => (int) $request['per_page'],
				'search'      => (string) $request['search'],
				'in_body'     => (bool) $request['in_body'],
				'status'      => (string) $request['status'],
				'orderby'     => (string) $request['orderby'],
				'order'       => (string) $request['order'],
				'after'       => (string) $request['after'],
				'before'      => (string) $request['before'],
				'source'      => (string) $request['source'],
				'format'      => (string) $request['format'],
				'attachments' => (bool) $request['attachments'],
				'notes'       => (bool) $request['notes'],
				'delivery'    => (string) $request['delivery'],
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
		$item['meta']         = self::decode_meta( (string) ( $row['meta'] ?? '' ) );

		/**
		 * Detail payload of one log entry (dialog). Modules add their fields here.
		 *
		 * @param array<string,mixed>  $item
		 * @param array<string,string> $row  Raw database row.
		 */
		$item = (array) apply_filters( 'mailspur_rest_item', $item, $row );

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
				return new WP_Error( 'rest_forbidden', __( 'Only administrators can empty the log.', 'mailspur-email-log' ), array( 'status' => 403 ) );
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
		$to = $request->get_param( 'to' );
		if ( null !== $to && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Only administrators can send emails to other addresses.', 'mailspur-email-log' ), array( 'status' => 403 ) );
		}

		$row = $this->repository->find( (int) $request['id'] );
		if ( ! $row ) {
			return $this->not_found();
		}
		// Anonymised entries have no content left – resending would send an empty mail.
		if ( ! empty( self::decode_meta( (string) ( $row['meta'] ?? '' ) )['anonymised'] ) ) {
			return new WP_Error( 'mailspur_anonymised', __( 'This entry was anonymised; its content can no longer be sent.', 'mailspur-email-log' ), array( 'status' => 409 ) );
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

		$headers    = array_filter( explode( "\n", (string) $row['headers'] ) );
		$recipients = $row['recipients'];
		if ( is_array( $to ) ) {
			// Other recipients only: the original Cc/Bcc must not get a copy.
			$recipients = $to;
			$headers    = array_filter(
				$headers,
				static function ( string $header ): bool {
					return ! preg_match( '/^\s*b?cc\s*:/i', $header );
				}
			);
		}

		Logger::$source_override = 'mailspur:resend';
		$sent                    = wp_mail( $recipients, $row['subject'], $row['message'], array_values( $headers ), $files );
		Logger::$source_override = '';

		$result = array(
			'sent'                => (bool) $sent,
			'missing_attachments' => $missing,
		);
		if ( is_array( $to ) ) {
			$result['to'] = $to;
		}
		return new WP_REST_Response( $result );
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

		$item = array(
			'id'          => (int) $row['id'],
			'date'        => (string) wp_date( $this->date_format, $timestamp ),
			'date_iso'    => gmdate( 'c', $timestamp ),
			'status'      => Repository::status_slug( (int) $row['status'] ),
			'to'          => (string) $row['recipients'],
			'subject'     => (string) $row['subject'],
			'attachments' => array_column( self::decode_attachments( (string) $row['attachments'] ), 'name' ),
			'source'      => (string) $row['source'],
			'error'       => (string) $row['error'],
			'notes'       => (int) ( $row['notes'] ?? 0 ),
			'size'        => (int) ( $row['size'] ?? 0 ),
		);

		/**
		 * List payload of one log entry. Keep it small: it is sent for every row of a page.
		 *
		 * @param array<string,mixed>  $item
		 * @param array<string,string> $row  Raw database row (list columns only).
		 */
		return (array) apply_filters( 'mailspur_rest_summary', $item, $row );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function decode_meta( string $json ): array {
		$meta = '' === $json ? array() : json_decode( $json, true );
		return is_array( $meta ) ? $meta : array();
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
		return new WP_Error( 'mailspur_not_found', __( 'Log entry not found.', 'mailspur-email-log' ), array( 'status' => 404 ) );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function list_args(): array {
		$date = array(
			'type'    => 'string',
			'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
			'default' => '',
		);
		$flag = array(
			'type'    => 'boolean',
			'default' => false,
		);
		return array(
			'page'        => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'per_page'    => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 200,
				'default' => 25,
			),
			'search'      => array(
				'type'      => 'string',
				'maxLength' => 200,
				'default'   => '',
			),
			'in_body'     => $flag,
			'status'      => array(
				'type'    => 'string',
				'enum'    => array( 'all', 'sent', 'failed', 'pending', 'held' ),
				'default' => 'all',
			),
			'orderby'     => array(
				'type'    => 'string',
				'enum'    => array_keys( Repository::ORDER_COLUMNS ),
				'default' => 'date',
			),
			'order'       => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'desc',
			),
			'after'       => $date,
			'before'      => $date,
			// Exact "source" value, e.g. "plugin:woocommerce" (indexed).
			'source'      => array(
				'type'      => 'string',
				'maxLength' => 100,
				'default'   => '',
			),
			'format'      => array(
				'type'    => 'string',
				'enum'    => array_merge( array( '' ), Repository::FORMATS ),
				'default' => '',
			),
			'attachments' => $flag,
			'notes'       => $flag,
			// Delivery status reported by the email provider (indexed).
			'delivery'    => array(
				'type'    => 'string',
				'enum'    => array_merge( array( '' ), array_values( Repository::DELIVERY ) ),
				'default' => '',
			),
		);
	}
}
