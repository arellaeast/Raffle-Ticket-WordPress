<?php
/**
 * Handles scheduling and execution of the daily winner draw.
 *
 * WP-Cron doesn't support "run at site midnight" natively — its
 * built-in schedules are interval-based (hourly, daily-from-now, etc),
 * and "daily" means 24h after whenever it was first scheduled, which
 * drifts from actual midnight and breaks across DST changes. Instead
 * we schedule a single, non-recurring event for the next midnight,
 * and each run re-schedules the *next* one. This keeps it self-
 * correcting against timezone/DST changes and avoids drift.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Raffle_Ticket_Cron {

	const HOOK = 'raffle_ticket_daily_draw';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::HOOK, array( $this, 'run_daily_draw' ) );

		// Safety net: if for any reason no event is scheduled (e.g. it
		// was cleared by another plugin, or the site was down at the
		// scheduled time and something purged it), re-arm it.
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	public function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule_next_draw();
		}
	}

	/**
	 * Schedules a single event to fire at the next site-timezone midnight.
	 */
	public static function schedule_next_draw() {
		$timestamp = self::next_midnight_timestamp();
		wp_schedule_single_event( $timestamp, self::HOOK );
	}

	/**
	 * Calculates the UTC timestamp corresponding to the next midnight
	 * in the site's configured timezone (Settings > General).
	 */
	private static function next_midnight_timestamp() {
		$timezone = wp_timezone(); // Respects the site's Settings > General timezone.
		$now = new DateTime( 'now', $timezone );

		$next_midnight = new DateTime( 'now', $timezone );
		$next_midnight->setTime( 0, 0, 0 );
		$next_midnight->modify( '+1 day' );

		return $next_midnight->getTimestamp();
	}

	/**
	 * Runs at site midnight: draws yesterday's winner, then schedules
	 * the next run. "Yesterday" here means the raffle_date that just
	 * ended — i.e. the date right before the midnight that just hit.
	 */
	public function run_daily_draw() {
		$timezone = wp_timezone();
		$today = new DateTime( 'now', $timezone );
		$ended_date = $today->modify( '-1 second' )->format( 'Y-m-d' ); // The day that just finished.

		Raffle_Ticket_DB::draw_winner_for_date( $ended_date );

		// Always re-schedule the next midnight run, regardless of outcome.
		self::schedule_next_draw();
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}
}
