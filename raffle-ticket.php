<?php
/**
 * Plugin Name: Raffle Ticket
 * Plugin URI:  https://example.com/raffle-ticket
 * Description: Daily raffle plugin. Logged-in users claim one ticket per day; a winner is drawn automatically at site midnight. Includes shortcode, admin settings, and a read-only REST API.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL v2 or later
 * Text Domain: raffle-ticket
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'RAFFLE_TICKET_VERSION', '1.0.0' );
define( 'RAFFLE_TICKET_PATH', plugin_dir_path( __FILE__ ) );
define( 'RAFFLE_TICKET_URL', plugin_dir_url( __FILE__ ) );
define( 'RAFFLE_TICKET_TABLE_TICKETS', 'raffle_tickets' );
define( 'RAFFLE_TICKET_TABLE_WINNERS', 'raffle_winners' );

require_once RAFFLE_TICKET_PATH . 'includes/class-raffle-ticket-db.php';
require_once RAFFLE_TICKET_PATH . 'includes/class-raffle-ticket-cron.php';
require_once RAFFLE_TICKET_PATH . 'includes/class-raffle-ticket-shortcode.php';
require_once RAFFLE_TICKET_PATH . 'includes/class-raffle-ticket-rest.php';
require_once RAFFLE_TICKET_PATH . 'includes/class-raffle-ticket-ajax.php';
require_once RAFFLE_TICKET_PATH . 'admin/class-raffle-ticket-admin.php';

/**
 * Core plugin bootstrap / singleton.
 */
final class Raffle_Ticket {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( 'Raffle_Ticket_DB', 'activate' ) );
		register_deactivation_hook( __FILE__, array( 'Raffle_Ticket_Cron', 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function init() {
		load_plugin_textdomain( 'raffle-ticket', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		Raffle_Ticket_Cron::instance();
		Raffle_Ticket_Shortcode::instance();
		Raffle_Ticket_REST::instance();
		Raffle_Ticket_Ajax::instance();

		if ( is_admin() ) {
			Raffle_Ticket_Admin::instance();
		}
	}
}

Raffle_Ticket::instance();
