<?php
/**
 * The answers in plain language: short sentences for site owners, composed from facts (see Facts). Pure apart
 * from translation, number and date formatting – so every variant is covered by unit tests.
 *
 * Every answer has the same shape: a tone (ok, info, warn, bad), one or more parts (a sentence plus optional
 * list items, each item with an optional link) and at most one link to the place with the details.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Answers;

use Mailspur\Modules\Delivery\Problems;
use Mailspur\Modules\Notes\Explainer;

defined( 'ABSPATH' ) || exit;

final class Sentences {

	/** Items listed per part at most (stopped types, emails due today …). */
	const MAX_ITEMS = 3;

	/** Longest error message quoted (characters). */
	const MAX_ERROR = 140;

	/* ------------------------------------------------------- did it arrive? */

	/**
	 * "Did my email arrive?"
	 *
	 * @param array<string,mixed> $lookup Facts::lookup().
	 * @return array<string,mixed> Answer.
	 */
	public static function arrived( array $lookup, int $now ): array {
		$kind  = (string) ( $lookup['kind'] ?? 'invalid' );
		$rows  = array_values( (array) ( $lookup['rows'] ?? array() ) );
		$email = (string) ( $lookup['email'] ?? '' );
		$order = (string) ( $lookup['order'] ?? '' );
		$link  = '' !== (string) ( $lookup['url'] ?? '' ) ? array(
			'url'   => (string) $lookup['url'],
			'label' => __( 'Show these emails in the log', 'mailspur-email-log' ),
		) : null;

		if ( 'invalid' === $kind ) {
			return self::answer( 'info', array( self::part( __( 'Enter an email address or an order number.', 'mailspur-email-log' ) ) ) );
		}
		if ( 'order' === $kind && empty( $lookup['order_found'] ) && ! empty( $lookup['shop'] ) ) {
			/* translators: %s: order number */
			return self::answer( 'info', array( self::part( sprintf( __( 'Mailspur found no order #%s in your shop.', 'mailspur-email-log' ), $order ) ) ) );
		}
		if ( ! $rows ) {
			if ( 'email' === $kind ) {
				/* translators: %s: email address */
				$text = sprintf( __( 'Mailspur has no email to %s in the log.', 'mailspur-email-log' ), $email );
				$hint = __( 'Check the address for typos – or the email was never sent by your site.', 'mailspur-email-log' );
			} else {
				/* translators: %s: order number */
				$text = sprintf( __( 'Mailspur has no email for order #%s in the log.', 'mailspur-email-log' ), $order );
				$hint = __( 'Maybe the email was never sent – check the order status and the settings of your shop’s emails.', 'mailspur-email-log' );
			}
			$retention = (int) ( $lookup['retention'] ?? 0 );
			if ( $retention > 0 ) {
				/* translators: %s: number of days */
				$hint .= ' ' . sprintf( _n( 'The log keeps emails for %s day.', 'The log keeps emails for %s days.', $retention, 'mailspur-email-log' ), number_format_i18n( $retention ) );
			}
			return self::answer( 'info', array( self::part( $text . ' ' . $hint ) ) );
		}

		$row     = (array) $rows[0];
		$subject = '' !== (string) $row['subject'] ? (string) $row['subject'] : __( '(no subject)', 'mailspur-email-log' );
		$to      = (string) $row['recipient'];
		$when    = self::when( (int) $row['time'], $now );
		$main    = self::latest( $row, $subject, $to, $when );
		$text    = $main['text'];
		if ( ! empty( $lookup['problem'] ) && 'bad' !== $main['tone'] ) {
			$text .= ' ' . __( 'Careful: emails to this address failed several times before.', 'mailspur-email-log' );
		}

		$items = array();
		if ( count( $rows ) > 1 ) {
			foreach ( array_slice( $rows, 0, self::MAX_ITEMS ) as $other ) {
				$other   = (array) $other;
				$items[] = array(
					'text'  => sprintf(
						/* translators: 1: email subject, 2: date and time, e.g. "today at 14:02", 3: status, e.g. "Sent" */
						__( '“%1$s” – %2$s – %3$s', 'mailspur-email-log' ),
						'' !== (string) $other['subject'] ? (string) $other['subject'] : __( '(no subject)', 'mailspur-email-log' ),
						self::when( (int) $other['time'], $now ),
						self::status_label( (string) $other['status'] )
					),
					'url'   => (string) ( $other['url'] ?? '' ),
					'label' => '',
				);
			}
		}

		$parts = array( self::part( $text, $items ) );
		if ( '' !== $main['hint'] ) {
			$parts[] = self::part( $main['hint'] );
		}
		return self::answer( $main['tone'], $parts, $link );
	}

	/**
	 * Sentence about the latest email.
	 *
	 * @param array<string,mixed> $row Facts row.
	 * @return array{tone:string,text:string,hint:string}
	 */
	private static function latest( array $row, string $subject, string $to, string $when ): array {
		$feedback = (array) ( $row['feedback'] ?? array() );
		$event    = (string) ( $feedback['event'] ?? '' );
		$via      = (string) ( $feedback['via'] ?? '' );
		$via      = '' !== $via ? $via : __( 'your email provider', 'mailspur-email-log' );

		switch ( (string) $row['status'] ) {
			case 'failed':
				return array(
					'tone' => 'bad',
					'text' => sprintf(
						/* translators: 1: email subject, 2: email address, 3: date and time, e.g. "today at 14:02", 4: reason */
						__( 'No. “%1$s” to %2$s failed %3$s. Reason: %4$s.', 'mailspur-email-log' ),
						$subject,
						$to,
						$when,
						self::reason( (string) $row['error'] )
					),
					'hint' => self::recipient_problem( (string) $row['error'] )
						? __( 'The address seems invalid – check it for typos or ask for another one.', 'mailspur-email-log' )
						: __( 'Once the cause is fixed, you can send the email again from the log.', 'mailspur-email-log' ),
				);
			case 'held':
				return array(
					'tone' => 'warn',
					'text' => sprintf(
						/* translators: 1: email subject, 2: email address, 3: date and time, e.g. "today at 14:02", 4: reason */
						__( 'No, on purpose. “%1$s” to %2$s was held %3$s and not sent. Reason: %4$s.', 'mailspur-email-log' ),
						$subject,
						$to,
						$when,
						self::held_reason( (string) ( $row['held'] ?? '' ) )
					),
					'hint' => __( 'You can still send it from the log (“Send now”).', 'mailspur-email-log' ),
				);
			case 'pending':
				return array(
					'tone' => 'warn',
					'text' => sprintf(
						/* translators: 1: email subject, 2: email address, 3: date and time, e.g. "today at 14:02" */
						__( 'Unclear. “%1$s” to %2$s was handed over for sending %3$s, but Mailspur got no result back.', 'mailspur-email-log' ),
						$subject,
						$to,
						$when
					),
					'hint' => '',
				);
		}

		$args = array( $subject, $to, $when, $via );
		switch ( $event ) {
			case 'delivered':
				return array(
					'tone' => 'ok',
					/* translators: 1: email subject, 2: email address, 3: date and time, e.g. "today at 14:02", 4: name of the email provider, e.g. "Postmark" */
					'text' => vsprintf( __( 'Yes. “%1$s” went to %2$s %3$s and %4$s confirmed delivery.', 'mailspur-email-log' ), $args ),
					'hint' => '',
				);
			case 'complaint':
				return array(
					'tone' => 'warn',
					/* translators: 1: email subject, 2: email address, 3: date and time, e.g. "today at 14:02", 4: name of the email provider, e.g. "Postmark" */
					'text' => vsprintf( __( 'Yes, but “%1$s” to %2$s (%3$s) was marked as spam by the recipient, says %4$s.', 'mailspur-email-log' ), $args ),
					'hint' => '',
				);
			case 'bounced':
				if ( empty( $feedback['hard'] ) ) {
					return array(
						'tone' => 'warn',
						/* translators: 1: email subject, 2: email address, 3: date and time, e.g. "today at 14:02", 4: name of the email provider, e.g. "Postmark" */
						'text' => vsprintf( __( 'Not yet. “%1$s” to %2$s left your site %3$s, but %4$s reports a temporary problem.', 'mailspur-email-log' ), $args ),
						'hint' => __( 'The receiving server may still accept it on a later try.', 'mailspur-email-log' ),
					);
				}
				return array(
					'tone' => 'bad',
					/* translators: 1: email subject, 2: email address, 3: date and time, e.g. "today at 14:02", 4: name of the email provider, e.g. "Postmark" */
					'text' => vsprintf( __( 'No. “%1$s” to %2$s left your site %3$s, but %4$s reports that the receiving server rejected it.', 'mailspur-email-log' ), $args ),
					'hint' => __( 'The address seems invalid – check it for typos or ask for another one.', 'mailspur-email-log' ),
				);
		}
		return array(
			'tone' => 'ok',
			'text' => sprintf(
				/* translators: 1: email subject, 2: email address, 3: date and time, e.g. "today at 14:02" */
				__( 'Very likely. “%1$s” went out to %2$s %3$s.', 'mailspur-email-log' ),
				$subject,
				$to,
				$when
			),
			'hint' => __( 'Mailspur sees that your site sent it, not the inbox itself. If it is not there, ask the recipient to check the spam folder.', 'mailspur-email-log' ),
		);
	}

	/** Why an email failed, in a few words. */
	public static function reason( string $error ): string {
		if ( class_exists( Problems::class ) ) {
			$why = Problems::classify( $error );
			if ( '' !== $why ) {
				return Problems::reason_label( $why );
			}
		}
		if ( class_exists( Explainer::class ) ) {
			$title = Explainer::title( $error );
			if ( '' !== $title ) {
				return $title;
			}
		}
		$error = trim( (string) preg_replace( '/\s+/', ' ', $error ) );
		if ( '' === $error ) {
			return __( 'unknown – the mailer gave no error message', 'mailspur-email-log' );
		}
		return '“' . self::cut( $error ) . '”';
	}

	/** The receiving server rejected the address itself (not the sender, not a temporary problem). */
	private static function recipient_problem( string $error ): bool {
		return class_exists( Problems::class ) && '' !== Problems::classify( $error );
	}

	private static function held_reason( string $held ): string {
		switch ( $held ) {
			case 'staging':
			case 'no_redirect_address':
				return __( 'staging mode is on', 'mailspur-email-log' );
			case 'brake':
				return __( 'the emergency brake stopped a flood of emails', 'mailspur-email-log' );
			case 'problem_recipient':
				return __( 'emails to this address failed several times before', 'mailspur-email-log' );
		}
		return __( 'another plugin stopped it', 'mailspur-email-log' );
	}

	private static function status_label( string $status ): string {
		switch ( $status ) {
			case 'sent':
				return __( 'Sent', 'mailspur-email-log' );
			case 'failed':
				return __( 'Failed', 'mailspur-email-log' );
			case 'held':
				return __( 'Held', 'mailspur-email-log' );
		}
		return __( 'Unknown', 'mailspur-email-log' );
	}

	/* ------------------------------------------------------ is it running? */

	/**
	 * "Is everything running?"
	 *
	 * @param array<string,mixed>  $facts Facts::health().
	 * @param array<string,string> $links Facts::links().
	 * @param bool                 $admin The user may open the settings.
	 * @return array<string,mixed> Answer.
	 */
	public static function health( array $facts, array $links, bool $admin ): array {
		$week    = (array) ( $facts['week'] ?? array() );
		$total   = (int) ( $week['total'] ?? 0 );
		$failed  = (int) ( $week['failed'] ?? 0 );
		$held    = (int) ( $week['held'] ?? 0 );
		$staging = (string) ( $facts['staging'] ?? 'off' );
		$brake   = (array) ( $facts['brake'] ?? array() );
		$stopped = (array) ( $facts['stopped'] ?? array() );
		$issues  = array();

		if ( ! empty( $brake['active'] ) ) {
			$text = __( 'The emergency brake is on: your site suddenly sent far more emails than usual.', 'mailspur-email-log' );
			if ( (int) ( $brake['held'] ?? 0 ) > 0 ) {
				$text .= ' ' . sprintf(
					/* translators: %s: number of emails */
					_n( '%s email is waiting for your decision.', '%s emails are waiting for your decision.', (int) $brake['held'], 'mailspur-email-log' ),
					number_format_i18n( (int) $brake['held'] )
				);
			}
			$issues[] = self::item( $text, $admin ? $links['brake'] : $links['held'], $admin ? __( 'Open the emergency brake', 'mailspur-email-log' ) : __( 'Show held emails', 'mailspur-email-log' ) );
		}
		if ( 'off' !== $staging ) {
			$issues[] = self::item(
				'redirect' === $staging
					? __( 'Staging mode is on: all emails go to your test addresses, not to the real recipients.', 'mailspur-email-log' )
					: __( 'Staging mode is on: emails are logged but not sent.', 'mailspur-email-log' ),
				$admin ? $links['staging'] : '',
				__( 'Open staging mode', 'mailspur-email-log' )
			);
		}
		if ( $failed > 0 ) {
			$text = sprintf(
				/* translators: %s: number of emails */
				_n( '%s email failed in the last 7 days.', '%s emails failed in the last 7 days.', $failed, 'mailspur-email-log' ),
				number_format_i18n( $failed )
			);
			$recent = (int) ( $facts['day_failed'] ?? 0 );
			if ( $recent > 0 && $recent < $failed ) {
				/* translators: %s: number of emails */
				$text .= ' ' . sprintf( __( '%s of them in the last 24 hours.', 'mailspur-email-log' ), number_format_i18n( $recent ) );
			}
			$issues[] = self::item( $text, $links['failed'], __( 'Show failed emails', 'mailspur-email-log' ) );
		}
		if ( $held > 0 && 'off' === $staging && empty( $brake['active'] ) ) {
			$issues[] = self::item(
				sprintf(
					/* translators: %s: number of emails */
					_n( '%s email was held and not sent in the last 7 days.', '%s emails were held and not sent in the last 7 days.', $held, 'mailspur-email-log' ),
					number_format_i18n( $held )
				),
				$links['held'],
				__( 'Show held emails', 'mailspur-email-log' )
			);
		}
		if ( $stopped ) {
			$names    = array_map(
				static function ( $type ): string {
					return '“' . (string) ( (array) $type )['name'] . '”';
				},
				array_slice( $stopped, 0, 2 )
			);
			$issues[] = self::item(
				sprintf(
					/* translators: 1: number of email types, 2: their names, e.g. "“New order #…”" */
					_n( '%1$s email type has stopped: %2$s.', '%1$s email types have stopped, e.g. %2$s.', count( $stopped ), 'mailspur-email-log' ),
					number_format_i18n( count( $stopped ) ),
					implode( ', ', $names )
				),
				$links['missing'],
				__( 'See why', 'mailspur-email-log' )
			);
		}
		$sender = (array) ( $facts['sender'] ?? array() );
		if ( $admin && $sender ) {
			$issues[] = self::item(
				/* translators: %s: comma-separated domain names */
				sprintf( __( 'The sender check found a problem with %s. Emails may land in spam.', 'mailspur-email-log' ), implode( ', ', array_map( 'strval', $sender ) ) ),
				$links['sender'],
				__( 'Open the sender check', 'mailspur-email-log' )
			);
		}

		if ( $issues ) {
			return self::answer(
				'warn',
				array(
					self::part(
						/* translators: %s: number of things */
						sprintf( _n( '%s thing needs attention:', '%s things need attention:', count( $issues ), 'mailspur-email-log' ), number_format_i18n( count( $issues ) ) ),
						$issues
					),
				)
			);
		}
		if ( 0 === $total ) {
			return self::answer( 'info', array( self::part( __( 'Your site sent no emails in the last 7 days.', 'mailspur-email-log' ) ) ), self::link( $links['log'], __( 'Open the log', 'mailspur-email-log' ) ) );
		}
		return self::answer(
			'ok',
			array(
				self::part(
					sprintf(
						/* translators: %s: number of emails */
						_n( 'Everything looks fine: %s email in the last 7 days, without errors.', 'Everything looks fine: %s emails in the last 7 days, none failed.', $total, 'mailspur-email-log' ),
						number_format_i18n( $total )
					)
				),
			),
			self::link( $links['log'], __( 'Open the log', 'mailspur-email-log' ) )
		);
	}

	/**
	 * The health answer as one line (dashboard widget): the first sentence plus the items.
	 *
	 * @param array<string,mixed> $answer health().
	 */
	public static function line( array $answer ): string {
		$part  = (array) ( $answer['parts'][0] ?? array() );
		$texts = array( (string) ( $part['text'] ?? '' ) );
		foreach ( (array) ( $part['items'] ?? array() ) as $item ) {
			$texts[] = (string) ( (array) $item )['text'];
		}
		return implode( ' ', $texts );
	}

	/* --------------------------------------------------- is one missing? */

	/**
	 * "Is an email missing?" and "What is still due today?"
	 *
	 * @param array<string,mixed>  $facts Facts::health() (types part).
	 * @param array<string,string> $links Facts::links().
	 * @return array<string,mixed> Answer.
	 */
	public static function missing( array $facts, array $links, int $now ): array {
		$stopped   = array_values( (array) ( $facts['stopped'] ?? array() ) );
		$due       = array_values( (array) ( $facts['due'] ?? array() ) );
		$scheduled = array_values( (array) ( $facts['scheduled'] ?? array() ) );
		$regular   = (int) ( $facts['regular'] ?? 0 );
		$parts     = array();
		$tone      = 'ok';

		if ( $stopped ) {
			$tone  = 'warn';
			$items = array();
			foreach ( array_slice( $stopped, 0, self::MAX_ITEMS ) as $type ) {
				$type = (array) $type;
				$text = sprintf(
					/* translators: 1: email type, e.g. "New order #…", 2: plugin name, 3: number of days, 4: time span, e.g. "3 days" */
					__( '“%1$s” (%2$s) usually goes out at least every %3$s days. The last one was sent %4$s ago.', 'mailspur-email-log' ),
					(string) $type['name'],
					(string) $type['source'],
					number_format_i18n( (int) $type['expected'] ),
					human_time_diff( (int) $type['last_seen'], $now )
				);
				if ( '' !== (string) ( $type['cause'] ?? '' ) ) {
					$text .= ' ' . (string) $type['cause'];
				}
				$items[] = self::item( $text );
			}
			$parts[] = self::part(
				sprintf(
					/* translators: %s: number of email types */
					_n( 'Probably yes: %s email type has stopped.', 'Probably yes: %s email types have stopped.', count( $stopped ), 'mailspur-email-log' ),
					number_format_i18n( count( $stopped ) )
				),
				$items
			);
		} elseif ( $regular > 0 ) {
			$parts[] = self::part( __( 'No. Every email that goes out regularly arrived on schedule.', 'mailspur-email-log' ) );
		} else {
			$tone    = 'info';
			$parts[] = self::part( __( 'Nothing to compare yet: no email goes out regularly enough for Mailspur to notice a gap.', 'mailspur-email-log' ) );
		}

		if ( $due ) {
			$items = array();
			foreach ( array_slice( $due, 0, self::MAX_ITEMS ) as $type ) {
				$type = (array) $type;
				$text = ! empty( $type['daily'] )
					/* translators: 1: email type, 2: plugin name, 3: date and time of the last one, e.g. "yesterday at 09:02" */
					? __( '“%1$s” (%2$s) – usually every day, last one %3$s', 'mailspur-email-log' )
					/* translators: 1: email type, 2: plugin name, 3: date and time of the last one, e.g. "on 29 September at 09:02", 4: weekday, e.g. "Monday" */
					: __( '“%1$s” (%2$s) – usually every %4$s, last one %3$s', 'mailspur-email-log' );
				$text    = sprintf( $text, (string) $type['name'], (string) $type['source'], self::when( (int) $type['last_seen'], $now ), (string) ( $type['weekday'] ?? '' ) );
				$items[] = self::item( self::with_run( $text, (int) ( $type['next'] ?? 0 ) ) );
			}
			$parts[] = self::part( __( 'Expected today and not sent yet:', 'mailspur-email-log' ), $items );
		}
		if ( $scheduled ) {
			$items = array();
			foreach ( array_slice( $scheduled, 0, self::MAX_ITEMS ) as $type ) {
				$type    = (array) $type;
				$items[] = self::item(
					sprintf(
						/* translators: 1: email type, 2: plugin name, 3: time, e.g. "18:00" */
						__( '“%1$s” (%2$s) at %3$s', 'mailspur-email-log' ),
						(string) $type['name'],
						(string) $type['source'],
						self::clock( (int) $type['next'] )
					)
				);
			}
			$parts[] = self::part( __( 'Scheduled for later today:', 'mailspur-email-log' ), $items );
		}
		if ( ! $due && ! $scheduled && $regular > 0 ) {
			$parts[] = self::part( __( 'Nothing else is expected today.', 'mailspur-email-log' ) );
		}

		return self::answer( $tone, $parts, self::link( $links['types'], __( 'Show all email types', 'mailspur-email-log' ) ) );
	}

	private static function with_run( string $text, int $next ): string {
		if ( $next <= 0 ) {
			return $text;
		}
		/* translators: 1: description of an email type, 2: time, e.g. "18:00" */
		return sprintf( __( '%1$s (scheduled for %2$s)', 'mailspur-email-log' ), $text, self::clock( $next ) );
	}

	/* ------------------------------------------------------- why failed? */

	/**
	 * "Why did an email fail?"
	 *
	 * @param array<string,mixed>  $failures Facts::health()['failures']: total and the most common error.
	 * @param array<string,string> $links    Facts::links().
	 * @return array<string,mixed> Answer.
	 */
	public static function failure( array $failures, array $links ): array {
		$total = (int) ( $failures['total'] ?? 0 );
		if ( $total <= 0 ) {
			return self::answer( 'ok', array( self::part( __( 'Nothing failed in the last 7 days.', 'mailspur-email-log' ) ) ) );
		}
		$count = (int) ( $failures['count'] ?? 0 );
		$error = (string) ( $failures['error'] ?? '' );
		$help  = class_exists( Explainer::class ) ? Explainer::explain( $error ) : null;
		$parts = array(
			self::part(
				sprintf(
					/* translators: %s: number of emails */
					_n( '%s email failed in the last 7 days.', '%s emails failed in the last 7 days.', $total, 'mailspur-email-log' ),
					number_format_i18n( $total )
				)
			),
		);

		if ( null !== $help ) {
			$reason = $help['title'];
		} else {
			$reason = self::reason( $error );
		}
		$parts[] = self::part(
			$count >= $total
				/* translators: %s: reason, e.g. "SMTP login failed" */
				? sprintf( __( 'The reason: %s.', 'mailspur-email-log' ), $reason )
				/* translators: 1: number of emails, 2: reason, e.g. "SMTP login failed" */
				: sprintf( __( 'Most common reason (%1$s of them): %2$s.', 'mailspur-email-log' ), number_format_i18n( $count ), $reason )
		);
		if ( null !== $help ) {
			$parts[] = self::part( $help['explanation'] . ( isset( $help['steps'][0] ) ? ' ' . $help['steps'][0] : '' ) );
		}
		return self::answer( 'bad', $parts, self::link( $links['failed'], __( 'Show failed emails', 'mailspur-email-log' ) ) );
	}

	/* ------------------------------------------------------------ helpers */

	/** "today at 14:02", "yesterday at 09:00", "on 3 October 2026 at 14:02" (site formats). */
	public static function when( int $time, int $now ): string {
		$day   = (string) wp_date( 'Y-m-d', $time );
		$clock = self::clock( $time );
		if ( (string) wp_date( 'Y-m-d', $now ) === $day ) {
			/* translators: %s: time, e.g. "14:02" */
			return sprintf( __( 'today at %s', 'mailspur-email-log' ), $clock );
		}
		if ( (string) wp_date( 'Y-m-d', $now - DAY_IN_SECONDS ) === $day ) {
			/* translators: %s: time, e.g. "14:02" */
			return sprintf( __( 'yesterday at %s', 'mailspur-email-log' ), $clock );
		}
		/* translators: 1: date, 2: time */
		return sprintf( __( 'on %1$s at %2$s', 'mailspur-email-log' ), (string) wp_date( (string) get_option( 'date_format', 'F j, Y' ), $time ), $clock );
	}

	private static function clock( int $time ): string {
		return (string) wp_date( (string) get_option( 'time_format', 'H:i' ), $time );
	}

	private static function cut( string $text ): string {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > self::MAX_ERROR ) {
			return rtrim( mb_substr( $text, 0, self::MAX_ERROR - 1, 'UTF-8' ) ) . '…';
		}
		return strlen( $text ) > self::MAX_ERROR ? rtrim( substr( $text, 0, self::MAX_ERROR - 1 ) ) . '…' : $text;
	}

	/**
	 * @param array<int,array{text:string,url:string,label:string}> $items
	 * @return array{text:string,items:array<int,array{text:string,url:string,label:string}>}
	 */
	private static function part( string $text, array $items = array() ): array {
		return array(
			'text'  => $text,
			'items' => $items,
		);
	}

	/**
	 * @return array{text:string,url:string,label:string}
	 */
	private static function item( string $text, string $url = '', string $label = '' ): array {
		return array(
			'text'  => $text,
			'url'   => $url,
			'label' => '' !== $url ? $label : '',
		);
	}

	/**
	 * @return array{url:string,label:string}|null
	 */
	private static function link( string $url, string $label ): ?array {
		return '' !== $url ? array(
			'url'   => $url,
			'label' => $label,
		) : null;
	}

	/**
	 * @param array<int,array<string,mixed>>    $parts
	 * @param array{url:string,label:string}|null $link
	 * @return array<string,mixed>
	 */
	private static function answer( string $tone, array $parts, ?array $link = null ): array {
		return array(
			'tone'  => $tone,
			'parts' => $parts,
			'link'  => $link,
		);
	}
}
