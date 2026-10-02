<?php
/**
 * Asks happy users for a review on WordPress.org – politely and only when it is going well.
 *
 * - Only on Mailspur's own admin page, only for administrators, never site-wide.
 * - Only after 14 days of use, with at least 25 delivered emails and (nearly) no failures in the last 7 days.
 * - "Maybe later" snoozes for 30 days, "I already did" and the review link end it for good.
 * - Plus a credit line with a review link in the admin footer of Mailspur's page and in the plugin list.
 *
 * Direct queries: the plugin's own table, two counts, cached for a day.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 *
 * @package Mailspur
 */

namespace Mailspur;

defined( 'ABSPATH' ) || exit;

final class Review {

	const OPTION     = 'mailspur_review';
	const TRANSIENT  = 'mailspur_review_health';
	const NONCE      = 'mailspur_review';
	const URL        = 'https://wordpress.org/support/plugin/mailspur-email-log/reviews/#new-post';
	const AUTHOR_URL = 'https://lucaswille.de/';

	const MIN_DAYS   = 14;
	const MIN_SENT   = 25;
	const SNOOZE     = 30;
	const MAX_FAILED = 0.05;

	/** @var callable():int */
	private $now;

	public function __construct( ?callable $now = null ) {
		$this->now = $now ?? 'time';
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'remember_start' ) );
		add_action( 'admin_post_mailspur_review', array( $this, 'handle' ) );
		add_filter( 'plugin_row_meta', array( self::class, 'row_meta' ), 10, 2 );
		add_filter( 'allowed_redirect_hosts', array( self::class, 'redirect_hosts' ) );
		add_action( 'mailspur_admin_top', array( $this, 'maybe_render' ) );
		add_action(
			'mailspur_admin_enqueue',
			static function (): void {
				add_filter( 'admin_footer_text', array( self::class, 'footer' ) );
			}
		);
	}

	/** First admin visit after install = start of the 14 days (also for sites that update to this version). */
	public function remember_start(): void {
		if ( false === get_option( self::OPTION ) ) {
			add_option(
				self::OPTION,
				array(
					'since' => (int) call_user_func( $this->now ),
					'state' => '',
					'until' => 0,
				),
				'',
				false
			);
		}
	}

	/** @return array{since:int,state:string,until:int} */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'since' => (int) ( $state['since'] ?? 0 ),
			'state' => (string) ( $state['state'] ?? '' ),
			'until' => (int) ( $state['until'] ?? 0 ),
		);
	}

	/**
	 * Whether to ask now.
	 *
	 * @param array{since:int,state:string,until:int} $state  See state().
	 * @param array{sent:int,week:int,failed:int}     $health Delivered emails overall, emails and failures of the last 7 days.
	 */
	public static function due( array $state, array $health, int $now ): bool {
		if ( 'done' === $state['state'] || ! $state['since'] || $now < $state['since'] + self::MIN_DAYS * DAY_IN_SECONDS ) {
			return false;
		}
		if ( 'later' === $state['state'] && $now < $state['until'] ) {
			return false;
		}
		return $health['sent'] >= self::MIN_SENT
			&& $health['failed'] <= max( 0, (int) floor( $health['week'] * self::MAX_FAILED ) );
	}

	/**
	 * Delivered emails overall and emails/failures of the last 7 days (cached for a day).
	 *
	 * @return array{sent:int,week:int,failed:int}
	 */
	public function health(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['sent'], $cached['week'], $cached['failed'] ) ) {
			return array(
				'sent'   => (int) $cached['sent'],
				'week'   => (int) $cached['week'],
				'failed' => (int) $cached['failed'],
			);
		}
		global $wpdb;
		$table  = Repository::table();
		$since  = gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->now ) - 7 * DAY_IN_SECONDS );
		$week   = (array) $wpdb->get_row(
			$wpdb->prepare( 'SELECT COUNT(*) AS total, SUM( CASE WHEN status = %d THEN 1 ELSE 0 END ) AS failed FROM %i WHERE created_at >= %s', Repository::STATUS_FAILED, $table, $since ),
			ARRAY_A
		);
		$health = array(
			'sent'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %d', $table, Repository::STATUS_SENT ) ),
			'week'   => (int) ( $week['total'] ?? 0 ),
			'failed' => (int) ( $week['failed'] ?? 0 ),
		);
		set_transient( self::TRANSIENT, $health, DAY_IN_SECONDS );
		return $health;
	}

	/** The request card at the top of Mailspur's page (called by Admin::render()). */
	public function maybe_render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$now = (int) call_user_func( $this->now );
		if ( ! self::due(
			self::state(),
			array(
				'sent'   => self::MIN_SENT,
				'week'   => 0,
				'failed' => 0,
			),
			$now
		) ) {
			return; // Cheap checks first: no query unless the time has come.
		}
		$health = $this->health();
		if ( ! self::due( self::state(), $health, $now ) ) {
			return;
		}
		$action = static function ( string $choice ): string {
			return wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'mailspur_review',
						'choice' => $choice,
					),
					admin_url( 'admin-post.php' )
				),
				self::NONCE
			);
		};
		?>
		<section class="mailspur-review" aria-labelledby="mailspur-review-title">
			<div class="mailspur-review-mark" aria-hidden="true"></div>
			<div class="mailspur-review-copy">
				<h2 id="mailspur-review-title"><?php esc_html_e( 'Is Mailspur helping you?', 'mailspur-email-log' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: number of emails */
						esc_html__( 'Mailspur has logged %s delivered emails on this site so far. If it saves you time, a short review on WordPress.org would mean a lot – it helps others find the plugin and keeps it free.', 'mailspur-email-log' ),
						esc_html( number_format_i18n( $health['sent'] ) )
					);
					?>
				</p>
				<p class="mailspur-review-actions">
					<a class="button button-primary" href="<?php echo esc_url( $action( 'rate' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Rate Mailspur ★★★★★', 'mailspur-email-log' ); ?></a>
					<a class="button" href="<?php echo esc_url( $action( 'later' ) ); ?>"><?php esc_html_e( 'Maybe later', 'mailspur-email-log' ); ?></a>
					<a class="button-link" href="<?php echo esc_url( $action( 'done' ) ); ?>"><?php esc_html_e( 'I already did', 'mailspur-email-log' ); ?></a>
				</p>
			</div>
		</section>
		<?php
	}

	/** admin-post.php?action=mailspur_review&choice=rate|later|done */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'mailspur-email-log' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
		$choice = isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '';
		$state  = self::state();

		if ( 'later' === $choice ) {
			$state['state'] = 'later';
			$state['until'] = (int) call_user_func( $this->now ) + self::SNOOZE * DAY_IN_SECONDS;
		} elseif ( in_array( $choice, array( 'rate', 'done' ), true ) ) {
			$state['state'] = 'done';
		}
		update_option( self::OPTION, $state, false );

		$back = wp_get_referer();
		wp_safe_redirect( 'rate' === $choice ? self::URL : ( $back ? $back : Admin::url() ) );
		exit;
	}

	/**
	 * Footer of Mailspur's page: credit and review link (replaces "Thank you for creating with WordPress" there only).
	 *
	 * The default text is replaced, not extended.
	 */
	public static function footer(): string {
		return sprintf(
			/* translators: 1: author link, 2: review link */
			esc_html__( 'Mailspur is made by %1$s. Enjoying it? %2$s', 'mailspur-email-log' ),
			'<a href="' . esc_url( self::AUTHOR_URL ) . '" target="_blank" rel="noopener">Lucas Wille</a>',
			'<a href="' . esc_url( self::URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Leave a ★★★★★ review', 'mailspur-email-log' ) . '</a>'
		);
	}

	/**
	 * Review link under the plugin's description in the plugin list.
	 *
	 * @param mixed $meta Row meta links.
	 * @param mixed $file Plugin file.
	 * @return mixed
	 */
	public static function row_meta( $meta, $file ) {
		if ( is_array( $meta ) && plugin_basename( FILE ) === $file ) {
			$meta[] = '<a href="' . esc_url( self::URL ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr__( 'Rate Mailspur on WordPress.org', 'mailspur-email-log' ) . '">★★★★★</a>';
		}
		return $meta;
	}

	/**
	 * Lets wp_safe_redirect() send the "Rate" click to the review form.
	 *
	 * @param mixed $hosts Allowed hosts.
	 * @return mixed
	 */
	public static function redirect_hosts( $hosts ) {
		if ( is_array( $hosts ) && did_action( 'admin_post_mailspur_review' ) ) {
			$hosts[] = 'wordpress.org';
		}
		return $hosts;
	}
}
