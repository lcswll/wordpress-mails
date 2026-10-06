<?php
/**
 * Answers: the plain-language sentences for every state – did it arrive (delivered, sent, failed, held, bounced,
 * spam complaint, unknown, nothing found, invalid input), health, missing and due emails, failure reasons.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Answers\Sentences;

final class AnswersSentencesTest extends TestCase {

	/** @var int Tuesday 2026-10-06 12:00 UTC */
	private $now;

	/** @var array<string,string> */
	private $links = array(
		'log'     => 'https://site.example/log',
		'failed'  => 'https://site.example/log&status=failed',
		'held'    => 'https://site.example/log&status=held',
		'types'   => 'https://site.example/log&tab=types',
		'missing' => 'https://site.example/log&tab=overview#msa-missing',
		'staging' => 'https://site.example/settings#mailspur-staging',
		'brake'   => 'https://site.example/settings#mailspur-brake',
		'sender'  => 'https://site.example/settings#mailspur-sender-check',
	);

	protected function setUp(): void {
		parent::setUp();
		$this->now = (int) strtotime( '2026-10-06 12:00:00 UTC' );
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'number_format_i18n' => static function ( $n ) {
					return number_format( (float) $n );
				},
				'wp_date'            => static function ( $format, $timestamp = null ) {
					return gmdate( $format, $timestamp ?? time() );
				},
				'get_option'         => static function ( $name, $fallback = false ) {
					$options = array(
						'date_format' => 'j F Y',
						'time_format' => 'H:i',
					);
					return $options[ $name ] ?? $fallback;
				},
				'human_time_diff'    => static function ( $from, $to ) {
					return round( ( $to - $from ) / 86400 ) . ' days';
				},
			)
		);
	}

	/**
	 * A row as Facts::lookup() returns it.
	 *
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function row( array $extra = array() ): array {
		return array_merge(
			array(
				'id'        => 7,
				'time'      => (int) strtotime( '2026-10-03 14:02:00 UTC' ),
				'status'    => 'sent',
				'subject'   => 'Your invoice #1042',
				'recipient' => 'anna@example.com',
				'error'     => '',
				'held'      => '',
				'feedback'  => array(),
				'url'       => 'https://site.example/log&mail=7',
			),
			$extra
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed>            $extra
	 * @return array<string,mixed>
	 */
	private function lookup( array $rows, array $extra = array() ): array {
		return array_merge(
			array(
				'kind'        => 'email',
				'email'       => 'anna@example.com',
				'order'       => '',
				'shop'        => false,
				'order_found' => false,
				'rows'        => $rows,
				'problem'     => false,
				'retention'   => 0,
				'url'         => 'https://site.example/log&s=anna',
			),
			$extra
		);
	}

	/**
	 * All sentences of an answer as one string.
	 *
	 * @param array<string,mixed> $answer
	 */
	private static function text( array $answer ): string {
		$out = array();
		foreach ( $answer['parts'] as $part ) {
			$out[] = $part['text'];
			foreach ( $part['items'] as $item ) {
				$out[] = '- ' . $item['text'];
			}
		}
		return implode( "\n", $out );
	}

	/* --------------------------------------------------------------- arrived */

	public function test_delivered_names_subject_time_and_provider(): void {
		$answer = Sentences::arrived(
			$this->lookup(
				array(
					$this->row(
						array(
							'feedback' => array(
								'event' => 'delivered',
								'hard'  => false,
								'via'   => 'Postmark',
							),
						)
					),
				)
			),
			$this->now
		);
		$this->assertSame( 'ok', $answer['tone'] );
		$this->assertSame( 'Yes. “Your invoice #1042” went to anna@example.com on 3 October 2026 at 14:02 and Postmark confirmed delivery.', self::text( $answer ) );
		$this->assertSame( 'https://site.example/log&s=anna', $answer['link']['url'] );
	}

	public function test_sent_without_feedback_is_very_likely_with_spam_hint(): void {
		$answer = Sentences::arrived( $this->lookup( array( $this->row( array( 'time' => $this->now - 3600 ) ) ) ), $this->now );
		$this->assertSame( 'ok', $answer['tone'] );
		$this->assertStringStartsWith( 'Very likely. “Your invoice #1042” went out to anna@example.com today at 11:00.', self::text( $answer ) );
		$this->assertStringContainsString( 'check the spam folder', self::text( $answer ) );
	}

	public function test_yesterday_and_older_emails_are_listed_with_links(): void {
		$answer = Sentences::arrived(
			$this->lookup(
				array(
					$this->row( array( 'time' => $this->now - 86400 ) ),
					$this->row(
						array(
							'id'      => 6,
							'status'  => 'failed',
							'subject' => '',
							'url'     => 'https://site.example/log&mail=6',
						)
					),
				)
			),
			$this->now
		);
		$items  = $answer['parts'][0]['items'];
		$this->assertCount( 2, $items );
		$this->assertSame( '“Your invoice #1042” – yesterday at 12:00 – Sent', $items[0]['text'] );
		$this->assertSame( '“(no subject)” – on 3 October 2026 at 14:02 – Failed', $items[1]['text'] );
		$this->assertSame( 'https://site.example/log&mail=6', $items[1]['url'] );
		$this->assertSame( '', $items[1]['label'], 'The whole item is the link.' );
	}

	public function test_failed_because_the_mailbox_does_not_exist(): void {
		$answer = Sentences::arrived(
			$this->lookup(
				array(
					$this->row(
						array(
							'status' => 'failed',
							'error'  => 'SMTP Error: 550 5.1.1 <ben@example.com>: Recipient address rejected: User unknown',
						)
					),
				)
			),
			$this->now
		);
		$this->assertSame( 'bad', $answer['tone'] );
		$this->assertSame(
			"No. “Your invoice #1042” to anna@example.com failed on 3 October 2026 at 14:02. Reason: Mailbox unknown or unavailable.\nThe address seems invalid – check it for typos or ask for another one.",
			self::text( $answer )
		);
	}

	public function test_failed_because_of_the_mail_setup_explains_and_suggests_a_resend(): void {
		$answer = Sentences::arrived(
			$this->lookup(
				array(
					$this->row(
						array(
							'status' => 'failed',
							'error'  => 'SMTP Error: Could not authenticate.',
						)
					),
				)
			),
			$this->now
		);
		$this->assertStringContainsString( 'Reason: SMTP login failed.', self::text( $answer ) );
		$this->assertStringContainsString( 'send the email again from the log', self::text( $answer ) );
	}

	public function test_failed_with_an_unknown_or_no_message(): void {
		$answer = Sentences::arrived(
			$this->lookup(
				array(
					$this->row(
						array(
							'status' => 'failed',
							'error'  => "Something odd\nhappened",
						)
					),
				)
			),
			$this->now
		);
		$this->assertStringContainsString( 'Reason: “Something odd happened”.', self::text( $answer ) );
		$answer = Sentences::arrived( $this->lookup( array( $this->row( array( 'status' => 'failed' ) ) ) ), $this->now );
		$this->assertStringContainsString( 'Reason: unknown – the mailer gave no error message.', self::text( $answer ) );
		$this->assertSame( str_repeat( 'x', 139 ) . '…', trim( Sentences::reason( str_repeat( 'x', 300 ) ), '“”' ) );
	}

	public function test_held_names_the_reason(): void {
		$cases = array(
			'staging'             => 'staging mode is on',
			'no_redirect_address' => 'staging mode is on',
			'brake'               => 'the emergency brake stopped a flood of emails',
			'problem_recipient'   => 'emails to this address failed several times before',
			''                    => 'another plugin stopped it',
		);
		foreach ( $cases as $held => $reason ) {
			$answer = Sentences::arrived(
				$this->lookup(
					array(
						$this->row(
							array(
								'status' => 'held',
								'held'   => $held,
							)
						),
					)
				),
				$this->now
			);
			$this->assertSame( 'warn', $answer['tone'] );
			$this->assertSame(
				"No, on purpose. “Your invoice #1042” to anna@example.com was held on 3 October 2026 at 14:02 and not sent. Reason: {$reason}.\nYou can still send it from the log (“Send now”).",
				self::text( $answer ),
				$held
			);
		}
	}

	public function test_provider_reports_bounces_and_complaints(): void {
		$feedback = static function ( string $event, bool $hard ): array {
			return array(
				'feedback' => array(
					'event' => $event,
					'hard'  => $hard,
					'via'   => '',
				),
			);
		};
		$hard     = Sentences::arrived( $this->lookup( array( $this->row( $feedback( 'bounced', true ) ) ) ), $this->now );
		$this->assertSame( 'bad', $hard['tone'] );
		$this->assertStringStartsWith( 'No. “Your invoice #1042” to anna@example.com left your site on 3 October 2026 at 14:02, but your email provider reports that the receiving server rejected it.', self::text( $hard ) );

		$soft = Sentences::arrived( $this->lookup( array( $this->row( $feedback( 'bounced', false ) ) ) ), $this->now );
		$this->assertSame( 'warn', $soft['tone'] );
		$this->assertStringStartsWith( 'Not yet.', self::text( $soft ) );
		$this->assertStringContainsString( 'later try', self::text( $soft ) );

		$spam = Sentences::arrived( $this->lookup( array( $this->row( $feedback( 'complaint', false ) ) ) ), $this->now );
		$this->assertSame( 'warn', $spam['tone'] );
		$this->assertStringStartsWith( 'Yes, but “Your invoice #1042” to anna@example.com (on 3 October 2026 at 14:02) was marked as spam', self::text( $spam ) );
	}

	public function test_pending_is_unclear(): void {
		$answer = Sentences::arrived( $this->lookup( array( $this->row( array( 'status' => 'pending' ) ) ) ), $this->now );
		$this->assertSame( 'warn', $answer['tone'] );
		$this->assertStringStartsWith( 'Unclear.', self::text( $answer ) );
		$this->assertCount( 1, $answer['parts'], 'No hint.' );
	}

	public function test_problem_recipient_gets_a_warning_unless_it_already_failed(): void {
		$answer = Sentences::arrived( $this->lookup( array( $this->row() ), array( 'problem' => true ) ), $this->now );
		$this->assertStringContainsString( 'Careful: emails to this address failed several times before.', self::text( $answer ) );
		$answer = Sentences::arrived(
			$this->lookup(
				array(
					$this->row(
						array(
							'status' => 'failed',
							'error'  => '550 5.1.1 user unknown',
						)
					),
				),
				array( 'problem' => true )
			),
			$this->now
		);
		$this->assertStringNotContainsString( 'Careful', self::text( $answer ) );
	}

	public function test_nothing_found(): void {
		$answer = Sentences::arrived(
			$this->lookup(
				array(),
				array(
					'retention' => 30,
					'url'       => '',
				)
			),
			$this->now
		);
		$this->assertSame( 'info', $answer['tone'] );
		$this->assertSame( 'Mailspur has no email to anna@example.com in the log. Check the address for typos – or the email was never sent by your site. The log keeps emails for 30 days.', self::text( $answer ) );
		$this->assertNull( $answer['link'] );

		$answer = Sentences::arrived(
			$this->lookup(
				array(),
				array(
					'retention' => 1,
					'kind'      => 'order',
					'order'     => '1042',
				)
			),
			$this->now
		);
		$this->assertStringStartsWith( 'Mailspur has no email for order #1042 in the log.', self::text( $answer ) );
		$this->assertStringEndsWith( 'The log keeps emails for 1 day.', self::text( $answer ) );
	}

	public function test_order_numbers_and_invalid_input(): void {
		$answer = Sentences::arrived(
			$this->lookup(
				array(),
				array(
					'kind'  => 'order',
					'order' => '99',
					'shop'  => true,
				)
			),
			$this->now
		);
		$this->assertSame( 'Mailspur found no order #99 in your shop.', self::text( $answer ) );

		$answer = Sentences::arrived(
			$this->lookup(
				array( $this->row() ),
				array(
					'kind'        => 'order',
					'order'       => '1042',
					'shop'        => true,
					'order_found' => true,
				)
			),
			$this->now
		);
		$this->assertStringStartsWith( 'Very likely. “Your invoice #1042” went out to anna@example.com', self::text( $answer ) );

		$answer = Sentences::arrived( $this->lookup( array(), array( 'kind' => 'invalid' ) ), $this->now );
		$this->assertSame( 'Enter an email address or an order number.', self::text( $answer ) );
	}

	/* ---------------------------------------------------------------- health */

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function facts( array $extra = array() ): array {
		return array_merge(
			array(
				'week'       => array(
					'total'   => 214,
					'sent'    => 214,
					'failed'  => 0,
					'held'    => 0,
					'pending' => 0,
				),
				'day_failed' => 0,
				'staging'    => 'off',
				'brake'      => array(
					'active' => false,
					'held'   => 0,
				),
				'stopped'    => array(),
				'due'        => array(),
				'scheduled'  => array(),
				'regular'    => 3,
				'sender'     => array(),
				'types'      => true,
			),
			$extra
		);
	}

	public function test_health_everything_fine(): void {
		$answer = Sentences::health( $this->facts(), $this->links, true );
		$this->assertSame( 'ok', $answer['tone'] );
		$this->assertSame( 'Everything looks fine: 214 emails in the last 7 days, none failed.', self::text( $answer ) );
		$this->assertSame( 'https://site.example/log', $answer['link']['url'] );

		$one                  = $this->facts();
		$one['week']['total'] = 1;
		$this->assertSame( 'Everything looks fine: 1 email in the last 7 days, without errors.', self::text( Sentences::health( $one, $this->links, true ) ) );

		$none                  = $this->facts();
		$none['week']['total'] = 0;
		$answer                = Sentences::health( $none, $this->links, true );
		$this->assertSame( 'info', $answer['tone'] );
		$this->assertSame( 'Your site sent no emails in the last 7 days.', self::text( $answer ) );
	}

	public function test_health_lists_what_needs_attention_with_links(): void {
		$facts                   = $this->facts(
			array(
				'day_failed' => 1,
				'brake'      => array(
					'active' => true,
					'held'   => 12,
				),
				'stopped'    => array(
					array( 'name' => 'Subscription reminder' ),
					array( 'name' => 'New order #…' ),
					array( 'name' => 'Third' ),
				),
				'sender'     => array( 'example.com' ),
			)
		);
		$facts['week']['failed'] = 3;
		$facts['week']['held']   = 12;

		$answer = Sentences::health( $facts, $this->links, true );
		$this->assertSame( 'warn', $answer['tone'] );
		$this->assertSame(
			"4 things need attention:\n"
			. "- The emergency brake is on: your site suddenly sent far more emails than usual. 12 emails are waiting for your decision.\n"
			. "- 3 emails failed in the last 7 days. 1 of them in the last 24 hours.\n"
			. "- 3 email types have stopped, e.g. “Subscription reminder”, “New order #…”.\n"
			. '- The sender check found a problem with example.com. Emails may land in spam.',
			self::text( $answer )
		);
		$items = $answer['parts'][0]['items'];
		$this->assertSame( array( $this->links['brake'], 'Open the emergency brake' ), array( $items[0]['url'], $items[0]['label'] ) );
		$this->assertSame( $this->links['failed'], $items[1]['url'] );
		$this->assertSame( $this->links['missing'], $items[2]['url'] );
		$this->assertSame( $this->links['sender'], $items[3]['url'] );
		$this->assertNull( $answer['link'] );

		// Without access to the settings: no sender check, the brake links to the held emails.
		$answer = Sentences::health( $facts, $this->links, false );
		$this->assertCount( 3, $answer['parts'][0]['items'] );
		$this->assertSame( array( $this->links['held'], 'Show held emails' ), array( $answer['parts'][0]['items'][0]['url'], $answer['parts'][0]['items'][0]['label'] ) );
	}

	public function test_health_staging_mode_and_held_emails(): void {
		$facts                 = $this->facts( array( 'staging' => 'hold' ) );
		$facts['week']['held'] = 5;
		$answer                = Sentences::health( $facts, $this->links, true );
		$this->assertSame( "1 thing needs attention:\n- Staging mode is on: emails are logged but not sent.", self::text( $answer ), 'Held emails are expected in staging mode.' );
		$this->assertSame( $this->links['staging'], $answer['parts'][0]['items'][0]['url'] );

		$facts['staging'] = 'redirect';
		$this->assertStringContainsString( 'go to your test addresses', self::text( Sentences::health( $facts, $this->links, false ) ) );
		$this->assertSame( '', Sentences::health( $facts, $this->links, false )['parts'][0]['items'][0]['url'] );

		$facts['staging'] = 'off';
		$answer           = Sentences::health( $facts, $this->links, true );
		$this->assertSame( "1 thing needs attention:\n- 5 emails were held and not sent in the last 7 days.", self::text( $answer ) );
		$this->assertSame( $this->links['held'], $answer['parts'][0]['items'][0]['url'] );
	}

	public function test_health_single_items_use_the_singular(): void {
		$facts                   = $this->facts( array( 'stopped' => array( array( 'name' => 'Subscription reminder' ) ) ) );
		$facts['week']['failed'] = 1;
		$facts['day_failed']     = 1;
		$answer                  = Sentences::health( $facts, $this->links, true );
		$this->assertSame( "2 things need attention:\n- 1 email failed in the last 7 days.\n- 1 email type has stopped: “Subscription reminder”.", self::text( $answer ) );
		$this->assertSame( '2 things need attention: 1 email failed in the last 7 days. 1 email type has stopped: “Subscription reminder”.', Sentences::line( $answer ) );
		$this->assertSame( 'Everything looks fine: 214 emails in the last 7 days, none failed.', Sentences::line( Sentences::health( $this->facts(), $this->links, true ) ) );
	}

	/* --------------------------------------------------------------- missing */

	public function test_missing_names_stopped_types_with_cause(): void {
		$facts  = $this->facts(
			array(
				'stopped' => array(
					array(
						'name'      => 'Subscription reminder',
						'source'    => 'WooCommerce',
						'last_seen' => $this->now - 4 * 86400,
						'expected'  => 2,
						'cause'     => 'Sent by the cron event wcs_reminder – this event is no longer scheduled.',
					),
				),
			)
		);
		$answer = Sentences::missing( $facts, $this->links, $this->now );
		$this->assertSame( 'warn', $answer['tone'] );
		$this->assertSame(
			"Probably yes: 1 email type has stopped.\n- “Subscription reminder” (WooCommerce) usually goes out at least every 2 days. The last one was sent 4 days ago. Sent by the cron event wcs_reminder – this event is no longer scheduled.\nNothing else is expected today.",
			self::text( $answer )
		);
		$this->assertSame( $this->links['types'], $answer['link']['url'] );
	}

	public function test_missing_nothing_or_no_rhythm_yet(): void {
		$answer = Sentences::missing( $this->facts(), $this->links, $this->now );
		$this->assertSame( 'ok', $answer['tone'] );
		$this->assertSame( "No. Every email that goes out regularly arrived on schedule.\nNothing else is expected today.", self::text( $answer ) );

		$answer = Sentences::missing( $this->facts( array( 'regular' => 0 ) ), $this->links, $this->now );
		$this->assertSame( 'info', $answer['tone'] );
		$this->assertSame( 'Nothing to compare yet: no email goes out regularly enough for Mailspur to notice a gap.', self::text( $answer ) );
	}

	public function test_due_today_and_scheduled_later(): void {
		$yesterday = (int) strtotime( '2026-10-05 09:02:00 UTC' );
		$facts     = $this->facts(
			array(
				'due'       => array(
					array(
						'name'      => 'Subscription reminder',
						'source'    => 'WooCommerce',
						'last_seen' => $yesterday,
						'daily'     => true,
						'weekday'   => 'Tuesday',
						'next'      => 0,
					),
					array(
						'name'      => 'Team digest',
						'source'    => 'My Plugin',
						'last_seen' => (int) strtotime( '2026-09-29 07:30:00 UTC' ),
						'daily'     => false,
						'weekday'   => 'Tuesday',
						'next'      => (int) strtotime( '2026-10-06 18:00:00 UTC' ),
					),
				),
				'scheduled' => array(
					array(
						'name'      => 'Weekly report',
						'source'    => 'Mailspur',
						'last_seen' => $yesterday,
						'next'      => (int) strtotime( '2026-10-06 20:15:00 UTC' ),
					),
				),
			)
		);
		$answer    = Sentences::missing( $facts, $this->links, $this->now );
		$this->assertSame( 'ok', $answer['tone'], 'Due emails are no alarm.' );
		$this->assertSame(
			"No. Every email that goes out regularly arrived on schedule.\n"
			. "Expected today and not sent yet:\n"
			. "- “Subscription reminder” (WooCommerce) – usually every day, last one yesterday at 09:02\n"
			. "- “Team digest” (My Plugin) – usually every Tuesday, last one on 29 September 2026 at 07:30 (scheduled for 18:00)\n"
			. "Scheduled for later today:\n"
			. '- “Weekly report” (Mailspur) at 20:15',
			self::text( $answer )
		);
	}

	/* --------------------------------------------------------------- failure */

	public function test_failure_reasons(): void {
		$answer = Sentences::failure( array( 'total' => 0 ), $this->links );
		$this->assertSame( 'ok', $answer['tone'] );
		$this->assertSame( 'Nothing failed in the last 7 days.', self::text( $answer ) );
		$this->assertNull( $answer['link'] );

		$answer = Sentences::failure(
			array(
				'total' => 4,
				'count' => 3,
				'error' => 'SMTP Error: Could not authenticate.',
			),
			$this->links
		);
		$this->assertSame( 'bad', $answer['tone'] );
		$text = self::text( $answer );
		$this->assertStringStartsWith( "4 emails failed in the last 7 days.\nMost common reason (3 of them): SMTP login failed.\nThe SMTP server rejected the user name or password", $text );
		$this->assertStringContainsString( 'Check host, port, encryption', $text, 'First step of the explanation.' );
		$this->assertSame( $this->links['failed'], $answer['link']['url'] );

		$answer = Sentences::failure(
			array(
				'total' => 1,
				'count' => 1,
				'error' => 'Mystery error 42',
			),
			$this->links
		);
		$this->assertSame( "1 email failed in the last 7 days.\nThe reason: “Mystery error 42”.", self::text( $answer ) );
	}
}
