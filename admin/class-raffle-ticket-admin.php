<?php
/**
 * Admin settings page: Settings > Raffle Ticket
 *
 * Lets the site admin enable/disable the raffle, customize button and
 * status text, set the minimum entries required before a winner is
 * drawn, force a manual draw, and review recent winners/tickets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Raffle_Ticket_Admin {

	const OPTION_KEY = 'raffle_ticket_settings';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_raffle_ticket_force_draw', array( $this, 'handle_force_draw' ) );
	}

	public static function get_settings() {
		$defaults = array(
			'enabled'              => 1,
			'button_text'          => __( "Get Today's Ticket", 'raffle-ticket' ),
			'already_claimed_text' => __( "You're in today's raffle! Your ticket number is #%d.", 'raffle-ticket' ),
			'logged_out_message'   => __( 'Please log in to claim your daily raffle ticket.', 'raffle-ticket' ),
			'min_entries_to_draw'  => 1,
		);
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, $defaults );
	}

	public function add_settings_page() {
		add_options_page(
			__( 'Raffle Ticket Settings', 'raffle-ticket' ),
			__( 'Raffle Ticket', 'raffle-ticket' ),
			'manage_options',
			'raffle-ticket-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'raffle_ticket_settings_group', self::OPTION_KEY, array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$output = array();
		$output['enabled'] = empty( $input['enabled'] ) ? 0 : 1;
		$output['button_text'] = sanitize_text_field( $input['button_text'] ?? '' );
		$output['already_claimed_text'] = sanitize_text_field( $input['already_claimed_text'] ?? '' );
		$output['logged_out_message'] = sanitize_text_field( $input['logged_out_message'] ?? '' );
		$output['min_entries_to_draw'] = max( 1, absint( $input['min_entries_to_draw'] ?? 1 ) );
		return $output;
	}

	public function handle_force_draw() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'raffle-ticket' ) );
		}
		check_admin_referer( 'raffle_ticket_force_draw' );

		$date = isset( $_POST['raffle_date'] ) ? sanitize_text_field( wp_unslash( $_POST['raffle_date'] ) ) : Raffle_Ticket_DB::site_today();
		Raffle_Ticket_DB::draw_winner_for_date( $date );

		wp_safe_redirect( add_query_arg( array( 'page' => 'raffle-ticket-settings', 'drawn' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get_settings();
		$today = Raffle_Ticket_DB::site_today();
		$today_count = Raffle_Ticket_DB::count_tickets_for_date( $today );
		$winners = Raffle_Ticket_DB::get_winner_history( 15 );
		$next_run = wp_next_scheduled( Raffle_Ticket_Cron::HOOK );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Raffle Ticket Settings', 'raffle-ticket' ); ?></h1>

			<?php if ( isset( $_GET['drawn'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Draw executed (if a winner was eligible to be picked).', 'raffle-ticket' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'raffle_ticket_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Raffle', 'raffle-ticket' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
								<?php esc_html_e( 'Allow users to claim tickets', 'raffle-ticket' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rt_button_text"><?php esc_html_e( 'Button Text', 'raffle-ticket' ); ?></label></th>
						<td><input type="text" id="rt_button_text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[button_text]" value="<?php echo esc_attr( $settings['button_text'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rt_claimed_text"><?php esc_html_e( 'Already-Claimed Message', 'raffle-ticket' ); ?></label></th>
						<td>
							<input type="text" id="rt_claimed_text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[already_claimed_text]" value="<?php echo esc_attr( $settings['already_claimed_text'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Use %d as a placeholder for the ticket number.', 'raffle-ticket' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rt_logged_out"><?php esc_html_e( 'Logged-Out Message', 'raffle-ticket' ); ?></label></th>
						<td><input type="text" id="rt_logged_out" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[logged_out_message]" value="<?php echo esc_attr( $settings['logged_out_message'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rt_min_entries"><?php esc_html_e( 'Minimum Entries to Draw', 'raffle-ticket' ); ?></label></th>
						<td>
							<input type="number" id="rt_min_entries" min="1" class="small-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[min_entries_to_draw]" value="<?php echo esc_attr( $settings['min_entries_to_draw'] ); ?>" />
							<p class="description"><?php esc_html_e( 'If fewer tickets than this are claimed in a day, no winner will be drawn for that day.', 'raffle-ticket' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Today', 'raffle-ticket' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: date, 2: number of tickets claimed today */
					esc_html__( 'Date: %1$s — Tickets claimed today: %2$d', 'raffle-ticket' ),
					esc_html( $today ),
					(int) $today_count
				);
				?>
			</p>
			<p>
				<?php if ( $next_run ) : ?>
					<?php
					// $next_run is a Unix (UTC) timestamp from wp_next_scheduled();
					// convert it to the site's configured timezone for display.
					$next_run_local = new DateTime( '@' . $next_run );
					$next_run_local->setTimezone( wp_timezone() );
					printf(
						/* translators: %s: date/time of next scheduled draw */
						esc_html__( 'Next automatic draw: %s', 'raffle-ticket' ),
						esc_html( $next_run_local->format( 'Y-m-d H:i:s' ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'No draw currently scheduled — it will be re-armed automatically on the next page load.', 'raffle-ticket' ); ?>
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Force a draw now? This cannot be undone for this date.', 'raffle-ticket' ) ); ?>');">
				<input type="hidden" name="action" value="raffle_ticket_force_draw" />
				<input type="hidden" name="raffle_date" value="<?php echo esc_attr( $today ); ?>" />
				<?php wp_nonce_field( 'raffle_ticket_force_draw' ); ?>
				<?php submit_button( __( 'Force Draw For Today', 'raffle-ticket' ), 'secondary' ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Recent Winners', 'raffle-ticket' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'raffle-ticket' ); ?></th>
						<th><?php esc_html_e( 'Winner', 'raffle-ticket' ); ?></th>
						<th><?php esc_html_e( 'Ticket #', 'raffle-ticket' ); ?></th>
						<th><?php esc_html_e( 'Total Entries', 'raffle-ticket' ); ?></th>
						<th><?php esc_html_e( 'Picked At', 'raffle-ticket' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $winners ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No draws yet.', 'raffle-ticket' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $winners as $w ) :
							$user = $w->user_id ? get_userdata( $w->user_id ) : null;
							?>
							<tr>
								<td><?php echo esc_html( $w->raffle_date ); ?></td>
								<td><?php echo $user ? esc_html( $user->display_name ) : esc_html__( 'No winner (insufficient entries)', 'raffle-ticket' ); ?></td>
								<td><?php echo $w->ticket_number ? '#' . esc_html( $w->ticket_number ) : '—'; ?></td>
								<td><?php echo (int) $w->total_entries; ?></td>
								<td><?php echo esc_html( $w->picked_at ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Shortcode', 'raffle-ticket' ); ?></h2>
			<p><code>[raffle_ticket]</code> — <?php esc_html_e( 'add to any page or post.', 'raffle-ticket' ); ?></p>
			<p><code>[raffle_ticket show_history="no"]</code> — <?php esc_html_e( 'hides the "My Tickets" history table.', 'raffle-ticket' ); ?></p>

			<h2><?php esc_html_e( 'REST API', 'raffle-ticket' ); ?></h2>
			<ul>
				<li><code><?php echo esc_html( rest_url( 'raffle/v1/winner/today' ) ); ?></code></li>
				<li><code><?php echo esc_html( rest_url( 'raffle/v1/winners' ) ); ?></code></li>
				<li><code><?php echo esc_html( rest_url( 'raffle/v1/stats' ) ); ?></code></li>
			</ul>
		</div>
		<?php
	}
}
