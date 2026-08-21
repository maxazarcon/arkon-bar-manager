<?php
/**
 * [abm_calendar] shortcode: a month-grouped list of upcoming event occurrences
 * with collapsible month headings and an AJAX "Load More" button.
 *
 * Pagination walks the occurrence table with a keyset cursor rather than a
 * numeric offset. Two reasons: a recurring event contributes many rows from one
 * post, so post offsets are meaningless here; and a keyset cursor cannot skip or
 * repeat a row when an event is published between two Load More clicks.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Calendar {

	/** @var ABM_Calendar|null */
	private static $instance = null;

	/** Matches the live MEC list skin: 6 on first paint, 6 per Load More. */
	const DEFAULT_INITIAL = 6;
	const DEFAULT_MORE    = 6;
	const MAX_BATCH       = 50;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_shortcode( 'abm_calendar', array( $this, 'shortcode' ) );
		add_action( 'wp_ajax_abm_load_events', array( $this, 'ajax_load' ) );
		add_action( 'wp_ajax_nopriv_abm_load_events', array( $this, 'ajax_load' ) );
	}

	public function register_assets() {
		wp_register_style( 'abm-calendar', ABM_URL . 'assets/calendar.css', array(), ABM_VERSION );
		wp_register_script( 'abm-calendar', ABM_URL . 'assets/calendar.js', array(), ABM_VERSION, true );
	}

	/* --------------------------------------------------------------------- */
	/* Shortcode                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'initial'  => '',
				'more'     => '',
				'category' => '',
			),
			$atts,
			'abm_calendar'
		);

		$initial = ( '' !== $atts['initial'] )
			? max( 1, (int) $atts['initial'] )
			: max( 1, (int) abm_get_setting( 'calendar_initial', self::DEFAULT_INITIAL ) );
		$more = ( '' !== $atts['more'] )
			? max( 1, (int) $atts['more'] )
			: max( 1, (int) abm_get_setting( 'calendar_load_more', self::DEFAULT_MORE ) );

		wp_enqueue_style( 'abm-calendar' );
		wp_enqueue_script( 'abm-calendar' );

		$category = sanitize_title( $atts['category'] );
		$batch    = $this->render_batch( '', $initial, '', $category );

		$list = $batch['html'];
		if ( '' === $list ) {
			$list = '<p class="abm-empty">' . esc_html__( 'No upcoming events.', 'arkon-bar-manager' ) . '</p>';
		}

		ob_start();
		?>
		<div class="abm-calendar"
			data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-cursor="<?php echo esc_attr( $batch['cursor'] ); ?>"
			data-more="<?php echo esc_attr( (string) $more ); ?>"
			data-category="<?php echo esc_attr( $category ); ?>"
			data-last-month="<?php echo esc_attr( $batch['last_month'] ); ?>">
			<div class="abm-calendar-list"><?php echo $list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from individually escaped parts in render_event(). ?></div>
			<div class="abm-calendar-more"<?php echo $batch['has_more'] ? '' : ' hidden'; ?>>
				<button type="button" class="abm-load-more" data-loading="<?php esc_attr_e( 'Loading…', 'arkon-bar-manager' ); ?>"><?php esc_html_e( 'Load More', 'arkon-bar-manager' ); ?></button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------- */
	/* AJAX                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Public read-only pagination of published upcoming occurrences. No nonce:
	 * this exposes nothing that isn't already public, and a nonce would break
	 * under full-page caching. Batch size is clamped to keep queries bounded.
	 */
	public function ajax_load() {
		$cursor   = isset( $_POST['cursor'] ) ? sanitize_text_field( wp_unslash( $_POST['cursor'] ) ) : '';
		$count    = isset( $_POST['count'] ) ? absint( wp_unslash( $_POST['count'] ) ) : self::DEFAULT_MORE;
		$last     = isset( $_POST['last_month'] ) ? sanitize_text_field( wp_unslash( $_POST['last_month'] ) ) : '';
		$category = isset( $_POST['category'] ) ? sanitize_title( wp_unslash( $_POST['category'] ) ) : '';

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $last ) ) {
			$last = '';
		}

		wp_send_json_success( $this->render_batch( $cursor, $count, $last, $category ) );
	}

	/* --------------------------------------------------------------------- */
	/* Querying                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Decode a "Y-m-d|H:i|id" cursor. Anything malformed reads as "from the
	 * start", which is the safe failure mode.
	 *
	 * @param string $cursor Raw cursor.
	 * @return array{date:string,time:string,id:int}|null
	 */
	private function parse_cursor( $cursor ) {
		$parts = explode( '|', (string) $cursor );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$date = abm_sanitize_date( $parts[0] );
		if ( '' === $date ) {
			return null;
		}
		$time = preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $parts[1] ) ? $parts[1] : '';
		return array(
			'date' => $date,
			'time' => $time,
			'id'   => absint( $parts[2] ),
		);
	}

	/**
	 * Fetch the next page of occurrences after the cursor.
	 *
	 * @param array|null $cursor   Decoded cursor.
	 * @param int        $limit    How many rows.
	 * @param string     $category Optional category slug filter.
	 * @return array<int,object>
	 */
	private function query_occurrences( $cursor, $limit, $category = '' ) {
		global $wpdb;

		$occ   = ABM_Occurrences::table();
		$posts = $wpdb->posts;
		$today = current_time( 'Y-m-d' );

		$where = array( 'p.post_type = %s', "p.post_status = 'publish'", 'o.occur_date >= %s' );
		$args  = array( ABM_POST_TYPE, $today );

		if ( $cursor ) {
			// Strict keyset comparison on the exact index order.
			$where[] = '( o.occur_date > %s OR ( o.occur_date = %s AND ( o.start_time > %s OR ( o.start_time = %s AND o.id > %d ) ) ) )';
			array_push( $args, $cursor['date'], $cursor['date'], $cursor['time'], $cursor['time'], $cursor['id'] );
		}

		$join = '';
		if ( '' !== $category ) {
			$join  = " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id ";
			$where[] = 'tt.taxonomy = %s';
			$where[] = 't.slug = %s';
			array_push( $args, ABM_TAXONOMY, $category );
		}

		// One extra row tells us whether a Load More button is still warranted
		// without running a second COUNT query.
		$args[] = $limit + 1;

		$sql = "SELECT o.id, o.post_id, o.occur_date, o.start_time, o.end_time
			FROM {$occ} o
			INNER JOIN {$posts} p ON p.ID = o.post_id
			{$join}
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY o.occur_date ASC, o.start_time ASC, o.id ASC
			LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	/* --------------------------------------------------------------------- */
	/* Rendering                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Render a batch of occurrences with month dividers.
	 *
	 * @param string $cursor     Opaque cursor from the previous batch, or ''.
	 * @param int    $count      How many to render.
	 * @param string $last_month Y-m of the last month already shown, so a month
	 *                           split across two batches doesn't get a second heading.
	 * @param string $category   Optional category slug.
	 * @return array{html:string,cursor:string,last_month:string,has_more:bool}
	 */
	public function render_batch( $cursor, $count, $last_month, $category = '' ) {
		$count   = min( self::MAX_BATCH, max( 1, (int) $count ) );
		$decoded = $this->parse_cursor( $cursor );
		$rows    = $this->query_occurrences( $decoded, $count, $category );

		$has_more = count( $rows ) > $count;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$html          = '';
		$current_month = (string) $last_month;
		$new_cursor    = (string) $cursor;

		foreach ( $rows as $row ) {
			$mkey = substr( $row->occur_date, 0, 7 );
			if ( $mkey !== $current_month ) {
				$html         .= $this->render_month_divider( $row->occur_date, $mkey );
				$current_month = $mkey;
			}
			$html      .= $this->render_event( $row, $mkey );
			$new_cursor = $row->occur_date . '|' . $row->start_time . '|' . (int) $row->id;
		}

		return array(
			'html'       => $html,
			'cursor'     => $new_cursor,
			'last_month' => $current_month,
			'has_more'   => $has_more,
		);
	}

	/**
	 * A month heading that doubles as a collapse toggle.
	 *
	 * The button carries the month key so the JS can find every article in that
	 * month, including ones appended later by Load More.
	 *
	 * @param string $date Any date inside the month.
	 * @param string $mkey Y-m.
	 * @return string
	 */
	private function render_month_divider( $date, $mkey ) {
		$label = date_i18n( 'F Y', abm_date_to_timestamp( $date ) );
		$slug  = 'abm-m-' . str_replace( '-', '', $mkey );

		$chevron = '<svg class="abm-month-chevron" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';

		return sprintf(
			'<div class="abm-month" data-month="%1$s"><button type="button" class="abm-month-toggle" aria-expanded="true" aria-controls="%1$s"><span class="abm-month-label">%2$s</span>%3$s</button></div>',
			esc_attr( $slug ),
			esc_html( $label ),
			$chevron
		);
	}

	/**
	 * Render a single occurrence row.
	 *
	 * Date and time come from the occurrence, not from the post's cached display
	 * meta: a recurring event has one post but many dates, so the cached string
	 * would be wrong for every row but one.
	 *
	 * @param object $row  Occurrence row.
	 * @param string $mkey Y-m, used for the collapse class.
	 * @return string
	 */
	private function render_event( $row, $mkey ) {
		$post_id   = (int) $row->post_id;
		$permalink = get_permalink( $post_id );
		$title     = get_the_title( $post_id );
		$slug      = 'abm-m-' . str_replace( '-', '', $mkey );

		// Point a recurring event's row at its own date so the single view can
		// show the occurrence the visitor actually clicked.
		$link = $permalink;
		if ( $permalink && ABM_Occurrences::is_recurring( $post_id ) ) {
			$link = add_query_arg( 'occ', $row->occur_date, $permalink );
		}

		$flyer = get_post_meta( $post_id, 'abm_flyer_url', true );
		if ( '' === $flyer ) {
			$flyer = abm_resolve_flyer_url( $post_id, absint( get_post_meta( $post_id, 'abm_flyer_id', true ) ) );
		}

		$date_d = abm_format_date( $row->occur_date );
		$time_d = abm_format_time_range( $row->start_time, $row->end_time );
		$cost_d = get_post_meta( $post_id, 'abm_cost_display', true );

		// Short description. Only 15 of the 337 imported events carry one, so this is
		// absent from most rows by design rather than by omission.
		$blurb = trim( (string) get_post_field( 'post_excerpt', $post_id ) );
		if ( '' === $blurb ) {
			$blurb = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 26, '&hellip;' );
		}

		$cats = '';
		if ( abm_show_category_for( $post_id ) ) {
			$terms = get_the_terms( $post_id, ABM_TAXONOMY );
			$cats  = ( $terms && ! is_wp_error( $terms ) ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
		}

		ob_start();
		?>
		<article class="abm-event <?php echo esc_attr( $slug ); ?>" data-month="<?php echo esc_attr( $slug ); ?>" data-date="<?php echo esc_attr( $row->occur_date ); ?>">
			<div class="abm-event-main">
				<?php if ( $flyer ) : ?>
					<a class="abm-event-flyer" href="<?php echo esc_url( $link ); ?>">
						<img src="<?php echo esc_url( $flyer ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy" />
					</a>
				<?php endif; ?>
				<div class="abm-event-title"><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $title ); ?></a></div>
				<?php if ( $blurb ) : ?>
					<p class="abm-event-blurb"><?php echo esc_html( $blurb ); ?></p>
				<?php endif; ?>
				<div class="abm-event-meta">
					<?php if ( $date_d ) : ?>
						<span class="abm-meta-row abm-meta-date"><?php echo self::icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( $date_d ); ?></span></span>
					<?php endif; ?>
					<?php if ( $time_d ) : ?>
						<span class="abm-meta-row abm-meta-time"><?php echo self::icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( $time_d ); ?></span></span>
					<?php endif; ?>
					<?php if ( $cats ) : ?>
						<span class="abm-meta-row abm-meta-cat"><?php echo self::icon( 'folder' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( $cats ); ?></span></span>
					<?php endif; ?>
					<?php if ( $cost_d ) : ?>
						<span class="abm-meta-row abm-meta-cost"><?php echo self::icon( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( $cost_d ); ?></span></span>
					<?php endif; ?>
				</div>
			</div>
			<div class="abm-event-foot"><a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'View Detail', 'arkon-bar-manager' ); ?></a></div>
		</article>
		<?php
		return ob_get_clean();
	}

	/**
	 * Inline SVG meta icons (themable via currentColor).
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	private static function icon( $name ) {
		$open  = '<svg class="abm-ico" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
		$paths = array(
			'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
			'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'folder'   => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
			'ticket'   => '<path d="M3 9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2 2 2 0 0 0 0 4 2 2 0 0 1-2 2H5a2 2 0 0 1-2-2 2 2 0 0 0 0-4Z"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return $open . $paths[ $name ] . '</svg>';
	}
}
