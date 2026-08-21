<?php
/**
 * Admin: top-level menu, settings page, list-table columns / sorting /
 * "upcoming vs past" filter, and asset enqueueing.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Admin {

	/** @var ABM_Admin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// List table: columns, sorting and the upcoming/past filter.
		add_filter( 'manage_' . ABM_POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . ABM_POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . ABM_POST_TYPE . '_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'restrict_manage_posts', array( $this, 'when_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'admin_query' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Menu                                                                  */
	/* --------------------------------------------------------------------- */

	public function register_menu() {
		// Top-level menu whose landing screen IS the events list.
		add_menu_page(
			__( 'Event Manager', 'arkon-bar-manager' ),
			__( 'Event Manager', 'arkon-bar-manager' ),
			'edit_posts',
			'edit.php?post_type=' . ABM_POST_TYPE,
			'',
			'dashicons-beer',
			25
		);

		add_submenu_page(
			'edit.php?post_type=' . ABM_POST_TYPE,
			__( 'Add Event', 'arkon-bar-manager' ),
			__( 'Add Event', 'arkon-bar-manager' ),
			'edit_posts',
			'post-new.php?post_type=' . ABM_POST_TYPE
		);

		add_submenu_page(
			'edit.php?post_type=' . ABM_POST_TYPE,
			__( 'Categories', 'arkon-bar-manager' ),
			__( 'Categories', 'arkon-bar-manager' ),
			'manage_categories',
			'edit-tags.php?taxonomy=' . ABM_TAXONOMY . '&post_type=' . ABM_POST_TYPE
		);

		add_submenu_page(
			'edit.php?post_type=' . ABM_POST_TYPE,
			__( 'Settings', 'arkon-bar-manager' ),
			__( 'Settings', 'arkon-bar-manager' ),
			'manage_options',
			'abm-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Settings                                                              */
	/* --------------------------------------------------------------------- */

	public function register_settings() {
		register_setting(
			'abm_settings_group',
			ABM_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * @param array $input Raw submitted settings.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		// Start from what is already stored, so keys this form does not render are
		// not silently dropped. recur_horizon_months, legacy_base and
		// calendar_page_id are read by the plugin but have no field here, and
		// rebuilding the array from scratch deleted them on every save.
		//
		// Every key the form does manage is overwritten unconditionally below, so
		// an unticked checkbox still resolves to 0 rather than keeping its old
		// value.
		$existing = get_option( ABM_SETTINGS, array() );
		$out      = is_array( $existing ) ? $existing : array();

		$out['placeholder_id'] = isset( $input['placeholder_id'] ) ? absint( $input['placeholder_id'] ) : 0;
		if ( $out['placeholder_id'] && 'attachment' !== get_post_type( $out['placeholder_id'] ) ) {
			$out['placeholder_id'] = 0;
		}

		$close = abm_sanitize_time( $input['close_time'] ?? '' );
		$out['close_time'] = ( $close && 'close' !== $close ) ? $close : '02:00';

		$out['venue_name']    = sanitize_text_field( $input['venue_name'] ?? '' );
		$out['venue_address'] = sanitize_text_field( $input['venue_address'] ?? '' );

		$symbol = sanitize_text_field( $input['currency_symbol'] ?? '' );
		$out['currency_symbol'] = ( '' !== $symbol ) ? mb_substr( $symbol, 0, 3 ) : '$';

		$out['date_format'] = sanitize_text_field( $input['date_format'] ?? '' );

		// Clamped to the same 1-120 range ABM_Occurrences::horizon_months() enforces
		// when reading it, so the stored value and the effective value never differ.
		// Blank or zero means "use the default" rather than "one month".
		$horizon                     = absint( $input['recur_horizon_months'] ?? 0 );
		$out['recur_horizon_months'] = $horizon
			? max( 1, min( 120, $horizon ) )
			: ABM_Occurrences::DEFAULT_HORIZON_MONTHS;

		$out['calendar_initial']        = max( 1, absint( $input['calendar_initial'] ?? 10 ) );
		$out['calendar_load_more']      = max( 1, absint( $input['calendar_load_more'] ?? 10 ) );
		$out['calendar_show_categories'] = empty( $input['calendar_show_categories'] ) ? 0 : 1;

		return $out;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$placeholder_id  = absint( abm_get_setting( 'placeholder_id', 0 ) );
		$placeholder_url = $placeholder_id ? wp_get_attachment_image_url( $placeholder_id, 'medium' ) : '';
		$close_time      = esc_attr( abm_get_setting( 'close_time', '02:00' ) );
		$venue_name      = esc_attr( abm_get_setting( 'venue_name', '' ) );
		$venue_address   = esc_attr( abm_get_setting( 'venue_address', '' ) );
		$currency_symbol = esc_attr( abm_get_setting( 'currency_symbol', '$' ) );
		$date_format     = esc_attr( abm_get_setting( 'date_format', 'j M' ) );
		$date_preview    = esc_html( date_i18n( abm_get_setting( 'date_format', 'j M' ) ?: 'j M', current_datetime()->getTimestamp() ) );
		$recur_horizon   = (int) ABM_Occurrences::horizon_months();
		$cal_initial     = (int) abm_get_setting( 'calendar_initial', 10 );
		$cal_load_more   = (int) abm_get_setting( 'calendar_load_more', 10 );
		$cal_show_cats   = (int) abm_get_setting( 'calendar_show_categories', 1 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Arkon Event Manager Settings', 'arkon-bar-manager' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'abm_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Global Flyer Placeholder', 'arkon-bar-manager' ); ?></th>
						<td>
							<span class="abm-placeholder-preview">
								<?php if ( $placeholder_url ) : ?>
									<img src="<?php echo esc_url( $placeholder_url ); ?>" alt="" style="max-width:200px;height:auto;display:block;margin:6px 0;" />
								<?php endif; ?>
							</span>
							<input type="hidden" id="abm_placeholder_id" name="<?php echo esc_attr( ABM_SETTINGS ); ?>[placeholder_id]" value="<?php echo esc_attr( $placeholder_id ); ?>" />
							<button type="button" class="button abm-placeholder-upload"><?php esc_html_e( 'Select / Upload Image', 'arkon-bar-manager' ); ?></button>
							<button type="button" class="button abm-placeholder-remove" <?php echo $placeholder_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'arkon-bar-manager' ); ?></button>
							<p class="description"><?php esc_html_e( 'Used when an event has no flyer of its own.', 'arkon-bar-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abm_close_time"><?php esc_html_e( 'Default "Close" Time', 'arkon-bar-manager' ); ?></label></th>
						<td>
							<input type="time" id="abm_close_time" name="<?php echo esc_attr( ABM_SETTINGS ); ?>[close_time]" value="<?php echo $close_time; ?>" />
							<p class="description"><?php esc_html_e( 'Used for calendar exports when an event ends at "Close". The frontend still displays "Close".', 'arkon-bar-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abm_currency_symbol"><?php esc_html_e( 'Currency Symbol', 'arkon-bar-manager' ); ?></label></th>
						<td>
							<input type="text" class="small-text" id="abm_currency_symbol" name="<?php echo esc_attr( ABM_SETTINGS ); ?>[currency_symbol]" value="<?php echo $currency_symbol; ?>" maxlength="3" />
							<p class="description"><?php esc_html_e( 'Prepended to numeric event costs (e.g. 10 → $10).', 'arkon-bar-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abm_date_format"><?php esc_html_e( 'Date Format', 'arkon-bar-manager' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="abm_date_format" name="<?php echo esc_attr( ABM_SETTINGS ); ?>[date_format]" value="<?php echo $date_format; ?>" placeholder="j M" />
							<p class="description">
								<?php esc_html_e( 'PHP date format for event dates. Preview:', 'arkon-bar-manager' ); ?> <code><?php echo $date_preview; ?></code><br />
								<?php esc_html_e( 'Tokens: j = 21, d = 08, D = Sat, l = Saturday, M = Jun, F = June, m = 06, n = 6, Y = 2026, y = 26. Examples: “M j” → Jun 21 · “l, F jS” → Saturday, June 21st · “m/d/Y” → 06/21/2026. Leave blank for the default (j M).', 'arkon-bar-manager' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abm_venue_name"><?php esc_html_e( 'Venue Name', 'arkon-bar-manager' ); ?></label></th>
						<td><input type="text" class="regular-text" id="abm_venue_name" name="<?php echo esc_attr( ABM_SETTINGS ); ?>[venue_name]" value="<?php echo $venue_name; ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="abm_venue_address"><?php esc_html_e( 'Venue Address', 'arkon-bar-manager' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="abm_venue_address" name="<?php echo esc_attr( ABM_SETTINGS ); ?>[venue_address]" value="<?php echo $venue_address; ?>" />
							<p class="description"><?php esc_html_e( 'Added to the location field of calendar exports.', 'arkon-bar-manager' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Repeating Events', 'arkon-bar-manager' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="abm_recur_horizon_months"><?php esc_html_e( 'Generate Ahead', 'arkon-bar-manager' ); ?></label></th>
						<td>
							<input type="number" min="1" max="120" step="1" class="small-text" id="abm_recur_horizon_months" name="<?php echo esc_attr( ABM_SETTINGS ); ?>[recur_horizon_months]" value="<?php echo esc_attr( (string) $recur_horizon ); ?>" />
							<?php esc_html_e( 'months', 'arkon-bar-manager' ); ?>
							<p class="description">
								<?php esc_html_e( 'How far ahead an event with an open-ended repeat generates dates. Measured from today and rolled forward daily, so a repeat never runs dry. 1-120 months; the default is 24. Changing this re-expands every open-ended event immediately.', 'arkon-bar-manager' ); ?><br />
								<?php esc_html_e( 'This governs events driven by a recurrence rule only. Events imported from Modern Events Calendar carry their dates verbatim from the source and are not affected by this setting; bound those with "Skip dates before" on the Migrate & Tools screen instead.', 'arkon-bar-manager' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Calendar Shortcode', 'arkon-bar-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Controls the [abm_calendar] event list.', 'arkon-bar-manager' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="abm_calendar_initial"><?php esc_html_e( 'Events Loaded Initially', 'arkon-bar-manager' ); ?></label></th>
						<td>
							<input type="number" min="1" step="1" class="small-text" id="abm_calendar_initial" name="<?php echo esc_attr( ABM_SETTINGS ); ?>[calendar_initial]" value="<?php echo esc_attr( $cal_initial ); ?>" />
							<p class="description"><?php esc_html_e( 'Number of upcoming events shown before the Load More button.', 'arkon-bar-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abm_calendar_load_more"><?php esc_html_e( 'Events Per “Load More”', 'arkon-bar-manager' ); ?></label></th>
						<td>
							<input type="number" min="1" step="1" class="small-text" id="abm_calendar_load_more" name="<?php echo esc_attr( ABM_SETTINGS ); ?>[calendar_load_more]" value="<?php echo esc_attr( $cal_load_more ); ?>" />
							<p class="description"><?php esc_html_e( 'Number of additional events loaded each time Load More is clicked.', 'arkon-bar-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Category Tags', 'arkon-bar-manager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( ABM_SETTINGS ); ?>[calendar_show_categories]" value="1" <?php checked( $cal_show_cats, 1 ); ?> />
								<?php esc_html_e( 'Show category tags (Music, Event, …) in the calendar list', 'arkon-bar-manager' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Any event can override this under Event Details → Category Tag.', 'arkon-bar-manager' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* List table                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['abm_flyer'] = __( 'Flyer', 'arkon-bar-manager' );
			}
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['abm_when'] = __( 'Date', 'arkon-bar-manager' );
				$new['abm_time'] = __( 'Time', 'arkon-bar-manager' );
			}
		}
		return $new;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'abm_flyer':
				$flyer_id = absint( get_post_meta( $post_id, 'abm_flyer_id', true ) );
				$url      = abm_resolve_flyer_url( $post_id, $flyer_id, 'thumbnail' );
				if ( $url ) {
					printf( '<img src="%s" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:4px;" />', esc_url( $url ) );
				} else {
					echo '&mdash;';
				}
				break;

			case 'abm_when':
				// The next date this actually happens, not abm_date_display —
				// that is the series *start*, so a weekly night running since
				// 2019 would show 2019 here for ever.
				$next = ABM_Occurrences::next_date( $post_id );
				if ( $next ) {
					echo esc_html( abm_format_date( $next ) );
					if ( ABM_Occurrences::is_recurring( $post_id ) ) {
						echo ' <span class="abm-repeats" title="' . esc_attr__( 'Repeats', 'arkon-bar-manager' ) . '">&#8635;</span>';
					}
				} else {
					$display = get_post_meta( $post_id, 'abm_date_display', true );
					// No upcoming date: show the last one it had, so a finished
					// event still reads sensibly instead of as a dash.
					echo $display ? '<span style="color:#888">' . esc_html( $display ) . '</span>' : '&mdash;';
				}
				break;

			case 'abm_time':
				$display = get_post_meta( $post_id, 'abm_time_display', true );
				echo $display ? esc_html( $display ) : '&mdash;';
				break;
		}
	}

	/**
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function sortable_columns( $columns ) {
		$columns['abm_when'] = 'abm_event_date';
		return $columns;
	}

	/**
	 * Render the All / Upcoming / Past dropdown on the events list screen.
	 */
	public function when_filter() {
		global $typenow;
		if ( ABM_POST_TYPE !== $typenow ) {
			return;
		}
		$current = isset( $_GET['abm_when'] ) ? sanitize_key( wp_unslash( $_GET['abm_when'] ) ) : '';
		?>
		<select name="abm_when">
			<option value=""><?php esc_html_e( 'All dates', 'arkon-bar-manager' ); ?></option>
			<option value="upcoming" <?php selected( $current, 'upcoming' ); ?>><?php esc_html_e( 'Upcoming', 'arkon-bar-manager' ); ?></option>
			<option value="past" <?php selected( $current, 'past' ); ?>><?php esc_html_e( 'Past', 'arkon-bar-manager' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Default ordering by event date + apply the upcoming/past filter in admin.
	 *
	 * @param WP_Query $query Query.
	 */
	public function admin_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( ABM_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// Everything below sorts and filters on the occurrence table rather than
		// the abm_event_date meta. That meta is the series *start*, so a weekly
		// night that began in 2019 sorts to the very top for ever and lands under
		// "Past" even though it runs this Monday — on the screen used to manage
		// events daily, which makes it the worst place for that bug to live.
		$when = isset( $_GET['abm_when'] ) ? sanitize_key( wp_unslash( $_GET['abm_when'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $when, array( 'upcoming', 'past' ), true ) ) {
			$when = '';
		}

		$query->set( 'abm_admin_when', $when );

		// Leave an explicit column sort alone; only own the default ordering.
		$owns_order = ! $query->get( 'orderby' ) || 'abm_event_date' === $query->get( 'orderby' );
		$query->set( 'abm_admin_order_by_next', $owns_order );

		// WP_Query defaults `order` to DESC. For a list of events the useful
		// default is soonest-first, so force ASC unless the user actually clicked
		// a column header to choose a direction.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $owns_order && ! isset( $_GET['order'] ) ) {
			$query->set( 'order', 'ASC' );
		}

		if ( '' !== $when || $query->get( 'abm_admin_order_by_next' ) ) {
			add_filter( 'posts_clauses', array( $this, 'admin_query_clauses' ), 10, 2 );
		}
	}

	/**
	 * Join the occurrence table for the events list screen.
	 *
	 * "Upcoming" means the event has at least one date still to come.
	 * "Past" means it has none — which is the only definition that makes sense
	 * for something that repeats.
	 *
	 * @param array    $clauses Query clauses.
	 * @param WP_Query $query   Query.
	 * @return array
	 */
	public function admin_query_clauses( $clauses, $query ) {
		if ( ! $query->get( 'abm_admin_when' ) && ! $query->get( 'abm_admin_order_by_next' ) ) {
			return $clauses;
		}

		// One query, one application.
		remove_filter( 'posts_clauses', array( $this, 'admin_query_clauses' ), 10 );

		global $wpdb;
		$occ   = ABM_Occurrences::table();
		$today = current_time( 'Y-m-d' );
		$when  = (string) $query->get( 'abm_admin_when' );

		$sub = $wpdb->prepare(
			"( SELECT post_id, MIN(occur_date) AS abm_next_date FROM {$occ}
			   WHERE occur_date >= %s GROUP BY post_id )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$today
		);

		if ( 'past' === $when ) {
			// No upcoming occurrence at all.
			$clauses['join']  .= " LEFT JOIN {$sub} abm_next ON abm_next.post_id = {$wpdb->posts}.ID ";
			$clauses['where'] .= ' AND abm_next.post_id IS NULL ';
			if ( $query->get( 'abm_admin_order_by_next' ) ) {
				// Most recently finished first.
				$clauses['orderby'] = "{$wpdb->posts}.post_date DESC";
			}
			return $clauses;
		}

		if ( 'upcoming' === $when ) {
			$clauses['join'] .= " INNER JOIN {$sub} abm_next ON abm_next.post_id = {$wpdb->posts}.ID ";
		} else {
			// "All": still order by next date, but do not hide finished events.
			$clauses['join'] .= " LEFT JOIN {$sub} abm_next ON abm_next.post_id = {$wpdb->posts}.ID ";
		}

		if ( $query->get( 'abm_admin_order_by_next' ) ) {
			$order = ( 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ) ? 'DESC' : 'ASC';
			// Finished events sort last under "All" rather than first, which is
			// what a NULL next_date would otherwise do on an ASC sort.
			$clauses['orderby'] = "abm_next.abm_next_date IS NULL ASC, abm_next.abm_next_date {$order}, {$wpdb->posts}.ID ASC";
		}

		return $clauses;
	}

	/* --------------------------------------------------------------------- */
	/* Assets                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * @param string $hook Current admin page.
	 */
	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_event_edit = $screen && ABM_POST_TYPE === $screen->post_type && in_array( $screen->base, array( 'post', 'post-new' ), true );
		$is_settings   = ( false !== strpos( (string) $hook, 'abm-settings' ) );

		if ( ! $is_event_edit && ! $is_settings ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'abm-admin', ABM_URL . 'assets/admin.css', array(), ABM_VERSION );
		wp_enqueue_script( 'abm-admin', ABM_URL . 'assets/admin.js', array( 'jquery' ), ABM_VERSION, true );
		wp_localize_script(
			'abm-admin',
			'ABM',
			array(
				'frameTitle'  => __( 'Select Image', 'arkon-bar-manager' ),
				'frameButton' => __( 'Use this image', 'arkon-bar-manager' ),
				'unitDays'    => __( 'day(s)', 'arkon-bar-manager' ),
				'unitWeeks'   => __( 'week(s)', 'arkon-bar-manager' ),
				'unitMonths'  => __( 'month(s)', 'arkon-bar-manager' ),
			)
		);
	}
}
