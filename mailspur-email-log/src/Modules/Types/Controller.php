<?php
/**
 * REST: GET /types – every email type with its health (indexes new log entries first);
 * GET /types/{id}/compare – the last email before and the first after the type's latest content change.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Controller {

	/** @var Store */
	private $store;

	/** @var Indexer */
	private $indexer;

	/** @var Repository */
	private $repository;

	public function __construct( Store $store, Indexer $indexer, ?Repository $repository = null ) {
		$this->store      = $store;
		$this->indexer    = $indexer;
		$this->repository = $repository ?? new Repository();
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
		register_rest_route(
			Rest::NS,
			'/types/(?P<id>\d+)/compare',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'compare' ),
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

	public function index(): WP_REST_Response {
		$this->indexer->run( Module::INDEX_ON_VIEW );
		$items     = Report::current( $this->store, time() );
		$templates = ( new Templates() )->for_items( $items );
		$out       = array();
		foreach ( $items as $item ) {
			$template = $templates[ $item['id'] ] ?? array();
			$out[]    = array(
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
				'lastId'    => $item['last_id'],
				'change'    => null === $item['change'] ? null : array(
					'at'      => gmdate( 'c', $item['change']['after_at'] ),
					'updates' => $item['change']['updates'],
				),
				'cron'      => $item['cron'],
				'cause'     => $item['cause'],
				'template'  => array(
					'url'  => (string) ( $template['url'] ?? '' ),
					'hint' => (string) ( $template['hint'] ?? '' ),
				),
				'probe'     => (string) ( $template['probe'] ?? '' ),
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

	/**
	 * Side-by-side data for the "Compare" view: both emails (only while both are still in the log and not
	 * anonymised) and a line diff of their text.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function compare( WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$types = $this->store->types();
		$type  = $types[ $id ] ?? null;
		$state = null === $type ? array() : (array) ( $type['extra']['content'] ?? array() );
		if ( null === $type || empty( $state['c'] ) || ! is_array( $state['c'] ) ) {
			return new WP_Error( 'mailspur_not_found', __( 'This email type has no content change.', 'mailspur-email-log' ), array( 'status' => 404 ) );
		}
		$change  = Report::change( (array) $type['extra'], Updates::all(), 0 );
		$before  = $this->mail( (int) $state['c']['before'] );
		$after   = $this->mail( (int) $state['c']['after'] );
		$payload = array(
			'id'        => $id,
			'label'     => Report::text( (array) $type['pattern'] ),
			'changedAt' => gmdate( 'c', (int) $state['c']['after_at'] ),
			'updates'   => null === $change ? array() : $change['updates'],
			'available' => null !== $before && null !== $after,
		);
		if ( null === $before || null === $after ) {
			$payload['reason'] = __( 'One of the two emails was deleted or anonymised by the retention settings, so they can no longer be compared.', 'mailspur-email-log' );
		} else {
			$payload['before'] = $before;
			$payload['after']  = $after;
			$payload['diff']   = Diff::lines(
				Content::lines( $before['message'], $before['isHtml'] ),
				Content::lines( $after['message'], $after['isHtml'] )
			);
		}
		$response = new WP_REST_Response( $payload );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * @return array{id:int,date:string,subject:string,isHtml:bool,message:string}|null Null when gone or anonymised.
	 */
	private function mail( int $id ): ?array {
		$row = $id > 0 ? $this->repository->find( $id ) : null;
		if ( null === $row || '' === (string) $row['message'] || ! empty( Rest::decode_meta( (string) ( $row['meta'] ?? '' ) )['anonymised'] ) ) {
			return null;
		}
		$message = (string) $row['message'];
		return array(
			'id'      => (int) $row['id'],
			'date'    => (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $row['created_at'] . ' UTC' ) ),
			'subject' => (string) $row['subject'],
			'isHtml'  => Content::is_html( (string) $row['content_type'], $message ),
			'message' => $message,
		);
	}
}
