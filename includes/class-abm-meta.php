<?php
/**
 * Event meta: registration, the editor meta box, and save/sync of derived
 * display + calendar values into abm_ prefixed meta keys.
 *
 * Stored meta (all queryable, no leading underscore so Cornerstone Dynamic
 * Content can read them via {{dc:post:meta key="abm_..."}}):
 *   abm_event_date        Y-m-d   (raw, for sorting / Looper meta queries)
 *   abm_event_time_start  H:i
 *   abm_event_time_end    H:i | 'close'
 *   abm_date_display      e.g. "26 Jun"
 *   abm_time_display      e.g. "8:00 PM - Close"
 *   abm_flyer_id          attachment ID (mirrors the Featured Image)
 *   abm_flyer_url         flyer URL (Featured Image, or the global placeholder)
 *   abm_ical              per-event .ics download link
 *   abm_gcal              Google Calendar "add event" link
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Meta {

	/** @var ABM_Meta|null */
	private static $instance = null;

	const NONCE = 'abm_meta_nonce';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		// custom-fields support is on purely for REST; the raw key/value panel it
		// also switches on would just be a way to corrupt these fields by hand.
		add_action( 'add_meta_boxes', array( $this, 'hide_custom_fields_box' ), 20 );
		add_action( 'save_post_' . ABM_POST_TYPE, array( $this, 'save' ), 10, 2 );
		// Recompute flyer/calendar URLs against the final permalink after insert.
		add_action( 'save_post_' . ABM_POST_TYPE, array( $this, 'sync_derived' ), 20, 2 );
		// Settings affect derived meta (placeholder, close time, start-date clamp):
		// re-sync every event when they change.
		add_action( 'update_option_' . ABM_SETTINGS, array( $this, 'resync_all' ) );
	}

	/**
	 * Register meta with sanitization + REST exposure (read public, write = edit_post).
	 */
	public function register_meta() {
		$string_keys = array(
			'abm_event_date',
			'abm_event_time_start',
			'abm_event_time_end',
			'abm_event_cost',
			'abm_show_category',
			'abm_date_display',
			'abm_time_display',
			'abm_cost_display',
			'abm_recur_type',
			'abm_recur_weekdays',
			'abm_recur_until',
			'abm_recur_exceptions',
			'abm_legacy_slug',
		);
		foreach ( $string_keys as $key ) {
			register_post_meta(
				ABM_POST_TYPE,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'meta_auth' ),
				)
			);
		}

		// URLs need their own sanitizer. sanitize_text_field() contains a loop that
		// deletes every percent-encoded sequence it finds (see _sanitize_text_fields()
		// in wp-includes/formatting.php), which is correct for prose and destructive
		// for a URL. abm_gcal carries an encoded query string, so registering it as a
		// text field stripped every %20, %3A and %2C on save, so an event titled
		// "Live Band Tonight" reached Google as "LiveBandTonight".
		//
		// The closure takes one argument on purpose. register_post_meta() passes
		// ( $value, $meta_key, $object_type ), and esc_url_raw()'s second parameter is
		// $protocols -- handing it a meta key would be meaningless.
		$sanitize_url = static function ( $value ) {
			return esc_url_raw( (string) $value );
		};

		foreach ( array( 'abm_flyer_url', 'abm_ical', 'abm_gcal' ) as $url_key ) {
			register_post_meta(
				ABM_POST_TYPE,
				$url_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $sanitize_url,
					'auth_callback'     => array( $this, 'meta_auth' ),
				),
			);
		}

		foreach ( array( 'abm_flyer_id', 'abm_display_start_only', 'abm_recur_interval', 'abm_recur_count' ) as $int_key ) {
			register_post_meta(
				ABM_POST_TYPE,
				$int_key,
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( $this, 'meta_auth' ),
				)
			);
		}
	}

	/**
	 * Expose read-only occurrence facts on an event's REST representation.
	 *
	 * Without this a REST client cannot tell that an imported event's dates are
	 * locked. PATCHing abm_event_date on one returns 200, updates the meta and the
	 * display strings, and leaves the calendar rendering the original imported
	 * dates, because a verbatim set with no rule is deliberately never
	 * regenerated. That silence is the entire reason this field exists.
	 *
	 * Read-only: no update_callback is registered, so it cannot be written.
	 * Costs one query per event, and only when the field is actually requested --
	 * core skips additional fields left out of ?_fields=.
	 */
	public function register_rest_fields() {
		register_rest_field(
			ABM_POST_TYPE,
			'abm_occurrences',
			array(
				'get_callback' => array( $this, 'rest_occurrence_info' ),
				'schema'       => array(
					'description' => __( 'Read-only: how many dates this event has, its next upcoming date, and whether its dates are locked because they were imported verbatim.', 'arkon-bar-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'properties'  => array(
						'count'  => array( 'type' => 'integer' ),
						'next'   => array( 'type' => 'string' ),
						'locked' => array( 'type' => 'boolean' ),
					),
				),
			)
		);
	}

	/**
	 * @param array $post Prepared post response data.
	 * @return array{count:int,next:string,locked:bool}
	 */
	public function rest_occurrence_info( $post ) {
		return ABM_Occurrences::stats_for( isset( $post['id'] ) ? (int) $post['id'] : 0 );
	}

	/**
	 * Only users who can edit the event may write its meta over REST.
	 *
	 * @param bool   $allowed Default.
	 * @param string $meta_key Meta key.
	 * @param int    $object_id Post ID.
	 * @return bool
	 */
	public function meta_auth( $allowed, $meta_key, $object_id ) {
		return current_user_can( 'edit_post', $object_id );
	}

	/**
	 * Remove the core Custom Fields panel from the event editor.
	 */
	public function hide_custom_fields_box() {
		remove_meta_box( 'postcustom', ABM_POST_TYPE, 'normal' );
	}

	public function add_meta_box() {
		add_meta_box(
			'abm_event_details',
			__( 'Event Details', 'arkon-bar-manager' ),
			array( $this, 'render_meta_box' ),
			ABM_POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the date / time / flyer fields. (Category is the native taxonomy
	 * checklist box provided automatically by WordPress.)
	 *
	 * @param WP_Post $post Current event.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$date  = get_post_meta( $post->ID, 'abm_event_date', true );
		$start = get_post_meta( $post->ID, 'abm_event_time_start', true );
		$end   = get_post_meta( $post->ID, 'abm_event_time_end', true );
		$cost  = get_post_meta( $post->ID, 'abm_event_cost', true );
		$showcat = get_post_meta( $post->ID, 'abm_show_category', true );

		$is_close = ( 'close' === $end );
		$end_time = $is_close ? '' : $end;

		// Default OFF for new events (meta never set): a stated end time is
		// believed unless the user says it is a placeholder.
		$ds_meta       = get_post_meta( $post->ID, 'abm_display_start_only', true );
		$display_start = ( '' === $ds_meta ) ? 0 : (int) $ds_meta;
		?>
		<div class="abm-fields">
			<p>
				<label for="abm_event_date"><strong><?php esc_html_e( 'Event Date', 'arkon-bar-manager' ); ?></strong></label><br />
				<input type="date" id="abm_event_date" name="abm_event_date" value="<?php echo esc_attr( $date ); ?>" />
			</p>

			<p>
				<label for="abm_event_time_start"><strong><?php esc_html_e( 'Start Time', 'arkon-bar-manager' ); ?></strong></label><br />
				<input type="time" id="abm_event_time_start" name="abm_event_time_start" value="<?php echo esc_attr( $start ); ?>" />
			</p>

			<p>
				<strong><?php esc_html_e( 'End Time', 'arkon-bar-manager' ); ?></strong><br />
				<label>
					<input type="radio" name="abm_end_mode" value="time" <?php checked( ! $is_close ); ?> class="abm-end-mode" />
					<?php esc_html_e( 'Specific time', 'arkon-bar-manager' ); ?>
				</label>
				<input type="time" id="abm_event_time_end" name="abm_event_time_end" value="<?php echo esc_attr( $end_time ); ?>" <?php disabled( $is_close ); ?> />
				&nbsp;&nbsp;
				<label>
					<input type="radio" name="abm_end_mode" value="close" <?php checked( $is_close ); ?> class="abm-end-mode" />
					<?php esc_html_e( 'Close', 'arkon-bar-manager' ); ?>
				</label>
			</p>

			<p>
				<label>
					<input type="checkbox" name="abm_display_start_only" value="1" <?php checked( $display_start, 1 ); ?> />
					<strong><?php esc_html_e( 'End time is approximate (cut exports off at end of night)', 'arkon-bar-manager' ); ?></strong>
				</label><br />
				<small><?php esc_html_e( 'Only affects Google Calendar and iCal downloads. The listing always shows this event on its start date, so an 8:00 PM – 1:00 AM show appears once, on the day it starts. Leave this off when the end time is real: the export will then correctly run past midnight. Turn it on when the end time is a placeholder or the night is open-ended, so exports stop at the end of the start day instead of claiming a finish time.', 'arkon-bar-manager' ); ?></small>
			</p>

			<?php
			$rule      = ABM_Occurrences::get_rule( $post->ID );
			$end_mode  = '' !== $rule['until'] ? 'until' : ( $rule['count'] > 0 ? 'count' : 'never' );
			$weekdays  = $rule['weekdays'];
			$day_names = array(
				0 => __( 'Sun', 'arkon-bar-manager' ),
				1 => __( 'Mon', 'arkon-bar-manager' ),
				2 => __( 'Tue', 'arkon-bar-manager' ),
				3 => __( 'Wed', 'arkon-bar-manager' ),
				4 => __( 'Thu', 'arkon-bar-manager' ),
				5 => __( 'Fri', 'arkon-bar-manager' ),
				6 => __( 'Sat', 'arkon-bar-manager' ),
			);
			$occ_count = 0;
			if ( $post->ID ) {
				global $wpdb;
				$occ_table = ABM_Occurrences::table();
				$occ_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$occ_table} WHERE post_id = %d", $post->ID ) ); // phpcs:ignore
			}
			?>
			<fieldset class="abm-recur">
				<legend><strong><?php esc_html_e( 'Repeats', 'arkon-bar-manager' ); ?></strong></legend>

				<p>
					<select id="abm_recur_type" name="abm_recur_type" class="abm-recur-type">
						<option value="" <?php selected( $rule['type'], '' ); ?>><?php esc_html_e( 'Does not repeat', 'arkon-bar-manager' ); ?></option>
						<option value="daily" <?php selected( $rule['type'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'arkon-bar-manager' ); ?></option>
						<option value="weekly" <?php selected( $rule['type'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'arkon-bar-manager' ); ?></option>
						<option value="monthly_date" <?php selected( $rule['type'], 'monthly_date' ); ?>><?php esc_html_e( 'Monthly, on the same date', 'arkon-bar-manager' ); ?></option>
						<option value="monthly_day" <?php selected( $rule['type'], 'monthly_day' ); ?>><?php esc_html_e( 'Monthly, on the same weekday', 'arkon-bar-manager' ); ?></option>
					</select>
				</p>

				<div class="abm-recur-detail">
					<p>
						<label for="abm_recur_interval"><?php esc_html_e( 'Every', 'arkon-bar-manager' ); ?></label>
						<input type="number" id="abm_recur_interval" name="abm_recur_interval" min="1" max="52" step="1" value="<?php echo esc_attr( (string) $rule['interval'] ); ?>" style="width:5em" />
						<span class="abm-recur-unit"></span>
					</p>

					<p class="abm-recur-weekly">
						<strong><?php esc_html_e( 'On', 'arkon-bar-manager' ); ?></strong><br />
						<?php foreach ( $day_names as $num => $label ) : ?>
							<label style="margin-right:10px;display:inline-block">
								<input type="checkbox" name="abm_recur_weekdays[]" value="<?php echo esc_attr( (string) $num ); ?>" <?php checked( in_array( $num, $weekdays, true ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
						<br /><small><?php esc_html_e( 'Leave all unchecked to use the weekday of the event date.', 'arkon-bar-manager' ); ?></small>
					</p>

					<p>
						<strong><?php esc_html_e( 'Ends', 'arkon-bar-manager' ); ?></strong><br />
						<label><input type="radio" name="abm_recur_end_mode" value="never" <?php checked( $end_mode, 'never' ); ?> /> <?php esc_html_e( 'Never', 'arkon-bar-manager' ); ?></label><br />
						<label><input type="radio" name="abm_recur_end_mode" value="until" <?php checked( $end_mode, 'until' ); ?> /> <?php esc_html_e( 'On', 'arkon-bar-manager' ); ?></label>
						<input type="date" name="abm_recur_until" value="<?php echo esc_attr( $rule['until'] ); ?>" /><br />
						<label><input type="radio" name="abm_recur_end_mode" value="count" <?php checked( $end_mode, 'count' ); ?> /> <?php esc_html_e( 'After', 'arkon-bar-manager' ); ?></label>
						<input type="number" name="abm_recur_count" min="1" max="1000" step="1" value="<?php echo esc_attr( (string) ( $rule['count'] ?: '' ) ); ?>" style="width:5em" />
						<?php esc_html_e( 'occurrences', 'arkon-bar-manager' ); ?>
					</p>

					<p>
						<label for="abm_recur_exceptions"><strong><?php esc_html_e( 'Skip these dates', 'arkon-bar-manager' ); ?></strong></label><br />
						<input type="text" id="abm_recur_exceptions" name="abm_recur_exceptions" class="widefat" value="<?php echo esc_attr( implode( ', ', $rule['exceptions'] ) ); ?>" placeholder="2026-12-24, 2026-12-25" />
						<br /><small><?php esc_html_e( 'Comma-separated YYYY-MM-DD. Useful for holidays the bar is closed.', 'arkon-bar-manager' ); ?></small>
					</p>

					<p class="abm-recur-count-note">
						<?php
						printf(
							/* translators: %s: number of generated dates. */
							esc_html__( 'Currently generating %s dates.', 'arkon-bar-manager' ),
							'<strong>' . esc_html( number_format_i18n( $occ_count ) ) . '</strong>'
						);
						?>
						<br /><small><?php
						printf(
							/* translators: %s: horizon in months. */
							esc_html__( 'Open-ended repeats are generated %s months ahead and extended automatically.', 'arkon-bar-manager' ),
							esc_html( number_format_i18n( ABM_Occurrences::horizon_months() ) )
						);
						?></small>
					</p>
				</div>
			</fieldset>

			<p>
				<label for="abm_event_cost"><strong><?php esc_html_e( 'Event Cost', 'arkon-bar-manager' ); ?></strong></label><br />
				<input type="text" id="abm_event_cost" name="abm_event_cost" value="<?php echo esc_attr( $cost ); ?>" placeholder="<?php esc_attr_e( 'e.g. 10 or Free', 'arkon-bar-manager' ); ?>" />
				<br />
				<small><?php esc_html_e( 'Leave empty for no cover. A plain number gets the currency symbol (10 → $10); text such as "Free" shows as typed.', 'arkon-bar-manager' ); ?></small>
			</p>

			<p>
				<label for="abm_show_category"><strong><?php esc_html_e( 'Category Tag (calendar)', 'arkon-bar-manager' ); ?></strong></label><br />
				<select id="abm_show_category" name="abm_show_category">
					<option value="" <?php selected( $showcat, '' ); ?>><?php esc_html_e( 'Default (use global setting)', 'arkon-bar-manager' ); ?></option>
					<option value="show" <?php selected( $showcat, 'show' ); ?>><?php esc_html_e( 'Show', 'arkon-bar-manager' ); ?></option>
					<option value="hide" <?php selected( $showcat, 'hide' ); ?>><?php esc_html_e( 'Hide', 'arkon-bar-manager' ); ?></option>
				</select>
				<br />
				<small><?php esc_html_e( 'Whether this event’s category shows in the [abm_calendar] list.', 'arkon-bar-manager' ); ?></small>
			</p>

			<p>
				<strong><?php esc_html_e( 'Event Flyer', 'arkon-bar-manager' ); ?></strong><br />
				<small><?php esc_html_e( 'Set the flyer with the Featured Image panel. If none is set, the global placeholder image (Settings) is used.', 'arkon-bar-manager' ); ?></small>
			</p>
		</div>
		<?php
	}

	/**
	 * Persist submitted fields after validation + capability/nonce checks.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		// Bail on autosave / revisions / missing nonce / insufficient caps.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$date  = abm_sanitize_date( wp_unslash( $_POST['abm_event_date'] ?? '' ) );
		$start = abm_sanitize_time( wp_unslash( $_POST['abm_event_time_start'] ?? '' ) );

		$mode = isset( $_POST['abm_end_mode'] ) ? sanitize_key( wp_unslash( $_POST['abm_end_mode'] ) ) : 'time';
		if ( 'close' === $mode ) {
			$end = 'close';
		} else {
			$end = abm_sanitize_time( wp_unslash( $_POST['abm_event_time_end'] ?? '' ) );
		}

		$cost = sanitize_text_field( wp_unslash( $_POST['abm_event_cost'] ?? '' ) );

		$show_cat = isset( $_POST['abm_show_category'] ) ? sanitize_key( wp_unslash( $_POST['abm_show_category'] ) ) : '';
		if ( ! in_array( $show_cat, array( 'show', 'hide' ), true ) ) {
			$show_cat = '';
		}

		update_post_meta( $post_id, 'abm_event_date', $date );
		update_post_meta( $post_id, 'abm_event_time_start', $start );
		update_post_meta( $post_id, 'abm_event_time_end', $end );
		update_post_meta( $post_id, 'abm_event_cost', $cost );
		update_post_meta( $post_id, 'abm_show_category', $show_cat );
		update_post_meta( $post_id, 'abm_display_start_only', isset( $_POST['abm_display_start_only'] ) ? 1 : 0 );

		// Recurrence. ABM_Occurrences (priority 25) re-expands from these.
		$recur_type = isset( $_POST['abm_recur_type'] ) ? sanitize_key( wp_unslash( $_POST['abm_recur_type'] ) ) : '';
		if ( ! in_array( $recur_type, array( 'daily', 'weekly', 'monthly_date', 'monthly_day' ), true ) ) {
			$recur_type = '';
		}

		$weekdays = array();
		if ( isset( $_POST['abm_recur_weekdays'] ) && is_array( $_POST['abm_recur_weekdays'] ) ) {
			foreach ( wp_unslash( $_POST['abm_recur_weekdays'] ) as $wd ) {
				$wd = (int) $wd;
				if ( $wd >= 0 && $wd <= 6 ) {
					$weekdays[] = $wd;
				}
			}
			$weekdays = array_values( array_unique( $weekdays ) );
			sort( $weekdays );
		}

		$end_mode = isset( $_POST['abm_recur_end_mode'] ) ? sanitize_key( wp_unslash( $_POST['abm_recur_end_mode'] ) ) : 'never';
		$until    = '';
		$rcount   = 0;
		if ( 'until' === $end_mode ) {
			$until = abm_sanitize_date( wp_unslash( $_POST['abm_recur_until'] ?? '' ) );
		} elseif ( 'count' === $end_mode ) {
			$rcount = max( 0, min( 1000, (int) ( $_POST['abm_recur_count'] ?? 0 ) ) );
		}

		$exceptions = array_values(
			array_filter(
				array_map(
					'abm_sanitize_date',
					array_map( 'trim', explode( ',', (string) wp_unslash( $_POST['abm_recur_exceptions'] ?? '' ) ) )
				),
				'strlen'
			)
		);

		update_post_meta( $post_id, 'abm_recur_type', $recur_type );
		update_post_meta( $post_id, 'abm_recur_interval', max( 1, min( 52, (int) ( $_POST['abm_recur_interval'] ?? 1 ) ) ) );
		update_post_meta( $post_id, 'abm_recur_weekdays', implode( ',', $weekdays ) );
		update_post_meta( $post_id, 'abm_recur_until', $until );
		update_post_meta( $post_id, 'abm_recur_count', $rcount );
		update_post_meta( $post_id, 'abm_recur_exceptions', implode( ',', $exceptions ) );

		// Pre-formatted display strings for the frontend.
		update_post_meta( $post_id, 'abm_date_display', abm_format_date( $date ) );
		update_post_meta( $post_id, 'abm_time_display', abm_format_time_range( $start, $end ) );
	}

	/**
	 * Recompute permalink-dependent + placeholder-aware values. Runs after save()
	 * (priority 20) so the post slug/permalink is final.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function sync_derived( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$date  = get_post_meta( $post_id, 'abm_event_date', true );
		$start = get_post_meta( $post_id, 'abm_event_time_start', true );
		$end   = get_post_meta( $post_id, 'abm_event_time_end', true );

		// The flyer IS the Featured Image; mirror it into abm_flyer_id for the
		// frontend (Dynamic Content / shortcodes).
		$flyer_id = (int) get_post_thumbnail_id( $post_id );
		update_post_meta( $post_id, 'abm_flyer_id', $flyer_id );

		// Recompute display strings here too so a global format change (via the
		// settings resync) reaches existing events, not just newly saved ones.
		update_post_meta( $post_id, 'abm_date_display', abm_format_date( $date ) );
		update_post_meta( $post_id, 'abm_time_display', abm_format_time_range( $start, $end ) );
		update_post_meta( $post_id, 'abm_cost_display', abm_format_cost( get_post_meta( $post_id, 'abm_event_cost', true ) ) );
		update_post_meta( $post_id, 'abm_flyer_url', abm_resolve_flyer_url( $post_id, $flyer_id ) );
		update_post_meta( $post_id, 'abm_ical', abm_ical_url( $post_id ) );
		update_post_meta( $post_id, 'abm_gcal', abm_build_gcal_url( $post_id, $date, $start, $end ) );
	}

	/**
	 * Re-sync derived meta for every event. Runs when plugin settings change so
	 * placeholder / close-time / start-date-clamp updates reach existing events.
	 */
	public function resync_all() {
		$ids = get_posts(
			array(
				'post_type'        => ABM_POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			$this->sync_derived( $id, get_post( $id ) );
		}
	}
}
