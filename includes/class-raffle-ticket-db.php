<?php
/**
 * Database table creation and core data access helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Raffle_Ticket_DB {

	/**
	 * Runs on plugin activation. Creates tables and sets default options.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$tickets_table = $wpdb->prefix . RAFFLE_TICKET_TABLE_TICKETS;
		$winners_table = $wpdb->prefix . RAFFLE_TICKET_TABLE_WINNERS;

		// Unique index on (user_id, raffle_date) enforces one ticket per
		// user per day at the DB layer — this is the real guard against
		// double-claims from race conditions, not just the PHP check.
		$sql_tickets = "CREATE TABLE {$tickets_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			raffle_date DATE NOT NULL,
			ticket_number INT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_per_day (user_id, raffle_date),
			KEY raffle_date_idx (raffle_date)
		) {$charset_collate};";

		$sql_winners = "CREATE TABLE {$winners_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			raffle_date DATE NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			ticket_number INT UNSIGNED NULL,
			total_entries INT UNSIGNED NOT NULL DEFAULT 0,
			picked_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY raffle_date_idx (raffle_date)
		) {$charset_collate};";

		dbDelta( $sql_tickets );
		dbDelta( $sql_winners );

		$defaults = array(
			'enabled'            => 1,
			'button_text'        => __( 'Get Today\'s Ticket', 'raffle-ticket' ),
			'already_claimed_text' => __( 'You\'re in today\'s raffle! Your ticket number is #%d.', 'raffle-ticket' ),
			'logged_out_message' => __( 'Please log in to claim your daily raffle ticket.', 'raffle-ticket' ),
			'min_entries_to_draw' => 1,
		);
		if ( false === get_option( 'raffle_ticket_settings' ) ) {
			add_option( 'raffle_ticket_settings', $defaults );
		}

		// Schedule the first cron event at next site-midnight.
		Raffle_Ticket_Cron::schedule_next_draw();
	}

	/** Get the wpdb table name for tickets. */
	public static function tickets_table() {
		global $wpdb;
		return $wpdb->prefix . RAFFLE_TICKET_TABLE_TICKETS;
	}

	/** Get the wpdb table name for winners. */
	public static function winners_table() {
		global $wpdb;
		return $wpdb->prefix . RAFFLE_TICKET_TABLE_WINNERS;
	}

	/**
	 * Today's date string in the site's configured timezone (Y-m-d).
	 * Centralizing this avoids any UTC/server-time drift between the
	 * shortcode, AJAX handler, and cron job.
	 */
	public static function site_today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Returns the ticket row for a given user on a given date, or null.
	 */
	public static function get_user_ticket( $user_id, $date = null ) {
		global $wpdb;
		$date = $date ? $date : self::site_today();
		$table = self::tickets_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND raffle_date = %s",
				$user_id,
				$date
			)
		);
		return $row;
	}

	/**
	 * Counts tickets issued for a given date.
	 */
	public static function count_tickets_for_date( $date ) {
		global $wpdb;
		$table = self::tickets_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE raffle_date = %s", $date )
		);
	}

	/**
	 * Issues a new ticket for the user for today, if they don't already
	 * have one. Returns the ticket row on success, or a WP_Error.
	 *
	 * Ticket numbers are sequential per day (1, 2, 3...), assigned by
	 * taking count-so-far + 1. The UNIQUE KEY on (user_id, raffle_date)
	 * is what actually prevents duplicate claims under concurrent
	 * requests; this function just handles the expected/common path.
	 */
	public static function claim_ticket( $user_id ) {
		global $wpdb;

		$settings = Raffle_Ticket_Admin::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error( 'raffle_disabled', __( 'The raffle is currently disabled.', 'raffle-ticket' ) );
		}

		$today = self::site_today();

		$existing = self::get_user_ticket( $user_id, $today );
		if ( $existing ) {
			return new WP_Error( 'already_claimed', __( 'You already have a ticket for today.', 'raffle-ticket' ), array( 'ticket' => $existing ) );
		}

		$table = self::tickets_table();
		$next_number = self::count_tickets_for_date( $today ) + 1;

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'       => $user_id,
				'raffle_date'   => $today,
				'ticket_number' => $next_number,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			// Most likely cause: the unique key rejected a duplicate
			// insert that snuck in from a near-simultaneous request.
			$existing = self::get_user_ticket( $user_id, $today );
			if ( $existing ) {
				return new WP_Error( 'already_claimed', __( 'You already have a ticket for today.', 'raffle-ticket' ), array( 'ticket' => $existing ) );
			}
			return new WP_Error( 'db_error', __( 'Could not save your ticket. Please try again.', 'raffle-ticket' ) );
		}

		return self::get_user_ticket( $user_id, $today );
	}

	/**
	 * Returns a user's ticket history, most recent first.
	 */
	public static function get_user_history( $user_id, $limit = 30 ) {
		global $wpdb;
		$tickets_table = self::tickets_table();
		$winners_table = self::winners_table();

		$sql = $wpdb->prepare(
			"SELECT t.raffle_date, t.ticket_number, t.created_at,
			        (w.user_id IS NOT NULL AND w.user_id = t.user_id) AS is_winner
			 FROM {$tickets_table} t
			 LEFT JOIN {$winners_table} w ON w.raffle_date = t.raffle_date
			 WHERE t.user_id = %d
			 ORDER BY t.raffle_date DESC
			 LIMIT %d",
			$user_id,
			$limit
		);

		return $wpdb->get_results( $sql );
	}

	/**
	 * Picks a random ticket from the given date's entries and records
	 * the winner. Idempotent: if a winner row already exists for that
	 * date, it will not pick again.
	 */
	public static function draw_winner_for_date( $date ) {
		global $wpdb;

		$winners_table = self::winners_table();
		$tickets_table = self::tickets_table();

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$winners_table} WHERE raffle_date = %s", $date )
		);
		if ( $existing ) {
			return $existing; // Already drawn — don't draw twice.
		}

		$settings = Raffle_Ticket_Admin::get_settings();
		$min_entries = isset( $settings['min_entries_to_draw'] ) ? (int) $settings['min_entries_to_draw'] : 1;

		$total_entries = self::count_tickets_for_date( $date );

		if ( $total_entries < max( 1, $min_entries ) ) {
			// Record a "no winner" row so we don't keep re-checking this
			// date on every cron run, but leave user_id/ticket_number null.
			$wpdb->insert(
				$winners_table,
				array(
					'raffle_date'    => $date,
					'user_id'        => null,
					'ticket_number'  => null,
					'total_entries'  => $total_entries,
					'picked_at'      => current_time( 'mysql' ),
				),
				array( '%s', '%d', '%d', '%d', '%s' )
			);
			return $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$winners_table} WHERE raffle_date = %s", $date )
			);
		}

		// Pick a random row among today's tickets.
		$winning_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, ticket_number FROM {$tickets_table}
				 WHERE raffle_date = %s
				 ORDER BY RAND()
				 LIMIT 1",
				$date
			)
		);

		if ( ! $winning_row ) {
			return false;
		}

		$wpdb->insert(
			$winners_table,
			array(
				'raffle_date'   => $date,
				'user_id'       => $winning_row->user_id,
				'ticket_number' => $winning_row->ticket_number,
				'total_entries' => $total_entries,
				'picked_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%d', '%s' )
		);

		do_action( 'raffle_ticket_winner_drawn', $date, $winning_row->user_id, $winning_row->ticket_number );

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$winners_table} WHERE raffle_date = %s", $date )
		);
	}

	/**
	 * Returns winner history, most recent first.
	 */
	public static function get_winner_history( $limit = 20, $offset = 0 ) {
		global $wpdb;
		$table = self::winners_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY raffle_date DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	/** Returns total ticket count across all time. */
	public static function total_ticket_count() {
		global $wpdb;
		$table = self::tickets_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/** Returns total winner (drawn) count across all time, excluding no-winner days. */
	public static function total_winner_count() {
		global $wpdb;
		$table = self::winners_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE user_id IS NOT NULL" );
	}
}
