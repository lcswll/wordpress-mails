<?php
/**
 * "Overview" tab: four everyday questions with a short answer each, and the health sentence on top of the
 * dashboard widget.
 *
 * Server-rendered; only "Did my email arrive?" needs JavaScript (assets/answers.js asks POST /answers/arrived).
 * The log stays the default tab, so existing links (?page=…, ?mail=ID) keep working.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Answers;

use Mailspur\Admin;
use Mailspur\Rest;
use Mailspur\Settings;
use const Mailspur\VERSION;

defined( 'ABSPATH' ) || exit;

final class Page {

	const TAB = 'overview';

	/** @var Facts */
	private $facts;

	public function __construct( Facts $facts ) {
		$this->facts = $facts;
	}

	/**
	 * First tab, before the log.
	 *
	 * @param mixed $tabs Tabs.
	 * @return array<string,array{0:string,1:string}>
	 */
	public function tabs( $tabs ): array {
		return array_merge(
			array( self::TAB => array( __( 'Overview', 'mailspur-email-log' ), Settings::capability() ) ),
			is_array( $tabs ) ? $tabs : array()
		);
	}

	/**
	 * @param string $tab  Current tab.
	 * @param string $base Assets URL.
	 */
	public function enqueue( $tab, $base ): void {
		if ( self::TAB !== $tab ) {
			return;
		}
		wp_enqueue_style( 'mailspur-answers', $base . 'answers.css', array( 'mailspur-email-log-admin' ), VERSION );
		wp_enqueue_script(
			'mailspur-answers',
			$base . 'answers.js',
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$config = array(
			'restUrl' => esc_url_raw( rest_url( Rest::NS ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => array(
				'checking'      => __( 'Looking it up …', 'mailspur-email-log' ),
				/* translators: %s: error message */
				'requestFailed' => __( 'Request failed: %s', 'mailspur-email-log' ),
			),
		);
		wp_add_inline_script( 'mailspur-answers', 'window.mailspurAnswers = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	public function render(): void {
		if ( ! Settings::current_user_can_view() ) {
			return;
		}
		$facts = $this->facts->health();
		$links = $this->facts->links();
		$admin = current_user_can( 'manage_options' );
		$now   = $this->facts->now();
		?>
		<div class="mailspur-answers" id="mailspur-answers">
			<section class="msa-card msa-card-wide" id="msa-arrived" aria-labelledby="msa-arrived-title">
				<h2 class="msa-question" id="msa-arrived-title"><?php esc_html_e( 'Did my email arrive?', 'mailspur-email-log' ); ?></h2>
				<form class="msa-form" id="msa-arrived-form" role="search" hidden>
					<label for="msa-query" class="screen-reader-text"><?php esc_html_e( 'Email address or order number', 'mailspur-email-log' ); ?></label>
					<input type="search" id="msa-query" class="regular-text" maxlength="200" autocomplete="off" spellcheck="false" required placeholder="<?php esc_attr_e( 'Email address or order number', 'mailspur-email-log' ); ?>">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Check', 'mailspur-email-log' ); ?></button>
				</form>
				<noscript><p><?php esc_html_e( 'This question requires JavaScript. You can search the log instead.', 'mailspur-email-log' ); ?></p></noscript>
				<div id="msa-arrived-answer" aria-live="polite"></div>
			</section>

			<section class="msa-card" id="msa-health" aria-labelledby="msa-health-title">
				<h2 class="msa-question" id="msa-health-title"><?php esc_html_e( 'Is everything running?', 'mailspur-email-log' ); ?></h2>
				<?php self::render_answer( Sentences::health( $facts, $links, $admin ) ); ?>
			</section>

			<?php if ( ! empty( $facts['types'] ) ) : ?>
				<section class="msa-card" id="msa-missing" aria-labelledby="msa-missing-title">
					<h2 class="msa-question" id="msa-missing-title"><?php esc_html_e( 'Is an email missing?', 'mailspur-email-log' ); ?></h2>
					<?php self::render_answer( Sentences::missing( $facts, $links, $now ) ); ?>
				</section>
			<?php endif; ?>

			<section class="msa-card" id="msa-failure" aria-labelledby="msa-failure-title">
				<h2 class="msa-question" id="msa-failure-title"><?php esc_html_e( 'Why did an email fail?', 'mailspur-email-log' ); ?></h2>
				<?php self::render_answer( Sentences::failure( (array) $facts['failures'], $links ) ); ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Prints an answer (same markup as answers.js builds for the lookup).
	 *
	 * @param array<string,mixed> $answer Sentences::*().
	 */
	public static function render_answer( array $answer ): void {
		?>
		<div class="msa-answer is-<?php echo esc_attr( (string) $answer['tone'] ); ?>">
			<?php foreach ( (array) $answer['parts'] as $part ) : ?>
				<p class="msa-text"><?php echo esc_html( (string) $part['text'] ); ?></p>
				<?php if ( ! empty( $part['items'] ) ) : ?>
					<ul class="msa-items">
						<?php foreach ( (array) $part['items'] as $item ) : ?>
							<li>
								<?php if ( '' !== $item['url'] && '' === $item['label'] ) : ?>
									<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['text'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $item['text'] ); ?>
									<?php if ( '' !== $item['url'] ) : ?>
										<a href="<?php echo esc_url( $item['url'] ); ?>" class="msa-item-link"><?php echo esc_html( $item['label'] ); ?></a>
									<?php endif; ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php if ( ! empty( $answer['link'] ) ) : ?>
				<p class="msa-more"><a href="<?php echo esc_url( (string) $answer['link']['url'] ); ?>"><?php echo esc_html( (string) $answer['link']['label'] ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** First line of the dashboard widget (Insights): the health sentence with a link to the overview. */
	public function dashboard_line(): void {
		if ( ! Settings::current_user_can_view() ) {
			return;
		}
		try {
			$answer = Sentences::health( $this->facts->health(), $this->facts->links(), current_user_can( 'manage_options' ) );
		} catch ( \Throwable $e ) {
			return; // The widget works without it.
		}
		?>
		<p class="msa-dashboard is-<?php echo esc_attr( (string) $answer['tone'] ); ?>">
			<?php echo esc_html( Sentences::line( $answer ) ); ?>
			<a href="<?php echo esc_url( Admin::url( array( 'tab' => self::TAB ) ) ); ?>"><?php esc_html_e( 'Overview', 'mailspur-email-log' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Style of the dashboard line.
	 *
	 * @param mixed $hook Admin screen.
	 */
	public function dashboard_style( $hook ): void {
		if ( 'index.php' === $hook && Settings::current_user_can_view() ) {
			wp_enqueue_style( 'mailspur-answers-dashboard', plugin_dir_url( \Mailspur\FILE ) . 'assets/answers.css', array(), VERSION );
		}
	}
}
