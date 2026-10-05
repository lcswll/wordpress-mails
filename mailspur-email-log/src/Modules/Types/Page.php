<?php
/**
 * "Email types" tab (server-rendered, works without JavaScript), its settings row and form actions
 * (ignore a type, rebuild), plus the "Email type" line in the log's detail view. Admin noise, slow types and
 * new senders appear as counts in the summary and as small markers on the rows.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Admin;
use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Settings;
use const Mailspur\VERSION;

defined( 'ABSPATH' ) || exit;

final class Page {

	const TAB   = 'types';
	const NONCE = 'mailspur_types';

	/** @var Store */
	private $store;

	/** @var Indexer */
	private $indexer;

	public function __construct( Store $store, Indexer $indexer ) {
		$this->store   = $store;
		$this->indexer = $indexer;
	}

	/**
	 * @param mixed $tabs Tabs.
	 * @return array<string,array{0:string,1:string}>
	 */
	public function tabs( $tabs ): array {
		$tabs = is_array( $tabs ) ? $tabs : array();
		$out  = array();
		// Right after the log: the map of the site's emails is the second thing to look at.
		foreach ( $tabs as $key => $tab ) {
			$out[ $key ] = $tab;
			if ( 'log' === $key ) {
				$out[ self::TAB ] = array( __( 'Email types', 'mailspur-email-log' ), Settings::capability() );
			}
		}
		if ( ! isset( $out[ self::TAB ] ) ) {
			$out[ self::TAB ] = array( __( 'Email types', 'mailspur-email-log' ), Settings::capability() );
		}
		return $out;
	}

	/**
	 * @param string $tab  Current tab.
	 * @param string $base Assets URL.
	 */
	public function enqueue( $tab, $base ): void {
		if ( self::TAB === $tab ) {
			wp_enqueue_style( 'mailspur-types', $base . 'types.css', array(), VERSION );
			wp_enqueue_script(
				'mailspur-types-tab',
				$base . 'types-tab.js',
				array(),
				VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_add_inline_script( 'mailspur-types-tab', 'window.mailspurTypesTab = ' . wp_json_encode( self::script_config() ) . ';', 'before' );
		} elseif ( 'log' === $tab ) {
			wp_enqueue_style( 'mailspur-types', $base . 'types.css', array(), VERSION );
			wp_enqueue_script(
				'mailspur-types',
				$base . 'types.js',
				array( 'mailspur-email-log-admin' ),
				VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_add_inline_script(
				'mailspur-types',
				'window.mailspurTypes = ' . wp_json_encode(
					array(
						'i18n' => array(
							'type'    => __( 'Email type', 'mailspur-email-log' ),
							/* translators: %s: rhythm, e.g. "daily" */
							'rhythm'  => __( 'usually sent: %s', 'mailspur-email-log' ),
							'stopped' => __( 'stopped', 'mailspur-email-log' ),
						),
					)
				) . ';',
				'before'
			);
		}
	}

	/**
	 * Log link that lists the emails of a type: sender filter plus the longest literal part of the subject.
	 *
	 * @param array<string,mixed> $item Report item.
	 */
	public static function log_url( array $item, bool $latest = false ): string {
		$args = array( 'source' => (string) $item['source'] );
		if ( empty( $item['other'] ) && '' !== (string) $item['search'] ) {
			$args['s'] = (string) $item['search'];
		}
		if ( $latest && ! empty( $item['last_id'] ) ) {
			$args['mail'] = (string) (int) $item['last_id']; // Opened in the dialog by types.js.
		}
		return Admin::url( $args );
	}

	/**
	 * Config of types-tab.js (Compare, Send latest to me).
	 *
	 * @return array<string,mixed>
	 */
	private static function script_config(): array {
		$user = wp_get_current_user();
		return array(
			'restUrl' => esc_url_raw( rest_url( Rest::NS ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'email'   => current_user_can( 'manage_options' ) ? (string) $user->user_email : '',
			'i18n'    => array(
				'close'        => __( 'Close', 'mailspur-email-log' ),
				'loading'      => __( 'Loading…', 'mailspur-email-log' ),
				'textChanges'  => __( 'Text changes', 'mailspur-email-log' ),
				'previews'     => __( 'Previews', 'mailspur-email-log' ),
				'before'       => __( 'Last email before the change', 'mailspur-email-log' ),
				'after'        => __( 'First email after the change', 'mailspur-email-log' ),
				/* translators: %s: comma-separated list of updated plugins/themes */
				'updated'      => __( 'Updated in between: %s', 'mailspur-email-log' ),
				'noDiff'       => __( 'The text is the same; only the layout changed. Compare the previews.', 'mailspur-email-log' ),
				/* translators: %s: number of lines */
				'unchanged'    => __( '%s unchanged lines', 'mailspur-email-log' ),
				'added'        => __( 'Added:', 'mailspur-email-log' ),
				'removed'      => __( 'Removed:', 'mailspur-email-log' ),
				/* translators: %s: email address */
				'confirmSend'  => __( 'Send the latest email of this type to %s?', 'mailspur-email-log' ),
				'send'         => __( 'Send', 'mailspur-email-log' ),
				'cancel'       => __( 'Cancel', 'mailspur-email-log' ),
				'sending'      => __( 'Sending…', 'mailspur-email-log' ),
				/* translators: %s: email address */
				'sent'         => __( 'Sent to %s.', 'mailspur-email-log' ),
				'notSent'      => __( 'Sending failed – see the log.', 'mailspur-email-log' ),
				/* translators: %s: error message */
				'failed'       => __( 'Request failed: %s', 'mailspur-email-log' ),
				/* translators: %s: comma-separated file names */
				'missingFiles' => __( 'Attachments no longer available: %s', 'mailspur-email-log' ),
			),
		);
	}

	/**
	 * How often a type is sent, in words.
	 *
	 * @param array{active:int,regular:bool,median:int} $rhythm
	 */
	public static function rhythm_text( array $rhythm ): string {
		if ( ! $rhythm['regular'] ) {
			return $rhythm['active'] > 0 ? __( 'Irregular', 'mailspur-email-log' ) : __( 'Rarely', 'mailspur-email-log' );
		}
		if ( 1 === $rhythm['median'] ) {
			return __( 'Daily', 'mailspur-email-log' );
		}
		if ( $rhythm['median'] >= 6 && $rhythm['median'] <= 8 ) {
			return __( 'Weekly', 'mailspur-email-log' );
		}
		/* translators: %s: number of days */
		return sprintf( __( 'Every %s days', 'mailspur-email-log' ), number_format_i18n( $rhythm['median'] ) );
	}

	public function render(): void {
		if ( ! Settings::current_user_can_view() ) {
			return;
		}
		$this->indexer->run( Module::INDEX_ON_VIEW );
		$remaining = $this->remaining();
		if ( $remaining > 0 && ! wp_next_scheduled( Module::HOOK_NOW ) ) {
			wp_schedule_single_event( time(), Module::HOOK_NOW );
		}

		$now   = time();
		$items = Report::current( $this->store, $now );
		$sum   = Report::summary( $items );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$attention = isset( $_GET['show'] ) && 'attention' === sanitize_key( wp_unslash( $_GET['show'] ) );
		$admin     = current_user_can( 'manage_options' );

		// Stopped first, then failing, new, fine, ignored – within a sender and across senders.
		$priority = array(
			'silent'  => 4,
			'failing' => 3,
			'new'     => 2,
			'ok'      => 1,
			'muted'   => 0,
		);
		usort(
			$items,
			static function ( array $a, array $b ) use ( $priority ): int {
				return array( $priority[ $b['state'] ] ?? 0, $b['total'] ) <=> array( $priority[ $a['state'] ] ?? 0, $a['total'] );
			}
		);
		$available = $this->available( $items );
		$groups    = array();
		foreach ( $items as $item ) {
			if ( $attention && ! in_array( $item['state'], array( 'silent', 'failing' ), true ) ) {
				continue;
			}
			$groups[ $item['source'] ][] = $item; // A sender's position follows its most urgent type.
		}
		?>
		<div class="mailspur-types" id="mailspur-types">
			<?php $this->notice(); ?>
			<p class="mst-intro">
				<?php esc_html_e( 'Every kind of email your site sends, found automatically in the log: grouped by the plugin that sends it and by subject, with numbers, addresses and names as placeholders. Mailspur learns how often each type is sent and notices when one stops – for example after a plugin update.', 'mailspur-email-log' ); ?>
			</p>

			<ul class="mst-summary">
				<li>
					<?php
					/* translators: 1: number of email types, 2: number of plugins/themes */
					printf( esc_html__( '%1$s email types from %2$s senders', 'mailspur-email-log' ), '<strong>' . esc_html( number_format_i18n( $sum['types'] ) ) . '</strong>', '<strong>' . esc_html( number_format_i18n( $sum['senders'] ) ) . '</strong>' );
					?>
				</li>
				<?php if ( $sum['silent'] ) : ?>
					<li class="is-silent"><strong><?php echo esc_html( number_format_i18n( $sum['silent'] ) ); ?></strong> <?php esc_html_e( 'stopped', 'mailspur-email-log' ); ?></li>
				<?php endif; ?>
				<?php if ( $sum['failing'] ) : ?>
					<li class="is-failing"><strong><?php echo esc_html( number_format_i18n( $sum['failing'] ) ); ?></strong> <?php esc_html_e( 'failing', 'mailspur-email-log' ); ?></li>
				<?php endif; ?>
				<?php if ( $sum['new'] ) : ?>
					<li class="is-new"><strong><?php echo esc_html( number_format_i18n( $sum['new'] ) ); ?></strong> <?php esc_html_e( 'new this week', 'mailspur-email-log' ); ?></li>
				<?php endif; ?>
				<?php if ( $sum['fresh'] ) : ?>
					<li class="is-fresh"><strong><?php echo esc_html( number_format_i18n( $sum['fresh'] ) ); ?></strong> <?php esc_html_e( 'from a new sender', 'mailspur-email-log' ); ?></li>
				<?php endif; ?>
				<?php if ( $sum['noise'] ) : ?>
					<li class="is-noise"><strong><?php echo esc_html( number_format_i18n( $sum['noise'] ) ); ?></strong> <?php esc_html_e( 'often to administrators', 'mailspur-email-log' ); ?></li>
				<?php endif; ?>
				<?php if ( $sum['slow'] ) : ?>
					<li class="is-slow"><strong><?php echo esc_html( number_format_i18n( $sum['slow'] ) ); ?></strong> <?php esc_html_e( 'slow to send', 'mailspur-email-log' ); ?></li>
				<?php endif; ?>
			</ul>

			<nav class="mst-views" aria-label="<?php esc_attr_e( 'Filter email types', 'mailspur-email-log' ); ?>">
				<a href="<?php echo esc_url( Admin::url( array( 'tab' => self::TAB ) ) ); ?>" aria-current="<?php echo esc_attr( $attention ? 'false' : 'page' ); ?>"><?php esc_html_e( 'All', 'mailspur-email-log' ); ?></a>
				<a href="
				<?php
				echo esc_url(
					Admin::url(
						array(
							'tab'  => self::TAB,
							'show' => 'attention',
						)
					)
				);
				?>
							" aria-current="<?php echo esc_attr( $attention ? 'page' : 'false' ); ?>"><?php esc_html_e( 'Needs attention', 'mailspur-email-log' ); ?></a>
			</nav>

			<?php if ( $remaining > 0 ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					/* translators: %s: number of emails */
					printf( esc_html__( 'Still sorting %s older emails into types in the background. Reload the page in a minute.', 'mailspur-email-log' ), esc_html( number_format_i18n( $remaining ) ) );
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( ! $groups ) : ?>
				<p class="mst-empty">
					<?php
					if ( $attention ) {
						esc_html_e( 'All email types look healthy.', 'mailspur-email-log' );
					} else {
						esc_html_e( 'No emails logged yet. Email types appear here as soon as your site sends emails.', 'mailspur-email-log' );
					}
					?>
				</p>
			<?php endif; ?>

			<?php foreach ( $groups as $source => $list ) : ?>
				<section class="mst-group" aria-labelledby="mst-group-<?php echo esc_attr( md5( (string) $source ) ); ?>">
					<h2 id="mst-group-<?php echo esc_attr( md5( (string) $source ) ); ?>">
						<?php echo esc_html( Report::source_label( (string) $source ) ); ?>
						<span class="mst-count"><?php echo esc_html( number_format_i18n( count( $list ) ) ); ?></span>
						<?php if ( ! empty( $list[0]['new_sender'] ) ) : ?>
							<span class="mst-flag is-fresh" title="<?php echo esc_attr( self::fresh_title( $list ) ); ?>"><?php esc_html_e( 'New sender', 'mailspur-email-log' ); ?></span>
						<?php endif; ?>
					</h2>
					<div class="mst-table-wrap">
						<table class="widefat mst-table">
							<thead>
								<tr>
									<th scope="col" class="mst-col-type"><?php esc_html_e( 'Email type', 'mailspur-email-log' ); ?></th>
									<th scope="col" class="mst-col-volume"><?php esc_html_e( 'Last 30 days', 'mailspur-email-log' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Rhythm', 'mailspur-email-log' ); ?></th>
									<th scope="col" class="mst-col-last"><?php esc_html_e( 'Last sent', 'mailspur-email-log' ); ?></th>
									<th scope="col" class="mst-col-state"><?php esc_html_e( 'Status', 'mailspur-email-log' ); ?></th>
									<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mailspur-email-log' ); ?></span></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $list as $item ) : ?>
									<?php $this->row( $item, $now, $admin, $available ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
			<?php endforeach; ?>

			<?php if ( $admin ) : ?>
				<form class="mst-rebuild" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mailspur_types_rebuild">
					<?php wp_nonce_field( self::NONCE ); ?>
					<p class="description">
						<?php esc_html_e( 'Types are updated hourly and whenever you open this page. Only counters, subject patterns and content fingerprints are stored (no recipients, no contents), and they follow the retention period of the log.', 'mailspur-email-log' ); ?>
						<button type="submit" class="button-link"><?php esc_html_e( 'Rebuild from the log', 'mailspur-email-log' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $item      Report item.
	 * @param array<int,bool>     $available Log ids that still have content.
	 */
	private function row( array $item, int $now, bool $admin, array $available ): void {
		$state  = (string) $item['state'];
		$labels = array(
			'silent'  => __( 'Stopped', 'mailspur-email-log' ),
			'failing' => __( 'Failing', 'mailspur-email-log' ),
			'new'     => __( 'New', 'mailspur-email-log' ),
			'ok'      => __( 'OK', 'mailspur-email-log' ),
			'muted'   => __( 'Ignored', 'mailspur-email-log' ),
		);
		?>
		<tr id="mailspur-type-<?php echo esc_attr( (string) $item['id'] ); ?>" class="mst-row is-<?php echo esc_attr( $state ); ?>">
			<td class="mst-col-type">
				<span class="mst-pattern"><?php echo wp_kses( self::pattern_html( (array) $item['pattern'] ), array( 'span' => array( 'class' => true ) ) ); ?></span>
				<span class="mst-meta">
					<?php
					/* translators: %s: date */
					printf( esc_html__( 'first seen %s', 'mailspur-email-log' ), esc_html( (string) wp_date( (string) get_option( 'date_format' ), (int) $item['first_seen'] ) ) );
					if ( $item['notes'] > 0 ) {
						echo ' · <span class="mst-notes">';
						/* translators: %s: number of notes */
						printf( esc_html__( 'Notes in the latest email: %s', 'mailspur-email-log' ), esc_html( number_format_i18n( (int) $item['notes'] ) ) );
						echo '</span>';
					}
					?>
				</span>
				<?php $this->change( $item, $admin, $available ); ?>
				<?php $this->hints( $item, $admin ); ?>
			</td>
			<td class="mst-col-volume">
				<?php echo wp_kses( self::sparkline( (array) $item['series'] ), self::svg_tags() ); ?>
				<span class="mst-volume">
					<?php
					/* translators: %s: number of emails */
					printf( esc_html__( '%s in 30 days', 'mailspur-email-log' ), esc_html( number_format_i18n( (int) $item['total'] ) ) );
					if ( $item['failed'] > 0 ) {
						echo ' · <span class="mst-failed">';
						/* translators: %s: number of failed emails */
						printf( esc_html__( '%s failed', 'mailspur-email-log' ), esc_html( number_format_i18n( (int) $item['failed'] ) ) );
						echo '</span>';
					}
					?>
				</span>
			</td>
			<td><?php echo esc_html( self::rhythm_text( $item['rhythm'] ) ); ?></td>
			<td class="mst-col-last">
				<?php
				/* translators: %s: time span, e.g. "3 hours" */
				printf( esc_html__( '%s ago', 'mailspur-email-log' ), esc_html( human_time_diff( (int) $item['last_seen'], $now ) ) );
				?>
			</td>
			<td class="mst-col-state">
				<span class="mst-state is-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $labels[ $state ] ?? $state ); ?></span>
				<?php if ( 'silent' === $state ) : ?>
					<span class="mst-why">
						<?php
						printf(
							/* translators: 1: number of days, 2: time span */
							esc_html__( 'Normally sent at least every %1$s days, now overdue by %2$s.', 'mailspur-email-log' ),
							esc_html( number_format_i18n( (int) $item['rhythm']['expected'] ) ),
							esc_html( human_time_diff( $now - (int) $item['rhythm']['overdue'], $now ) )
						);
						?>
					</span>
					<?php if ( $item['updates'] ) : ?>
						<span class="mst-why">
							<?php esc_html_e( 'Updated since the last one:', 'mailspur-email-log' ); ?>
							<?php
							$names = array();
							foreach ( (array) $item['updates'] as $update ) {
								$name    = esc_html( (string) $update['label'] );
								$names[] = (string) $update['slug'] === (string) $item['source'] ? '<strong>' . $name . '</strong>' : $name;
							}
							echo wp_kses( implode( ', ', array_unique( $names ) ), array( 'strong' => array() ) );
							?>
						</span>
					<?php endif; ?>
					<?php if ( ! empty( $item['cause']['text'] ) ) : ?>
						<span class="mst-why mst-cause is-<?php echo esc_attr( (string) $item['cause']['code'] ); ?>"><?php echo esc_html( (string) $item['cause']['text'] ); ?></span>
					<?php endif; ?>
				<?php elseif ( 'failing' === $state ) : ?>
					<span class="mst-why"><?php esc_html_e( 'Several of the latest emails of this type failed.', 'mailspur-email-log' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="mst-actions">
				<a href="<?php echo esc_url( self::log_url( $item ) ); ?>"><?php esc_html_e( 'Show emails', 'mailspur-email-log' ); ?></a>
				<?php if ( $admin ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="mailspur_types_mute">
						<input type="hidden" name="type" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
						<input type="hidden" name="muted" value="<?php echo esc_attr( $item['muted'] ? '0' : '1' ); ?>">
						<?php wp_nonce_field( self::NONCE, '_wpnonce', false ); ?>
						<button type="submit" class="button-link"><?php echo esc_html( $item['muted'] ? __( 'Monitor again', 'mailspur-email-log' ) : __( 'Ignore', 'mailspur-email-log' ) ); ?></button>
					</form>
				<?php endif; ?>
				<?php if ( ! empty( $item['last_id'] ) ) : ?>
					<details class="mst-more">
						<summary><span aria-hidden="true">…</span><span class="screen-reader-text"><?php esc_html_e( 'More actions', 'mailspur-email-log' ); ?></span></summary>
						<div class="mst-menu">
							<a href="<?php echo esc_url( self::log_url( $item, true ) ); ?>"><?php esc_html_e( 'Open latest', 'mailspur-email-log' ); ?></a>
							<?php if ( $admin ) : ?>
								<button type="button" class="button-link mst-send" data-mail="<?php echo esc_attr( (string) $item['last_id'] ); ?>" hidden><?php esc_html_e( 'Send latest to me', 'mailspur-email-log' ); ?></button>
							<?php endif; ?>
						</div>
					</details>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * "Content changed on 3 Oct, after the WooCommerce 9.4 update" with Compare (JS) and Seen.
	 *
	 * @param array<string,mixed> $item      Report item.
	 * @param array<int,bool>     $available Log ids that still have content.
	 */
	private function change( array $item, bool $admin, array $available ): void {
		$change = $item['change'] ?? null;
		if ( ! is_array( $change ) ) {
			return;
		}
		$date = (string) wp_date( (string) get_option( 'date_format' ), (int) $change['after_at'] );
		?>
		<span class="mst-change">
			<?php
			if ( $change['updates'] ) {
				/* translators: 1: date, 2: updated plugins/themes, e.g. "WooCommerce 9.4" */
				printf( esc_html__( 'Content changed on %1$s, after the %2$s update.', 'mailspur-email-log' ), esc_html( $date ), esc_html( implode( ', ', (array) $change['updates'] ) ) );
			} else {
				/* translators: %s: date */
				printf( esc_html__( 'Content changed on %s.', 'mailspur-email-log' ), esc_html( $date ) );
			}
			?>
			<?php if ( isset( $available[ (int) $change['before'] ], $available[ (int) $change['after'] ] ) ) : ?>
				<button type="button" class="button-link mst-compare" data-type="<?php echo esc_attr( (string) $item['id'] ); ?>" hidden><?php esc_html_e( 'Compare', 'mailspur-email-log' ); ?></button>
			<?php else : ?>
				<span class="mst-gone"><?php esc_html_e( '(the emails are no longer in the log)', 'mailspur-email-log' ); ?></span>
			<?php endif; ?>
			<?php if ( $admin ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mailspur_types_seen">
					<input type="hidden" name="type" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
					<?php wp_nonce_field( self::NONCE, '_wpnonce', false ); ?>
					<button type="submit" class="button-link"><?php esc_html_e( 'Seen', 'mailspur-email-log' ); ?></button>
				</form>
			<?php endif; ?>
		</span>
		<?php
	}

	/**
	 * Tooltip of the "New sender" marker.
	 *
	 * @param array<int,array<string,mixed>> $items Report items of one sender.
	 */
	private static function fresh_title( array $items ): string {
		$first = time();
		foreach ( $items as $item ) {
			$first = min( $first, (int) $item['first_seen'] );
		}
		return sprintf(
			/* translators: %s: date */
			__( 'First email from this sender on %s. With type alerts on, Mailspur alerts when a new sender writes to many external addresses.', 'mailspur-email-log' ),
			(string) wp_date( (string) get_option( 'date_format' ), $first )
		);
	}

	/**
	 * Markers for admin noise (with where to switch it off) and slow sending (with tips), as small disclosures.
	 *
	 * @param array<string,mixed> $item Report item.
	 */
	private function hints( array $item, bool $admin ): void {
		$fix   = Noise::fix( (string) $item['origin'], (string) $item['source'] );
		$quiet = isset( $fix['quiet'] ) && in_array( $fix['quiet'], Quiet::active(), true ) ? (string) $fix['quiet'] : '';
		$slow  = is_array( $item['slow'] ) ? $item['slow'] : null;
		if ( empty( $item['noise'] ) && null === $slow && '' === $quiet ) {
			return;
		}
		?>
		<span class="mst-hints">
			<?php if ( '' !== $quiet ) : ?>
				<span class="mst-hint is-quiet">
					<?php esc_html_e( 'Stopped by Mailspur.', 'mailspur-email-log' ); ?>
					<?php if ( $admin ) : ?>
						<?php self::quiet_form( $quiet, false, (int) $item['id'], __( 'Send again', 'mailspur-email-log' ) ); ?>
					<?php endif; ?>
				</span>
			<?php elseif ( ! empty( $item['noise'] ) ) : ?>
				<details class="mst-hint is-noise">
					<summary>
						<?php
						/* translators: %s: number of emails */
						printf( esc_html__( '%s to administrators in 30 days', 'mailspur-email-log' ), esc_html( number_format_i18n( (int) $item['admin'] ) ) );
						?>
					</summary>
					<div class="mst-hint-body">
						<?php if ( isset( $fix['url'], $fix['label'], $fix['hint'] ) ) : ?>
							<p>
								<?php
								printf(
									/* translators: 1: link to a settings screen, 2: name of the setting */
									esc_html__( 'Switch it off under %1$s: “%2$s”.', 'mailspur-email-log' ),
									'<a href="' . esc_url( (string) $fix['url'] ) . '">' . esc_html( (string) $fix['label'] ) . '</a>',
									esc_html( (string) $fix['hint'] )
								);
								?>
							</p>
						<?php elseif ( isset( $fix['quiet'] ) ) : ?>
							<p>
								<?php
								if ( Quiet::UPDATES === $fix['quiet'] ) {
									esc_html_e( 'WordPress has no setting for this email. Mailspur can stop the success notices of automatic updates; notices about failed updates still arrive.', 'mailspur-email-log' );
								} else {
									esc_html_e( 'WordPress has no setting for this email. Mailspur can stop the notice to administrators; the new user still receives their own email.', 'mailspur-email-log' );
								}
								?>
							</p>
							<?php if ( $admin ) : ?>
								<?php self::quiet_form( (string) $fix['quiet'], true, (int) $item['id'], __( 'Stop these emails', 'mailspur-email-log' ) ); ?>
							<?php endif; ?>
						<?php else : ?>
							<p>
								<?php
								/* translators: %s: plugin/theme name */
								printf( esc_html__( 'Look for an option to switch it off or to change its recipient in the email settings of %s – or ignore the type here.', 'mailspur-email-log' ), esc_html( Report::source_label( (string) $item['source'] ) ) );
								?>
							</p>
						<?php endif; ?>
					</div>
				</details>
			<?php endif; ?>
			<?php if ( null !== $slow ) : ?>
				<details class="mst-hint is-slow">
					<summary>
						<?php
						/* translators: %s: seconds, e.g. "2.4" */
						printf( esc_html__( 'Waits %s s for the mail server', 'mailspur-email-log' ), esc_html( number_format_i18n( $slow['median'] / 1000, 1 ) ) );
						?>
					</summary>
					<div class="mst-hint-body">
						<p>
							<?php
							printf(
								/* translators: 1: number of emails, 2: seconds, 3: seconds */
								esc_html__( 'Of the last %1$s emails sent while someone waited for the page, half took longer than %2$s s (average %3$s s).', 'mailspur-email-log' ),
								esc_html( number_format_i18n( (int) $slow['n'] ) ),
								esc_html( number_format_i18n( $slow['median'] / 1000, 1 ) ),
								esc_html( number_format_i18n( $slow['average'] / 1000, 1 ) )
							);
							?>
						</p>
						<ul>
							<?php foreach ( Speed::tips( (string) $slow['mailer'] ) as $tip ) : ?>
								<li><?php echo esc_html( $tip ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</details>
			<?php endif; ?>
		</span>
		<?php
	}

	private static function quiet_form( string $key, bool $on, int $id, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mailspur_types_quiet">
			<input type="hidden" name="quiet" value="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="on" value="<?php echo esc_attr( $on ? '1' : '0' ); ?>">
			<input type="hidden" name="type" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( Quiet::NONCE, '_wpnonce', false ); ?>
			<button type="submit" class="button-link"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Log ids of the content changes that still have their content (not deleted or anonymised).
	 *
	 * @param array<int,array<string,mixed>> $items Report items.
	 * @return array<int,bool>
	 */
	private function available( array $items ): array {
		global $wpdb;
		$ids = array();
		foreach ( $items as $item ) {
			if ( is_array( $item['change'] ?? null ) ) {
				$ids[] = (int) $item['change']['before'];
				$ids[] = (int) $item['change']['after'];
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, primary keys.
		$found = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND message <> ''",
				array_merge( array( Repository::table() ), $ids )
			)
		);
		return array_fill_keys( array_map( 'intval', $found ), true );
	}

	/**
	 * Pattern words with placeholders as styled chips (escaped here, printed through wp_kses).
	 *
	 * @param string[] $pattern
	 */
	public static function pattern_html( array $pattern ): string {
		if ( array( Fingerprint::OTHER ) === $pattern || ! $pattern ) {
			return '<span class="mst-other">' . esc_html( Report::text( $pattern ) ) . '</span>';
		}
		$parts = preg_split( '/(\{[^}]*\})/u', implode( ' ', $pattern ), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$html  = '';
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			$html .= '{' === $part[0] && '}' === substr( $part, -1 )
				? '<span class="mst-ph">' . esc_html( self::placeholder( $part ) ) . '</span>'
				: esc_html( $part );
		}
		return $html;
	}

	private static function placeholder( string $token ): string {
		switch ( $token ) {
			case '{#}':
				return __( 'number', 'mailspur-email-log' );
			case '{email}':
				return __( 'address', 'mailspur-email-log' );
			case '{url}':
				return __( 'link', 'mailspur-email-log' );
			case '{date}':
				return __( 'date', 'mailspur-email-log' );
			case '{time}':
				return __( 'time', 'mailspur-email-log' );
			case '{text}':
				return __( 'text', 'mailspur-email-log' );
			case '{id}':
				return __( 'code', 'mailspur-email-log' );
		}
		return __( 'name', 'mailspur-email-log' );
	}

	/**
	 * Bars of the last 30 days (failed part in red, held part in grey), as an accessible inline SVG.
	 *
	 * @param array<int,array{0:string,1:int,2:int,3:int}> $series
	 */
	public static function sparkline( array $series ): string {
		$width  = 4;
		$gap    = 1;
		$height = 26;
		$max    = max( array_merge( array( 1 ), array_map( 'intval', array_column( $series, 1 ) ) ) );
		$total  = array_sum( array_column( $series, 1 ) );
		$failed = array_sum( array_column( $series, 2 ) );
		/* translators: 1: number of emails, 2: number of failed emails */
		$label = sprintf( __( '%1$s emails in the last 30 days, %2$s failed', 'mailspur-email-log' ), number_format_i18n( $total ), number_format_i18n( $failed ) );

		$svg = sprintf(
			'<svg class="mst-spark" role="img" aria-label="%1$s" width="%2$d" height="%3$d" viewBox="0 0 %2$d %3$d" focusable="false">',
			esc_attr( $label ),
			count( $series ) * ( $width + $gap ),
			$height
		);
		foreach ( array_values( $series ) as $i => $day ) {
			list( $date, $count, $bad, $held ) = $day;
			$x                                 = $i * ( $width + $gap );
			$svg                              .= sprintf( '<rect class="mst-bar-base" x="%d" y="%d" width="%d" height="1"></rect>', $x, $height - 1, $width );
			if ( $count <= 0 ) {
				continue;
			}
			$h    = max( 2, (int) round( $count / $max * ( $height - 2 ) ) );
			$fh   = $bad > 0 ? max( 1, (int) round( $bad / $count * $h ) ) : 0;
			$hh   = $held > 0 ? max( 1, (int) round( $held / $count * $h ) ) : 0;
			$svg .= sprintf( '<rect class="mst-bar" x="%d" y="%d" width="%d" height="%d"><title>%s</title></rect>', $x, $height - $h, $width, $h, esc_html( $date . ': ' . number_format_i18n( $count ) ) );
			if ( $hh ) {
				$svg .= sprintf( '<rect class="mst-bar-held" x="%d" y="%d" width="%d" height="%d"></rect>', $x, $height - $h, $width, min( $hh, $h ) );
			}
			if ( $fh ) {
				$svg .= sprintf( '<rect class="mst-bar-failed" x="%d" y="%d" width="%d" height="%d"></rect>', $x, $height - $fh, $width, $fh );
			}
		}
		return $svg . '</svg>';
	}

	/** @return array<string,array<string,bool>> */
	public static function svg_tags(): array {
		$rect = array(
			'class'  => true,
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
		);
		return array(
			'svg'   => array(
				'class'      => true,
				'role'       => true,
				'aria-label' => true,
				'width'      => true,
				'height'     => true,
				'viewbox'    => true,
				'focusable'  => true,
			),
			'rect'  => $rect,
			'title' => array(),
		);
	}

	private function remaining(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, primary key range.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d', Repository::table(), (int) get_option( Indexer::CURSOR, 0 ) ) );
	}

	private function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, set by our own redirect.
		$done = isset( $_GET['types-done'] ) ? sanitize_key( wp_unslash( $_GET['types-done'] ) ) : '';
		$text = array(
			'rebuilt' => __( 'The email types are being rebuilt from the log.', 'mailspur-email-log' ),
			'muted'   => __( 'This email type is ignored now: no status, no alerts.', 'mailspur-email-log' ),
			'watched' => __( 'This email type is monitored again.', 'mailspur-email-log' ),
			'seen'    => __( 'Content change marked as seen.', 'mailspur-email-log' ),
			'quiet'   => __( 'Mailspur stops these emails now; their email type is ignored.', 'mailspur-email-log' ),
			'loud'    => __( 'These emails are sent again; their email type is monitored again.', 'mailspur-email-log' ),
		);
		if ( isset( $text[ $done ] ) ) {
			printf( '<div class="notice notice-success inline is-dismissible"><p>%s</p></div>', esc_html( $text[ $done ] ) );
		}
	}

	/** admin-post.php?action=mailspur_types_mute */
	public function mute(): void {
		$this->guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$id    = isset( $_POST['type'] ) ? absint( $_POST['type'] ) : 0;
		$muted = ! empty( $_POST['muted'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( $id ) {
			$this->store->mute( $id, $muted );
		}
		wp_safe_redirect(
			Admin::url(
				array(
					'tab'        => self::TAB,
					'types-done' => $muted ? 'muted' : 'watched',
				)
			) . '#mailspur-type-' . $id
		);
		exit;
	}

	/** admin-post.php?action=mailspur_types_seen – hides the content change marker until the next change. */
	public function seen(): void {
		$this->guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$id    = isset( $_POST['type'] ) ? absint( $_POST['type'] ) : 0;
		$types = $this->store->types();
		if ( isset( $types[ $id ] ) ) {
			$change = $types[ $id ]['extra']['content']['c'] ?? null;
			Report::mark_seen( $id, is_array( $change ) ? (int) ( $change['after'] ?? 0 ) : 0, $types );
		}
		wp_safe_redirect(
			Admin::url(
				array(
					'tab'        => self::TAB,
					'types-done' => 'seen',
				)
			) . '#mailspur-type-' . $id
		);
		exit;
	}

	/** admin-post.php?action=mailspur_types_rebuild */
	public function rebuild(): void {
		$this->guard();
		$this->indexer->rebuild();
		wp_safe_redirect(
			Admin::url(
				array(
					'tab'        => self::TAB,
					'types-done' => 'rebuilt',
				)
			)
		);
		exit;
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'mailspur-email-log' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
	}

	/**
	 * Settings row (after the monitoring alerts).
	 *
	 * @param mixed $s    Current settings.
	 * @param mixed $name Option name.
	 */
	public function settings_section( $s, $name ): void {
		$s    = is_array( $s ) ? $s : array();
		$name = (string) $name;
		?>
		<table class="form-table" role="presentation" id="mailspur-types-alerts">
			<tr>
				<th scope="row"><?php esc_html_e( 'Stopped email types', 'mailspur-email-log' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[alert_types]" value="1" <?php checked( ! empty( $s['alert_types'] ) ); ?>> <?php esc_html_e( 'Alert when an email type that is sent regularly stops', 'mailspur-email-log' ); ?></label>
					<p class="description"><?php esc_html_e( 'Mailspur learns the rhythm of every email type from the last 8 weeks – a daily order confirmation is overdue after 2 days, a weekly report after 2 weeks. The alert names the type, its sender and the plugins updated since its last email. Uses the channels above; ignored types never alert.', 'mailspur-email-log' ); ?></p>
						<p class="description"><?php esc_html_e( 'Also alerts once when a plugin or theme that never sent an email before writes to many different external addresses within a day – a possible sign of a hacked site. The first two weeks after installing Mailspur are the baseline.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Adds the email type to the log's detail view (REST mails/{id}).
	 *
	 * @param mixed $item REST item.
	 * @param mixed $row  Log row.
	 * @return mixed
	 */
	public function rest_item( $item, $row ) {
		if ( ! is_array( $item ) || ! is_array( $row ) || Indexer::ignored( (string) ( $row['source'] ?? '' ) ) ) {
			return $item;
		}
		try {
			$source = (string) ( $row['source'] ?? '' );
			$types  = array_filter(
				$this->store->types(),
				static function ( array $type ) use ( $source ): bool {
					return $type['source'] === $source;
				}
			);
			$id     = Indexer::find( $types, (string) ( $row['subject'] ?? '' ) );
			if ( null === $id ) {
				return $item;
			}
			foreach ( Report::current( $this->store, time(), array( $id => $types[ $id ] ) ) as $report ) {
				if ( $report['id'] === $id ) {
					$item['mailtype'] = array(
						'label'  => Report::text( $report['pattern'] ),
						'rhythm' => self::rhythm_text( $report['rhythm'] ),
						'state'  => $report['state'],
						'total'  => $report['total'],
						'url'    => Admin::url( array( 'tab' => self::TAB ) ) . '#mailspur-type-' . $id,
					);
					break;
				}
			}
		} catch ( \Throwable $e ) {
			return $item; // The detail view must never break because of this extra line.
		}
		return $item;
	}
}
