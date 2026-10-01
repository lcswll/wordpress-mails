<?php
/**
 * GDPR: personal data exporter / eraser and privacy policy suggestion.
 *
 * @package OutboxMailLog
 */

namespace OutboxMailLog;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	const BATCH = 100;

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_content' ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $exporters
	 * @return array<string,array<string,mixed>>
	 */
	public function add_exporter( array $exporters ): array {
		$exporters['outbox-mail-log'] = array(
			'exporter_friendly_name' => __( 'Email log', 'outbox-mail-log' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string,array<string,mixed>> $erasers
	 * @return array<string,array<string,mixed>>
	 */
	public function add_eraser( array $erasers ): array {
		$erasers['outbox-mail-log'] = array(
			'eraser_friendly_name' => __( 'Email log', 'outbox-mail-log' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		// The LIKE pre-filter pages over raw rows, so "done" is based on the raw batch size.
		$scanned = 0;
		$rows    = $this->repository->find_by_recipient( $email, self::BATCH, ( max( 1, $page ) - 1 ) * self::BATCH, $scanned );
		$data    = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'outbox-mail-log',
				'group_label' => __( 'Email log', 'outbox-mail-log' ),
				'item_id'     => 'outbox-mail-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Date', 'outbox-mail-log' ),
						'value' => get_date_from_gmt( $row['created_at'] ),
					),
					array(
						'name'  => __( 'Recipient', 'outbox-mail-log' ),
						'value' => $row['recipients'],
					),
					array(
						'name'  => __( 'Subject', 'outbox-mail-log' ),
						'value' => $row['subject'],
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => $scanned < self::BATCH,
		);
	}

	/**
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		// Matches get deleted, so only the non-matching candidates seen so far need to be skipped.
		$scanned = 0;
		$offset  = (int) get_transient( 'outbox_mail_log_erase_offset_' . md5( $email ) );
		$rows    = $this->repository->find_by_recipient( $email, self::BATCH, 1 === $page ? 0 : $offset, $scanned );
		$deleted = $this->repository->delete( array_column( $rows, 'id' ) );
		$done    = $scanned < self::BATCH;

		if ( $done ) {
			delete_transient( 'outbox_mail_log_erase_offset_' . md5( $email ) );
		} else {
			set_transient( 'outbox_mail_log_erase_offset_' . md5( $email ), ( 1 === $page ? 0 : $offset ) + $scanned - $deleted, HOUR_IN_SECONDS );
		}

		return array(
			'items_removed'  => $deleted > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		);
	}

	public function policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Outbox – Mail Log', 'outbox-mail-log' ),
			'<p>' . esc_html__( 'This site keeps a log of emails it sends (recipient, subject, content and delivery status) to verify delivery and troubleshoot problems. Log entries are deleted automatically after the configured retention period.', 'outbox-mail-log' ) . '</p>'
		);
	}
}
