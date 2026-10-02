<?php
/**
 * Dashboard widget: emails of the last 7 days (server-rendered SVG, no script), failures with a link to the log.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Insights;

use DateInterval;
use DateTimeImmutable;
use Mailspur\Admin;
use Mailspur\Settings;
use const Mailspur\VERSION;

defined( 'ABSPATH' ) || exit;

final class Dashboard {

	const ID = 'mailspur_insights_widget';

	/** Fill colors per status (validated categorical palette, see assets/insights.css). */
	const COLORS = array(
		'sent'    => '#2a78d6',
		'failed'  => '#d03b3b',
		'held'    => '#4a3aa7',
		'pending' => '#eda100',
	);

	/** @var Stats */
	private $stats;

	public function __construct( Stats $stats ) {
		$this->stats = $stats;
	}

	public function setup(): void {
		if ( ! Settings::current_user_can_view() ) {
			return;
		}
		wp_add_dashboard_widget( self::ID, __( 'Emails – last 7 days', 'mailspur-email-log' ), array( $this, 'render' ) );
		add_action(
			'admin_enqueue_scripts',
			static function ( $hook ): void {
				if ( 'index.php' === $hook ) {
					wp_enqueue_style( 'mailspur-insights', plugin_dir_url( \Mailspur\FILE ) . 'assets/insights.css', array(), VERSION );
				}
			}
		);
	}

	public function render(): void {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'now', $tz );
		$from  = $today->sub( new DateInterval( 'P6D' ) )->format( 'Y-m-d' );
		$to    = $today->format( 'Y-m-d' );
		$data  = $this->stats->get( $from, $to );

		$totals = (array) $data['totals'];
		$failed = (int) $totals['failed'];
		?>
		<div class="msi-widget">
			<div class="msi-widget-kpis">
				<p><span class="msi-widget-value"><?php echo esc_html( number_format_i18n( (int) $totals['all'] ) ); ?></span> <span class="msi-widget-label"><?php esc_html_e( 'Emails', 'mailspur-email-log' ); ?></span></p>
				<p class="<?php echo esc_attr( $failed > 0 ? 'has-failures' : '' ); ?>">
					<span class="msi-widget-value"><?php echo esc_html( number_format_i18n( $failed ) ); ?></span>
					<span class="msi-widget-label">
						<?php
						/* translators: %s: failure rate in percent */
						printf( esc_html__( 'Failed (%s %%)', 'mailspur-email-log' ), esc_html( number_format_i18n( (float) $totals['rate'], 1 ) ) );
						?>
					</span>
				</p>
			</div>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in svg().
			echo $this->svg( (array) $data['days'] );
			?>
			<ul class="msi-widget-legend" aria-hidden="true">
				<?php foreach ( self::labels() as $slug => $label ) : ?>
					<li><span class="msi-swatch" style="background:<?php echo esc_attr( self::COLORS[ $slug ] ); ?>"></span><?php echo esc_html( $label ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="msi-widget-links">
				<?php if ( $failed > 0 ) : ?>
					<a href="
					<?php
					echo esc_url(
						Admin::url(
							array(
								'status' => 'failed',
								'after'  => $from,
								'before' => $to,
							)
						)
					);
					?>
								">
						<?php
						/* translators: %s: number of failed emails */
						printf( esc_html__( 'View %s failed emails', 'mailspur-email-log' ), esc_html( number_format_i18n( $failed ) ) );
						?>
					</a> |
				<?php endif; ?>
				<a href="<?php echo esc_url( Admin::url( array( 'tab' => Page::TAB ) ) ); ?>"><?php esc_html_e( 'Statistics', 'mailspur-email-log' ); ?></a> |
				<a href="<?php echo esc_url( Admin::url() ); ?>"><?php esc_html_e( 'Mail Log', 'mailspur-email-log' ); ?></a>
			</p>
		</div>
		<?php
	}

	/** @return array<string,string> */
	private static function labels(): array {
		return array(
			'sent'    => __( 'Sent', 'mailspur-email-log' ),
			'failed'  => __( 'Failed', 'mailspur-email-log' ),
			'held'    => __( 'Held', 'mailspur-email-log' ),
			'pending' => __( 'Unknown', 'mailspur-email-log' ),
		);
	}

	/**
	 * Stacked columns per day plus an equivalent table for screen readers.
	 *
	 * @param array<int,array<string,mixed>> $days
	 */
	public function svg( array $days ): string {
		$labels = self::labels();
		$width  = 480;
		$top    = 18;
		$bottom = 100;
		$band   = $width / max( 1, count( $days ) );
		$bar    = min( 24.0, $band - 8 );
		$max    = 1;
		foreach ( $days as $day ) {
			$max = max( $max, (int) $day['sent'] + (int) $day['failed'] + (int) $day['held'] + (int) $day['pending'] );
		}

		$marks = '';
		$rows  = '';
		foreach ( array_values( $days ) as $i => $day ) {
			$time  = (int) strtotime( (string) $day['date'] . ' 12:00:00 UTC' );
			$name  = (string) wp_date( 'D', $time, new \DateTimeZone( 'UTC' ) );
			$long  = (string) wp_date( (string) get_option( 'date_format' ), $time, new \DateTimeZone( 'UTC' ) );
			$x     = $i * $band + ( $band - $bar ) / 2;
			$y     = $bottom;
			$sum   = 0;
			$parts = array();
			$tip   = array( $long );
			$cells = '';
			foreach ( array_keys( $labels ) as $slug ) {
				$n      = (int) $day[ $slug ];
				$sum   += $n;
				$cells .= '<td>' . esc_html( number_format_i18n( $n ) ) . '</td>';
				if ( $n > 0 ) {
					$tip[]   = $labels[ $slug ] . ': ' . number_format_i18n( $n );
					$parts[] = array( $slug, $n );
				}
			}
			$segments = '';
			foreach ( $parts as $k => list( $slug, $n ) ) {
				$h         = max( 1.0, ( $bottom - $top ) * $n / $max - ( $k > 0 ? 2 : 0 ) );
				$y        -= $h + ( $k > 0 ? 2 : 0 );
				$last      = count( $parts ) - 1 === $k;
				$segments .= sprintf(
					'<path d="%s" fill="%s"/>',
					esc_attr( self::bar_path( $x, $y, $bar, $h, $last ? min( 4.0, $h, $bar / 2 ) : 0.0 ) ),
					esc_attr( self::COLORS[ $slug ] )
				);
			}
			$marks .= sprintf(
				'<g><title>%1$s</title><rect x="%2$s" y="%3$s" width="%4$s" height="%5$s" fill="transparent"/>%6$s<text x="%7$s" y="%8$s" text-anchor="middle" class="msi-widget-num">%9$s</text><text x="%7$s" y="116" text-anchor="middle" class="msi-widget-axis">%10$s</text></g>',
				esc_html( implode( "\n", $tip ) ),
				esc_attr( (string) round( $i * $band, 2 ) ),
				esc_attr( (string) $top ),
				esc_attr( (string) round( $band, 2 ) ),
				esc_attr( (string) ( $bottom - $top ) ),
				$segments,
				esc_attr( (string) round( $x + $bar / 2, 2 ) ),
				esc_attr( (string) round( $y - 4, 2 ) ),
				$sum > 0 ? esc_html( number_format_i18n( $sum ) ) : '',
				esc_html( $name )
			);
			$rows  .= '<tr><th scope="row">' . esc_html( $long ) . '</th>' . $cells . '</tr>';
		}

		$head = '<th scope="col">' . esc_html__( 'Date', 'mailspur-email-log' ) . '</th>';
		foreach ( $labels as $label ) {
			$head .= '<th scope="col">' . esc_html( $label ) . '</th>';
		}

		return '<svg class="msi-widget-chart" viewBox="0 0 ' . $width . ' 122" role="img" aria-hidden="true" focusable="false">'
			. '<line x1="0" x2="' . $width . '" y1="' . $bottom . '.5" y2="' . $bottom . '.5" class="msi-widget-base"/>' . $marks . '</svg>'
			. '<table class="screen-reader-text"><caption>' . esc_html__( 'Emails per day and status', 'mailspur-email-log' ) . '</caption><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	/** Column with a rounded top (data end) and a square base. */
	public static function bar_path( float $x, float $y, float $w, float $h, float $r ): string {
		$f = static function ( float $n ): string {
			return (string) round( $n, 2 );
		};
		if ( $r <= 0 ) {
			return 'M' . $f( $x ) . ' ' . $f( $y + $h ) . 'V' . $f( $y ) . 'H' . $f( $x + $w ) . 'V' . $f( $y + $h ) . 'Z';
		}
		return 'M' . $f( $x ) . ' ' . $f( $y + $h ) . 'V' . $f( $y + $r )
			. 'Q' . $f( $x ) . ' ' . $f( $y ) . ' ' . $f( $x + $r ) . ' ' . $f( $y )
			. 'H' . $f( $x + $w - $r )
			. 'Q' . $f( $x + $w ) . ' ' . $f( $y ) . ' ' . $f( $x + $w ) . ' ' . $f( $y + $r )
			. 'V' . $f( $y + $h ) . 'Z';
	}
}
