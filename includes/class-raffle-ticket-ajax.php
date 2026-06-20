<?php
/**
 * Handles the AJAX request fired when a logged-in user clicks the
 * "Get Today's Ticket" button.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Raffle_Ticket_Ajax {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_raffle_ticket_claim', array( $this, 'handle_claim' ) );
		// Intentionally no wp_ajax_nopriv hook — logged-out users can't claim.
	}

	public function handle_claim() {
		check_ajax_referer( 'raffle_ticket_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to claim a ticket.', 'raffle-ticket' ) ), 403 );
		}

		$user_id = get_current_user_id();
		$result = Raffle_Ticket_DB::claim_ticket( $user_id );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$payload = array( 'message' => $result->get_error_message() );
			if ( ! empty( $data['ticket'] ) ) {
				$payload['ticket_number'] = (int) $data['ticket']->ticket_number;
			}
			wp_send_json_error( $payload, 400 );
		}

		wp_send_json_success(
			array(
				'message'       => __( 'Ticket claimed! Good luck.', 'raffle-ticket' ),
				'ticket_number' => (int) $result->ticket_number,
				'raffle_date'   => $result->raffle_date,
			)
		);
	}
}
