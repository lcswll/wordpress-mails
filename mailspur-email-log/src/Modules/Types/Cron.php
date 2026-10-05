<?php
/**
 * Cause of a stopped email type that is sent by WP-Cron: is its event still scheduled, does WP-Cron run at all?
 *
 * Which types come from cron is learnt while indexing (the trace of each email records the request type and
 * the cron hook). Only read when a type is overdue – no extra work while emails are sent.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

defined( 'ABSPATH' ) || exit;

final class Cron {

	/** Events overdue by more than this mean WP-Cron is not running. */
	const STALL = HOUR_IN_SECONDS;

	/**
	 * Diagnosis for the current site.
	 *
	 * @return array{code:string,text:string}
	 */
	public static function current( string $hook, int $now ): array {
		$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		return self::diagnose( $hook, $crons, $now, defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	/**
	 * Plain-language cause. Pure apart from translation and time formatting.
	 *
	 * @param string                                        $hook     Cron hook that sent the type's emails ('' = unknown).
	 * @param array<int|string,mixed>                       $crons    _get_cron_array(): timestamp => hook => events.
	 * @param int                                           $now      Unix time.
	 * @param bool                                          $disabled DISABLE_WP_CRON is set.
	 * @return array{code:string,text:string} code: unscheduled | stalled | overdue | running.
	 */
	public static function diagnose( string $hook, array $crons, int $now, bool $disabled ): array {
		// Oldest due event of any other hook: when WP-Cron runs, nothing else stays overdue for long.
		$oldest = null;
		$next   = null;
		foreach ( $crons as $time => $hooks ) {
			if ( ! is_numeric( $time ) || ! is_array( $hooks ) || ! $hooks ) {
				continue;
			}
			$time = (int) $time;
			if ( '' !== $hook && isset( $hooks[ $hook ] ) ) {
				$next = null === $next ? $time : min( $next, $time );
			}
			if ( count( $hooks ) > ( '' !== $hook && isset( $hooks[ $hook ] ) ? 1 : 0 ) ) {
				$oldest = null === $oldest ? $time : min( $oldest, $time );
			}
		}

		/* translators: %s: name of a WP-Cron hook */
		$sent_by = '' !== $hook ? sprintf( __( 'Sent by the cron event %s.', 'mailspur-email-log' ), $hook ) : __( 'Sent by WP-Cron.', 'mailspur-email-log' );

		if ( '' !== $hook && null === $next ) {
			return array(
				'code' => 'unscheduled',
				/* translators: %s: name of a WP-Cron hook */
				'text' => sprintf( __( 'Sent by the cron event %s – this event is no longer scheduled.', 'mailspur-email-log' ), $hook ),
			);
		}

		if ( null !== $oldest && $now - $oldest > self::STALL ) {
			$since = human_time_diff( $oldest, $now );
			$text  = $disabled
				/* translators: %s: time span, e.g. "2 days" */
				? sprintf( __( 'WP-Cron has not run for %s (DISABLE_WP_CRON is set – check your server cron job).', 'mailspur-email-log' ), $since )
				/* translators: %s: time span, e.g. "2 days" */
				: sprintf( __( 'WP-Cron has not run for %s – it only runs when the site gets visits, or it fails with an error.', 'mailspur-email-log' ), $since );
			return array(
				'code' => 'stalled',
				'text' => $sent_by . ' ' . $text,
			);
		}

		if ( null !== $next && $now - $next > self::STALL ) {
			return array(
				'code' => 'overdue',
				/* translators: 1: name of a WP-Cron hook, 2: time span */
				'text' => sprintf( __( 'Sent by the cron event %1$s – it is overdue by %2$s while WP-Cron runs other events; it may fail with an error.', 'mailspur-email-log' ), $hook, human_time_diff( $next, $now ) ),
			);
		}

		if ( null !== $next ) {
			return array(
				'code' => 'running',
				/* translators: 1: name of a WP-Cron hook, 2: time span */
				'text' => sprintf( __( 'Sent by the cron event %1$s – it is scheduled (next run in %2$s) and WP-Cron runs, so the event no longer sends this email.', 'mailspur-email-log' ), $hook, human_time_diff( $now, max( $now, $next ) ) ),
			);
		}
		return array(
			'code' => 'running',
			'text' => $sent_by . ' ' . __( 'WP-Cron runs normally.', 'mailspur-email-log' ),
		);
	}
}
