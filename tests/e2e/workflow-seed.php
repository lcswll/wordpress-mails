<?php
/**
 * Must-use plugin (installed by seed.d/workflow.php) for the browser tests of the Workflow module:
 * wp-admin/?mailspur-e2e-seed=workflow inserts the module's test rows once (subjects contain "[WF]").
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.NonceVerification.Recommended
 *
 * @package Mailspur
 */

use Mailspur\Modules\Workflow\Anonymiser;
use Mailspur\Repository;

add_action(
	'admin_init',
	static function () {
		if ( ! isset( $_GET['mailspur-e2e-seed'] ) || 'workflow' !== $_GET['mailspur-e2e-seed'] || ! current_user_can( 'manage_options' ) || ! class_exists( Repository::class ) ) {
			return;
		}
		if ( ! get_option( 'mailspur_e2e_workflow_seeded' ) ) {
			$repository = new Repository();
			$base       = array(
				'created_at'   => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'status'       => Repository::STATUS_SENT,
				'recipients'   => 'wf@example.com',
				'subject'      => '[WF] mail',
				'message'      => 'Plain body',
				'headers'      => 'From: Shop <shop@example.com>',
				'attachments'  => '',
				'content_type' => 'text/plain',
				'sender'       => 'Shop <shop@example.com>',
				'source'       => 'core',
				'error'        => '',
				'meta'         => '',
				'notes'        => 0,
				'size'         => 10,
				'raw'          => '',
			);
			$rows       = array(
				array(
					'subject'      => '[WF] Invoice with attachment',
					'content_type' => 'text/html',
					'message'      => '<p>Invoice</p>',
					'attachments'  => '[{"name":"invoice.pdf","path":"/nowhere/invoice.pdf"}]',
					'source'       => 'plugin:mailspur-email-log',
				),
				array(
					'subject' => '[WF] Failed with notes',
					'status'  => Repository::STATUS_FAILED,
					'error'   => 'SMTP connect() failed.',
					'notes'   => 3,
					'source'  => 'plugin:mailspur-email-log',
				),
				array(
					'subject' => '=SUM(1+1) [WF] formula',
					'source'  => 'theme:' . get_stylesheet(),
				),
				array(
					'subject' => '[WF] Held on staging',
					'status'  => Repository::STATUS_HELD,
				),
			);
			foreach ( $rows as $row ) {
				$repository->insert( array_merge( $base, $row ) );
			}

			// An entry the anonymisation already processed.
			$old  = array_merge(
				$base,
				array(
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ),
					'subject'    => '[WF] Old anonymised order',
					'recipients' => 'Anna <anna@example.com>',
				)
			);
			$data = Anonymiser::anonymise( $old, 30, false, time() );
			$old  = array_merge( $old, $data, array( 'meta' => (string) wp_json_encode( $data['meta'] ) ) );
			$repository->insert( $old );

			// Provider statuses ("[WFD]", outside the "[WF]" search of the other tests).
			foreach ( array( 'bounced', 'delivered' ) as $status ) {
				$repository->insert(
					array_merge(
						$base,
						array(
							'subject'  => '[WFD] Invoice ' . $status,
							'delivery' => Repository::delivery_code( $status ),
							'meta'     => (string) wp_json_encode(
								array(
									'feedback' => array(
										'event' => $status,
										'hard'  => 'bounced' === $status,
										'via'   => 'postmark',
										'at'    => time(),
									),
								)
							),
						)
					)
				);
			}

			update_option( 'mailspur_e2e_workflow_seeded', 1, false );
			delete_transient( 'mailspur_sources' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mailspur-email-log&s=%5BWF%5D' ) );
		exit;
	}
);
