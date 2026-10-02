<?php
/**
 * Workflow module: source/format/attachment/notes filters (sources endpoint), CSV/JSON export,
 * WP-CLI commands and anonymisation of old entries.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Workflow;

use Mailspur\Repository;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Module implements \Mailspur\Module {

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		( new Anonymiser( $this->repository ) )->register();

		$exporter = new Exporter( $this->repository );
		add_action( 'admin_post_' . Exporter::ACTION, array( $exporter, 'download' ) );

		add_action(
			'rest_api_init',
			static function (): void {
				( new Sources() )->register_routes();
			}
		);
		add_action( 'mailspur_admin_enqueue', array( $this, 'assets' ), 10, 2 );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'mailspur', new Cli( new Commands( $this->repository ) ) );
		}
	}

	public function assets( string $tab, string $base ): void {
		if ( 'log' !== $tab ) {
			return;
		}
		wp_enqueue_script(
			'mailspur-workflow',
			$base . 'workflow.js',
			array( 'mailspur-email-log-admin' ),
			\Mailspur\VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_enqueue_style( 'mailspur-workflow', $base . 'workflow.css', array( 'mailspur-email-log-admin' ), \Mailspur\VERSION );

		$config = array(
			'exportUrl'    => esc_url_raw( admin_url( 'admin-post.php' ) ),
			'exportAction' => Exporter::ACTION,
			'exportNonce'  => wp_create_nonce( Exporter::NONCE ),
			'exportKeys'   => Filters::KEYS,
			'i18n'         => array(
				'export'        => __( 'Export', 'mailspur-email-log' ),
				'exportCsv'     => __( 'CSV', 'mailspur-email-log' ),
				'exportJson'    => __( 'JSON', 'mailspur-email-log' ),
				/* translators: %s: file format, e.g. "CSV" */
				'exportTitle'   => __( 'Download the entries matching the current filters as %s', 'mailspur-email-log' ),
				'exportBodies'  => __( 'Include content', 'mailspur-email-log' ),
				'exportStarted' => __( 'Export started – your download begins shortly.', 'mailspur-email-log' ),
				'anonymised'    => __( 'Anonymised', 'mailspur-email-log' ),
				'privacy'       => __( 'Privacy', 'mailspur-email-log' ),
				/* translators: %s: number of days */
				'removedAfter'  => __( 'Content removed after %s days', 'mailspur-email-log' ),
				'removed'       => __( 'Content removed', 'mailspur-email-log' ),
				'anonymisedTip' => __( 'Content, headers and attachments were removed and the addresses masked by the anonymisation setting.', 'mailspur-email-log' ),
			),
		);
		wp_add_inline_script( 'mailspur-workflow', 'window.mailspurWorkflow = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/** Who may export and use the source list: everyone who may read the log. */
	public static function can_view(): bool {
		return Settings::current_user_can_view();
	}
}
