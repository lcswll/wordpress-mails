<?php
/**
 * Answers: the questions people come with, answered in plain language on the "Overview" tab – did my email
 * arrive, is everything running, is an email missing (or still due today), why did an email fail – plus the
 * health sentence on top of the dashboard widget.
 *
 * Reads what the other modules already know (log, email types, delivery status, staging mode, brake, sender
 * check, error explanations); nothing runs while an email is sent.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Answers;

use Mailspur\Repository;
use Mailspur\Rest;

defined( 'ABSPATH' ) || exit;

final class Module implements \Mailspur\Module {

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		$facts = new Facts( $this->repository );
		$page  = new Page( $facts );

		add_filter( 'mailspur_admin_tabs', array( $page, 'tabs' ), 20 );
		add_action( 'mailspur_render_tab_' . Page::TAB, array( $page, 'render' ) );
		add_action( 'mailspur_admin_enqueue', array( $page, 'enqueue' ), 10, 2 );
		add_action( 'mailspur_dashboard_widget_top', array( $page, 'dashboard_line' ) );
		add_action( 'admin_enqueue_scripts', array( $page, 'dashboard_style' ) );
		add_action(
			'rest_api_init',
			static function () use ( $facts ): void {
				( new Controller( $facts ) )->register_routes();
			}
		);
		// Counts are cached briefly; deleting or importing entries through the REST API refreshes them.
		add_filter( 'rest_request_after_callbacks', array( self::class, 'flush_after_change' ), 10, 3 );
	}

	/**
	 * @param mixed $response Response.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request.
	 * @return mixed Unchanged response.
	 */
	public static function flush_after_change( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInMiddle -- hook signature.
		if ( $request instanceof \WP_REST_Request && 'GET' !== $request->get_method() ) {
			$route = $request->get_route();
			if ( 0 === strpos( $route, '/' . Rest::NS . '/mails' ) || 0 === strpos( $route, '/' . Rest::NS . '/import' ) ) {
				Facts::flush();
			}
		}
		return $response;
	}
}
