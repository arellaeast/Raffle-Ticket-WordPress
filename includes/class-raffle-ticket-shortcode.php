<?php
/**
 * Shortcodes:
 *
 *   [raffle_ticket] — renders the claim button (or status) and an
 *   optional "My Tickets" history panel.
 *     Attributes:
 *       show_history="yes|no"   (default "yes")
 *       history_limit="10"      (default 10)
 *
 *   [raffle_ticket_history] — read-only history table only. No claim
 *   button. Intended for a "My Account" page or similar.
 *     Attributes:
 *       limit="20"              (default 20)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Raffle_Ticket_Shortcode {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'raffle_ticket', array( $this, 'render' ) );
		add_shortcode( 'raffle_ticket_history', array( $this, 'render_history' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Only enqueue JS/CSS on pages that actually use either shortcode,
	 * to avoid loading unnecessary assets site-wide. The claim-button
	 * JS is harmless to load on history-only pages (it just won't find
	 * a .raffle-ticket-button to bind to), so we share the same assets.
	 */
	public function maybe_enqueue_assets() {
		global $post;
		if ( is_a( $post, 'WP_Post' )
			&& ( has_shortcode( $post->post_content, 'raffle_ticket' )
				|| has_shortcode( $post->post_content, 'raffle_ticket_history' ) )
		) {
			$this->enqueue_assets();
		}
	}

	private function enqueue_assets() {
		wp_enqueue_style(
			'raffle-ticket',
			RAFFLE_TICKET_URL . 'assets/css/raffle-ticket.css',
			array(),
			RAFFLE_TICKET_VERSION
		);
		wp_enqueue_script(
			'raffle-ticket',
			RAFFLE_TICKET_URL . 'assets/js/raffle-ticket.js',
			array( 'jquery' ),
			RAFFLE_TICKET_VERSION,
			true
		);
		wp_localize_script(
			'raffle-ticket',
			'RaffleTicketData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'raffle_ticket_nonce' ),
			)
		);
	}

	public function render( $atts ) {
		// In case the shortcode is added dynamically (widget, builder, etc.)
		// where has_shortcode() on $post->post_content wouldn't catch it.
		$this->enqueue_assets();

		$atts = shortcode_atts(
			array(
				'show_history'  => 'yes',
				'history_limit' => 10,
			),
			$atts,
			'raffle_ticket'
		);

		$settings = Raffle_Ticket_Admin::get_settings();

		ob_start();
		?>
		<div class="raffle-ticket-widget">
			<?php if ( empty( $settings['enabled'] ) ) : ?>
				<p class="raffle-ticket-message"><?php esc_html_e( 'The raffle is currently paused. Check back soon!', 'raffle-ticket' ); ?></p>

			<?php elseif ( ! is_user_logged_in() ) : ?>
				<p class="raffle-ticket-message"><?php echo esc_html( $settings['logged_out_message'] ); ?></p>
				<a class="raffle-ticket-login-link" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
					<?php esc_html_e( 'Log In', 'raffle-ticket' ); ?>
				</a>

			<?php else :
				$user_id = get_current_user_id();
				$ticket = Raffle_Ticket_DB::get_user_ticket( $user_id );
				?>
				<div class="raffle-ticket-claim" data-claimed="<?php echo $ticket ? '1' : '0'; ?>">
					<button type="button"
						class="raffle-ticket-button"
						<?php disabled( (bool) $ticket ); ?>>
						<?php echo esc_html( $settings['button_text'] ); ?>
					</button>
					<p class="raffle-ticket-status">
						<?php if ( $ticket ) : ?>
							<?php echo esc_html( sprintf( $settings['already_claimed_text'], $ticket->ticket_number ) ); ?>
						<?php endif; ?>
					</p>
				</div>

				<?php if ( 'yes' === $atts['show_history'] ) :
				echo $this->render_history_table( $user_id, (int) $atts['history_limit'] );
			endif; ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [raffle_ticket_history] — read-only history table, no claim
	 * button. Safe to place on a "My Account" page or anywhere a user
	 * shouldn't be prompted to claim a ticket.
	 *
	 * Attributes:
	 *   limit="20"   number of past tickets to show (default 20)
	 */
	public function render_history( $atts ) {
		$this->enqueue_assets();

		$atts = shortcode_atts(
			array(
				'limit' => 20,
			),
			$atts,
			'raffle_ticket_history'
		);

		ob_start();
		?>
		<div class="raffle-ticket-widget raffle-ticket-history-only">
			<?php if ( ! is_user_logged_in() ) : ?>
				<p class="raffle-ticket-message"><?php esc_html_e( 'Please log in to see your raffle history.', 'raffle-ticket' ); ?></p>
				<a class="raffle-ticket-login-link" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
					<?php esc_html_e( 'Log In', 'raffle-ticket' ); ?>
				</a>
			<?php else :
				echo $this->render_history_table( get_current_user_id(), (int) $atts['limit'] );
			endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Shared markup for the "My Tickets" history table, used by both
	 * [raffle_ticket] (inline, optional) and [raffle_ticket_history]
	 * (standalone). Returns an empty string if the user has no tickets
	 * yet, rather than rendering an empty table shell.
	 */
	private function render_history_table( $user_id, $limit ) {
		$history = Raffle_Ticket_DB::get_user_history( $user_id, $limit );

		if ( ! $history ) {
			return '<p class="raffle-ticket-message">' . esc_html__( "You haven't claimed any tickets yet.", 'raffle-ticket' ) . '</p>';
		}

		ob_start();
		?>
		<div class="raffle-ticket-history">
			<h4><?php esc_html_e( 'My Tickets', 'raffle-ticket' ); ?></h4>
			<table class="raffle-ticket-history-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'raffle-ticket' ); ?></th>
						<th><?php esc_html_e( 'Ticket #', 'raffle-ticket' ); ?></th>
						<th><?php esc_html_e( 'Result', 'raffle-ticket' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $history as $row ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->raffle_date ) ) ); ?></td>
							<td>#<?php echo esc_html( $row->ticket_number ); ?></td>
							<td>
								<?php if ( $row->is_winner ) : ?>
									<span class="raffle-ticket-won">🎉 <?php esc_html_e( 'Winner!', 'raffle-ticket' ); ?></span>
								<?php else : ?>
									<span class="raffle-ticket-pending"><?php esc_html_e( '—', 'raffle-ticket' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}
}
