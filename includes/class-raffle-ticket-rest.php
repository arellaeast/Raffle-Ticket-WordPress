<?php
/**
 * Public, read-only REST API for the raffle.
 *
 * Namespace: raffle/v1
 *   GET /winner/today   -> today's winner (or null if not yet drawn)
 *   GET /winners         -> paginated winner history
 *   GET /stats            -> total tickets, today's ticket count, total winners
 *
 * All endpoints are public GETs by design (per spec: "expose via API").
 * User identity is limited to user_id + display_name — no emails or
 * other PII are returned.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Raffle_Ticket_REST {

	const NAMESPACE_ = 'raffle/v1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/winner/today',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_today_winner' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/winners',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_winners' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'per_page' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Formats a winner DB row into a REST-friendly array. Returns null
	 * fields for user/ticket if no winner was drawn that day (e.g. zero
	 * entries).
	 */
	private function format_winner_row( $row ) {
		$user = null;
		if ( $row->user_id ) {
			$user_obj = get_userdata( $row->user_id );
			$user = array(
				'id'           => (int) $row->user_id,
				'display_name' => $user_obj ? $user_obj->display_name : null,
			);
		}

		return array(
			'raffle_date'    => $row->raffle_date,
			'winner'         => $user,
			'ticket_number'  => $row->ticket_number ? (int) $row->ticket_number : null,
			'total_entries'  => (int) $row->total_entries,
			'picked_at'      => $row->picked_at,
		);
	}

	public function get_today_winner( WP_REST_Request $request ) {
		global $wpdb;
		$today = Raffle_Ticket_DB::site_today();
		$table = Raffle_Ticket_DB::winners_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE raffle_date = %s", $today )
		);

		if ( ! $row ) {
			return new WP_REST_Response(
				array(
					'raffle_date' => $today,
					'drawn'       => false,
					'message'     => __( "Today's winner has not been drawn yet.", 'raffle-ticket' ),
				),
				200
			);
		}

		$data = $this->format_winner_row( $row );
		$data['drawn'] = true;

		return new WP_REST_Response( $data, 200 );
	}

	public function get_winners( WP_REST_Request $request ) {
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$offset = ( $page - 1 ) * $per_page;

		$rows = Raffle_Ticket_DB::get_winner_history( $per_page, $offset );

		$results = array_map( array( $this, 'format_winner_row' ), $rows );

		return new WP_REST_Response(
			array(
				'page'     => $page,
				'per_page' => $per_page,
				'winners'  => $results,
			),
			200
		);
	}

	public function get_stats( WP_REST_Request $request ) {
		$today = Raffle_Ticket_DB::site_today();

		return new WP_REST_Response(
			array(
				'today_ticket_count' => Raffle_Ticket_DB::count_tickets_for_date( $today ),
				'total_ticket_count' => Raffle_Ticket_DB::total_ticket_count(),
				'total_winner_count' => Raffle_Ticket_DB::total_winner_count(),
				'raffle_date'        => $today,
			),
			200
		);
	}
}
