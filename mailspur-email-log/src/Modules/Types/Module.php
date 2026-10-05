<?php
/**
 * Email types ("Mail Map"): an automatic inventory of every kind of email the site sends, with health per type
 * and an alert when a type that is sent regularly stops.
 *
 * Nothing runs while an email is sent: the log is read afterwards (hourly cron, or when the tab is opened).
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Cleanup;
use Mailspur\Modules\Insights\Alerts;
use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Module implements \Mailspur\Module {

	const HOOK     = 'mailspur_types_index';
	const HOOK_NOW = 'mailspur_types_index_now';

	/** Log entries indexed synchronously when the tab or the REST route is opened. */
	const INDEX_ON_VIEW = 3000;

	/** Day counters are never kept longer than this, even with unlimited log retention. */
	const MAX_DAYS = 365;

	/** @var Repository Single emails (compare view); the index reads the log through its own keyset queries. */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		$store      = new Store();
		$indexer    = new Indexer( $store );
		$page       = new Page( $store, $indexer );
		$repository = $this->repository;

		add_action( 'admin_init', array( Store::class, 'maybe_install' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );

		add_filter( 'mailspur_admin_tabs', array( $page, 'tabs' ) );
		add_action( 'mailspur_render_tab_' . Page::TAB, array( $page, 'render' ) );
		add_action( 'mailspur_admin_enqueue', array( $page, 'enqueue' ), 10, 2 );
		add_action( 'mailspur_settings_sections', array( $page, 'settings_section' ), 20, 2 );
		add_filter( 'mailspur_settings_defaults', array( self::class, 'defaults' ) );
		add_filter( 'mailspur_settings_sanitize', array( self::class, 'sanitize' ), 10, 2 );
		add_filter( 'mailspur_rest_item', array( $page, 'rest_item' ), 10, 2 );
		add_action( 'admin_post_mailspur_types_mute', array( $page, 'mute' ) );
		add_action( 'admin_post_mailspur_types_rebuild', array( $page, 'rebuild' ) );
		add_action( 'admin_post_mailspur_types_seen', array( $page, 'seen' ) );

		add_action(
			'rest_api_init',
			static function () use ( $store, $indexer, $repository ): void {
				Store::maybe_install();
				( new Controller( $store, $indexer, $repository ) )->register_routes();
			}
		);

		$cron = static function () use ( $store, $indexer ): void {
			Store::maybe_install();
			$indexer->cron();
			if ( class_exists( Alerts::class ) ) {
				( new Monitor( $store, array( new Alerts(), 'dispatch' ) ) )->run();
			}
		};
		add_action( self::HOOK, $cron );
		add_action( self::HOOK_NOW, $cron );

		add_action( 'upgrader_process_complete', array( Updates::class, 'record' ), 10, 2 );
		add_action(
			Cleanup::HOOK,
			static function () use ( $store ): void {
				$store->prune( self::cutoff( (int) Settings::get( 'retention_days' ), time() ) );
			},
			30
		);
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/** First day to keep: the log retention, capped at a year. */
	public static function cutoff( int $retention_days, int $now ): string {
		$days = $retention_days > 0 ? min( $retention_days, self::MAX_DAYS ) : self::MAX_DAYS;
		return (string) wp_date( 'Y-m-d', $now - $days * DAY_IN_SECONDS );
	}

	/**
	 * @param mixed $defaults Defaults.
	 * @return array<string,mixed>
	 */
	public static function defaults( $defaults ): array {
		return array_merge( is_array( $defaults ) ? $defaults : array(), array( 'alert_types' => false ) );
	}

	/**
	 * @param mixed $clean Sanitized settings.
	 * @param mixed $input Raw form input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $clean, $input ): array {
		$clean                = is_array( $clean ) ? $clean : array();
		$clean['alert_types'] = is_array( $input ) && ! empty( $input['alert_types'] );
		return $clean;
	}

	/** REST namespace, for tests. */
	public static function route(): string {
		return '/' . Rest::NS . '/types';
	}
}
