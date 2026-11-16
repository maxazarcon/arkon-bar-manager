<?php
/**
 * Event Manager > Agent Changes.
 *
 * Where a person decides the changes an agent was not allowed to make on its
 * own, and reads back everything agents have done.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Agent_Admin {

	const SLUG = 'abm-agent-changes';

	/** @var ABM_Agent_Admin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_abm_agent_decide', array( $this, 'handle_decision' ) );
	}

	public function register_menu() {
		$pending = ABM_Agent_Store::pending_count();
		$title   = __( 'Agent Changes', 'arkon-bar-manager' );
		$menu    = $title;
		if ( $pending ) {
			$menu .= sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>', $pending, esc_html( number_format_i18n( $pending ) ) );
		}

		add_submenu_page(
			'edit.php?post_type=' . ABM_POST_TYPE,
			$title,
			$menu,
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Approve or reject one pending change.
	 *
	 * Only a logged-in administrator can decide, and never an agent account:
	 * the point of holding a change is that somebody other than the agent
	 * looks at it.
	 */
	public function handle_decision() {
		$id = isset( $_POST['change_id'] ) ? absint( wp_unslash( $_POST['change_id'] ) ) : 0;
		check_admin_referer( 'abm_agent_decide_' . $id );

		if ( ! current_user_can( 'manage_options' ) || ABM_Agent_API::is_agent() ) {
			wp_die( esc_html__( 'You do not have permission to decide agent changes.', 'arkon-bar-manager' ), '', array( 'response' => 403 ) );
		}

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$notice   = 'error';
		$message  = '';

		if ( 'approve' === $decision ) {
			$done = ABM_Agent_Ops::approve( $id, get_current_user_id() );
			if ( is_wp_error( $done ) ) {
				$message = $done->get_error_message();
			} elseif ( 'approved' === $done['status'] ) {
				$notice  = 'success';
				$message = __( 'Approved and applied.', 'arkon-bar-manager' );
			} else {
				$notice  = 'warning';
				$message = (string) ( $done['reason'] ?? __( 'The change could no longer be applied.', 'arkon-bar-manager' ) );
			}
		} elseif ( 'reject' === $decision ) {
			if ( ABM_Agent_Ops::reject( $id, get_current_user_id() ) ) {
				$notice  = 'success';
				$message = __( 'Rejected. Nothing was changed.', 'arkon-bar-manager' );
			} else {
				$message = __( 'That change was already decided.', 'arkon-bar-manager' );
			}
		} else {
			$message = __( 'Unknown decision.', 'arkon-bar-manager' );
		}

		set_transient(
			'abm_agent_notice_' . get_current_user_id(),
			array(
				'type'    => $notice,
				'message' => $message,
			),
			60
		);

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . ABM_POST_TYPE . '&page=' . self::SLUG ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'arkon-bar-manager' ) );
		}

		$notice_key = 'abm_agent_notice_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		if ( $notice ) {
			delete_transient( $notice_key );
		}

		$pending = ABM_Agent_Store::list_changes( 'pending', 100 );
		$log     = ABM_Agent_Store::get_log( 100 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Changes', 'arkon-bar-manager' ); ?></h1>

			<?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<p class="description" style="max-width:52em">
				<?php esc_html_e( 'Agents make clear changes on their own. A change that would take something off the calendar, or that looks like it might be a mistake, waits here until you decide. Approving applies it straight away; if the event has been edited since the agent asked, nothing is applied and the agent has to ask again.', 'arkon-bar-manager' ); ?>
			</p>

			<h2><?php esc_html_e( 'Waiting for you', 'arkon-bar-manager' ); ?></h2>

			<?php if ( ! $pending ) : ?>
				<p><?php esc_html_e( 'Nothing is waiting.', 'arkon-bar-manager' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Requested', 'arkon-bar-manager' ); ?></th>
							<th><?php esc_html_e( 'By', 'arkon-bar-manager' ); ?></th>
							<th><?php esc_html_e( 'Change', 'arkon-bar-manager' ); ?></th>
							<th><?php esc_html_e( 'Why it is waiting', 'arkon-bar-manager' ); ?></th>
							<th><?php esc_html_e( 'Decide', 'arkon-bar-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $pending as $change ) : ?>
						<tr>
							<td><?php echo esc_html( $this->when( $change['created_gmt'] ) ); ?></td>
							<td><?php echo esc_html( $this->who( $change['user_id'] ) ); ?></td>
							<td>
								<strong><?php echo esc_html( (string) ( $change['summary']['text'] ?? $change['op'] ) ); ?></strong>
								<?php if ( $change['post_id'] && get_post( $change['post_id'] ) ) : ?>
									<br /><a href="<?php echo esc_url( (string) get_edit_post_link( $change['post_id'] ) ); ?>"><?php esc_html_e( 'Open the event', 'arkon-bar-manager' ); ?></a>
								<?php endif; ?>
								<?php $this->dates( __( 'Dates that would come off the calendar:', 'arkon-bar-manager' ), (array) ( $change['summary']['removed'] ?? array() ) ); ?>
								<?php $this->dates( __( 'Dates that would be added:', 'arkon-bar-manager' ), (array) ( $change['summary']['added'] ?? array() ) ); ?>
							</td>
							<td>
								<?php foreach ( (array) ( $change['summary']['reasons'] ?? array() ) as $reason ) : ?>
									<?php echo esc_html( (string) $reason ); ?><br />
								<?php endforeach; ?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="abm_agent_decide" />
									<input type="hidden" name="change_id" value="<?php echo esc_attr( (string) $change['id'] ); ?>" />
									<?php wp_nonce_field( 'abm_agent_decide_' . $change['id'] ); ?>
									<button type="submit" name="decision" value="approve" class="button button-primary"><?php esc_html_e( 'Approve', 'arkon-bar-manager' ); ?></button>
									<button type="submit" name="decision" value="reject" class="button"><?php esc_html_e( 'Reject', 'arkon-bar-manager' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 style="margin-top:2em"><?php esc_html_e( 'What agents have done', 'arkon-bar-manager' ); ?></h2>

			<?php if ( ! $log ) : ?>
				<p><?php esc_html_e( 'No agent activity yet.', 'arkon-bar-manager' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'arkon-bar-manager' ); ?></th>
							<th><?php esc_html_e( 'By', 'arkon-bar-manager' ); ?></th>
							<th><?php esc_html_e( 'Change', 'arkon-bar-manager' ); ?></th>
							<th><?php esc_html_e( 'Result', 'arkon-bar-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $this->when( $entry['created_gmt'] ) ); ?></td>
							<td><?php echo esc_html( $this->who( $entry['user_id'] ) ); ?></td>
							<td>
								<?php echo esc_html( (string) ( $entry['summary']['text'] ?? $entry['op'] ) ); ?>
								<?php $this->dates( __( 'Removed:', 'arkon-bar-manager' ), 'applied' === $entry['outcome'] ? (array) ( $entry['summary']['removed'] ?? array() ) : array() ); ?>
							</td>
							<td><?php echo esc_html( $this->outcome_label( $entry['outcome'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string   $label Heading.
	 * @param string[] $dates Y-m-d list.
	 */
	private function dates( $label, array $dates ) {
		if ( ! $dates ) {
			return;
		}
		$shown = array();
		foreach ( $dates as $date ) {
			$shown[] = abm_format_date( (string) $date, 'D, M j, Y' );
		}
		echo '<br /><small><strong>' . esc_html( $label ) . '</strong> ' . esc_html( implode( '; ', $shown ) ) . '</small>';
	}

	/**
	 * @param string $gmt MySQL datetime, GMT.
	 * @return string
	 */
	private function when( $gmt ) {
		return (string) get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function who( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : null;
		return $user ? (string) $user->display_name : __( '(unknown)', 'arkon-bar-manager' );
	}

	/**
	 * @param string $outcome Stored outcome.
	 * @return string
	 */
	private function outcome_label( $outcome ) {
		$labels = array(
			'applied'           => __( 'Applied', 'arkon-bar-manager' ),
			'approval_required' => __( 'Held for approval', 'arkon-bar-manager' ),
			'refused'           => __( 'Refused', 'arkon-bar-manager' ),
			'rejected'          => __( 'Rejected', 'arkon-bar-manager' ),
			'stale'             => __( 'Not applied: the event had changed', 'arkon-bar-manager' ),
			'interrupted'       => __( 'Interrupted while being applied. Check the event.', 'arkon-bar-manager' ),
		);
		return isset( $labels[ $outcome ] ) ? $labels[ $outcome ] : (string) $outcome;
	}
}
