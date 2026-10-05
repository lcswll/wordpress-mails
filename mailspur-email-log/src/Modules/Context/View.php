<?php
/**
 * Compact mail list for the order meta box and the user profile (server-rendered, escaped).
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Context;

use Mailspur\Admin;
use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class View {

	/** Query arg the log tab reads to open one mail (see assets/context.js). */
	const OPEN_ARG = 'mail';

	/**
	 * Deep link into the log: filtered to the first recipient and the send day, so the mail is on the first
	 * page; context.js then opens its dialog.
	 *
	 * @param array<string,string> $row
	 */
	public static function log_url( array $row ): string {
		$args   = array( self::OPEN_ARG => (string) (int) $row['id'] );
		$emails = Repository::extract_emails( (string) ( $row['recipients'] ?? '' ) );
		if ( $emails ) {
			$args['s'] = rawurlencode( $emails[0] );
		}
		$time = strtotime( (string) ( $row['created_at'] ?? '' ) . ' UTC' );
		if ( false !== $time ) {
			$day            = (string) wp_date( 'Y-m-d', $time );
			$args['after']  = $day;
			$args['before'] = $day;
		}
		return Admin::url( $args );
	}

	/** The log filtered by one recipient. */
	public static function recipient_url( string $email ): string {
		return Admin::url( array( 's' => rawurlencode( $email ) ) );
	}

	/**
	 * @return array<string,string> Status slug => label (same wording as the log).
	 */
	public static function status_labels(): array {
		return array(
			'sent'    => __( 'Sent', 'mailspur-email-log' ),
			'failed'  => __( 'Failed', 'mailspur-email-log' ),
			'pending' => __( 'Unknown', 'mailspur-email-log' ),
			'held'    => __( 'Held', 'mailspur-email-log' ),
		);
	}

	/**
	 * @param array<int,array<string,string>> $rows
	 * @param array<int,bool>                 $loose  Ids of mails that are only matched by recipient.
	 * @param bool                            $resend Show the resend button.
	 */
	public static function table( array $rows, array $loose, bool $resend ): void {
		$labels = self::status_labels();
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<table class="mailspur-ctx-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'mailspur-email-log' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Subject', 'mailspur-email-log' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'mailspur-email-log' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mailspur-email-log' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $rows as $row ) :
					$id     = (int) $row['id'];
					$status = Repository::status_slug( (int) $row['status'] );
					$notes  = (int) ( $row['notes'] ?? 0 );
					$time   = (int) strtotime( (string) $row['created_at'] . ' UTC' );
					?>
					<tr>
						<td class="mailspur-ctx-date"><time datetime="<?php echo esc_attr( gmdate( 'c', $time ) ); ?>"><?php echo esc_html( (string) wp_date( $format, $time ) ); ?></time></td>
						<td class="mailspur-ctx-subject">
							<?php echo esc_html( '' !== (string) $row['subject'] ? (string) $row['subject'] : __( '(no subject)', 'mailspur-email-log' ) ); ?>
							<?php if ( ! empty( $loose[ $id ] ) ) : ?>
								<span class="mailspur-ctx-tag" title="<?php esc_attr_e( 'Sent to the same address around the order date, but not linked to this order (for example logged before the link existed, or imported).', 'mailspur-email-log' ); ?>"><?php esc_html_e( 'same recipient', 'mailspur-email-log' ); ?></span>
							<?php endif; ?>
							<?php if ( $notes > 0 ) : ?>
								<?php /* translators: %s: number of notes (hints) on the email */ ?>
								<span class="mailspur-ctx-tag is-notes"><?php echo esc_html( sprintf( __( '%s notes', 'mailspur-email-log' ), number_format_i18n( $notes ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><span class="mailspur-ctx-pill is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $labels[ $status ] ?? $status ); ?></span></td>
						<td class="mailspur-ctx-actions">
							<a href="<?php echo esc_url( self::log_url( $row ) ); ?>"><?php esc_html_e( 'View', 'mailspur-email-log' ); ?></a>
							<?php if ( $resend ) : ?>
								<button type="button" class="button-link mailspur-ctx-resend" data-id="<?php echo esc_attr( (string) $id ); ?>" data-to="<?php echo esc_attr( (string) $row['recipients'] ); ?>"><?php esc_html_e( 'Resend', 'mailspur-email-log' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
