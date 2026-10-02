<?php
/**
 * REST: GET /types – every email type with its health (indexes new log entries first).
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Rest;
use Mailspur\Settings;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Controller {

	/** @var Store */
	private $store;

	/** @var Indexer */
	private $indexer;

	public function __construct( Store $store, Indexer $indexer ) {
		$this->store   = $store;
		$this->indexer = $indexer;
	}

	public function register_routes(): void {
		register_rest_route(
			Rest::NS,
			'/types',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( Settings::class, 'current_user_can_view' ),
			)
		);
	}

	public function index(): WP_REST_Response {
		$this->indexer->run( Module::INDEX_ON_VIEW );
		$items = Report::current( $this->store, time() );
		$out   = array();
		foreach ( $items as $item ) {
			$out[] = array(
				'id'        => $item['id'],
				'source'    => $item['source'],
				'sender'    => Report::source_label( $item['source'] ),
				'label'     => Report::text( $item['pattern'] ),
				'pattern'   => implode( ' ', $item['pattern'] ),
				'state'     => $item['state'],
				'muted'     => $item['muted'],
				'total'     => $item['total'],
				'failed'    => $item['failed'],
				'held'      => $item['held'],
				'notes'     => $item['notes'],
				'firstSeen' => gmdate( 'c', $item['first_seen'] ),
				'lastSeen'  => gmdate( 'c', $item['last_seen'] ),
				'rhythm'    => $item['rhythm'],
				'series'    => array_map(
					static function ( array $day ): array {
						return array(
							'day'    => $day[0],
							'total'  => $day[1],
							'failed' => $day[2],
							'held'   => $day[3],
						);
					},
					$item['series']
				),
				'updates'   => $item['updates'],
				'logUrl'    => Page::log_url( $item ),
			);
		}
		$response = new WP_REST_Response(
			array(
				'summary' => Report::summary( $items ),
				'types'   => $out,
			)
		);
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}
}
