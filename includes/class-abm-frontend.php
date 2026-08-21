<?php
/**
 * Frontend: the /ical/ permalink endpoint, default archive ordering, and a set
 * of field shortcodes that act as the Dynamic Content bridge for the
 * pre-formatted values plus the export buttons.
 *
 * Shortcodes (all read the current post inside a Cornerstone Looper Consumer):
 *   [abm_event_date]      -> "26 Jun" (format="l, F jS" overrides the global format)
 *   [abm_event_time]      -> "8:00 PM - Close"
 *   [abm_event_category]  -> comma-separated category names
 *   [abm_cost]            -> formatted door cost (e.g. "$10")
 *   [abm_flyer_url]       -> flyer or placeholder URL
 *   [abm_flyer]           -> <img> flyer or placeholder
 *   [abm_ical]            -> .ics URL
 *   [abm_gcal]            -> Google Calendar URL
 *   [abm_event_export]    -> Google + iCal buttons
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Frontend {

	/** @var ABM_Frontend|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
		add_action( 'template_redirect', array( $this, 'maybe_output_ical' ) );
		add_action( 'pre_get_posts', array( $this, 'order_archive' ) );

		add_shortcode( 'abm_event_date', array( $this, 'sc_date' ) );
		add_shortcode( 'abm_event_time', array( $this, 'sc_time' ) );
		add_shortcode( 'abm_event_category', array( $this, 'sc_category' ) );
		add_shortcode( 'abm_cost', array( $this, 'sc_cost' ) );
		add_shortcode( 'abm_flyer_url', array( $this, 'sc_flyer_url' ) );
		add_shortcode( 'abm_flyer', array( $this, 'sc_flyer' ) );
		add_shortcode( 'abm_ical', array( $this, 'sc_ical_url' ) );
		add_shortcode( 'abm_gcal', array( $this, 'sc_gcal_url' ) );
		add_shortcode( 'abm_event_export', array( $this, 'sc_export' ) );
	}

	/**
	 * Register the /ical/ endpoint on single event permalinks.
	 */
	public static function register_endpoint() {
		add_rewrite_endpoint( ABM_ICAL_ENDPOINT, EP_PERMALINK );
	}

	/**
	 * Serve the .ics when a single event is requested with /ical/ appended.
	 */
	public function maybe_output_ical() {
		if ( ! is_singular( ABM_POST_TYPE ) ) {
			return;
		}
		global $wp_query;
		if ( ! isset( $wp_query->query_vars[ ABM_ICAL_ENDPOINT ] ) ) {
			return;
		}
		ABM_ICal::output( get_queried_object_id() );
	}

	/**
	 * Order the event category archive by each event's next upcoming date.
	 *
	 * Ordering by the abm_event_date meta is wrong for anything that repeats:
	 * that value is the series *start*, so a weekly night running since 2019
	 * sorts to the bottom of the list for ever, and still shows long after its
	 * start date has passed. The occurrence table knows the real answer, so join
	 * it and sort on the next date that hasn't happened yet.
	 *
	 * @param WP_Query $query Query.
	 */
	public function order_archive( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( ! $query->is_post_type_archive( ABM_POST_TYPE ) && ! $query->is_tax( ABM_TAXONOMY ) ) {
			return;
		}

		// Flag this exact query so the clause filter below cannot leak into any
		// other query that happens to run during the same request.
		$query->set( 'abm_order_by_occurrence', true );
		add_filter( 'posts_clauses', array( $this, 'order_archive_clauses' ), 10, 2 );
	}

	/**
	 * Join the occurrence table and sort by the next upcoming date.
	 *
	 * @param array    $clauses Query clauses.
	 * @param WP_Query $query   Query.
	 * @return array
	 */
	public function order_archive_clauses( $clauses, $query ) {
		if ( ! $query->get( 'abm_order_by_occurrence' ) ) {
			return $clauses;
		}

		// One query, one application.
		remove_filter( 'posts_clauses', array( $this, 'order_archive_clauses' ), 10 );

		global $wpdb;
		$occ = ABM_Occurrences::table();

		$clauses['join'] .= $wpdb->prepare(
			" INNER JOIN ( SELECT post_id, MIN(occur_date) AS abm_next_date
				FROM {$occ} WHERE occur_date >= %s GROUP BY post_id ) abm_next
				ON abm_next.post_id = {$wpdb->posts}.ID ", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			current_time( 'Y-m-d' )
		);

		$clauses['orderby'] = 'abm_next.abm_next_date ASC, ' . $wpdb->posts . '.ID ASC';

		return $clauses;
	}

	/* --------------------------------------------------------------------- */
	/* Shortcodes                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * Resolve a target post ID from a shortcode atts['id'] or the current loop.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return int
	 */
	private function target_id( $atts ) {
		if ( ! empty( $atts['id'] ) ) {
			return absint( $atts['id'] );
		}
		return get_the_ID();
	}

	/**
	 * Which date this request is about.
	 *
	 * A recurring event is one post with many dates, so a bare permalink is
	 * ambiguous. The calendar links each row with ?occ=Y-m-d; that value is
	 * honoured only when it is a real occurrence of this event, so a crafted URL
	 * cannot make an event advertise a date it never had. Otherwise we fall back
	 * to the next upcoming date, then to the stored start date.
	 *
	 * @param int $post_id Event ID.
	 * @return string Y-m-d
	 */
	public static function occurrence_date( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display selector on a public page.
		$requested = isset( $_GET['occ'] ) ? abm_sanitize_date( wp_unslash( $_GET['occ'] ) ) : '';
		if ( '' !== $requested && ABM_Occurrences::has_date( $post_id, $requested ) ) {
			return $requested;
		}

		$next = ABM_Occurrences::next_date( $post_id );
		if ( '' !== $next ) {
			return $next;
		}

		return (string) get_post_meta( $post_id, 'abm_event_date', true );
	}

	public function sc_date( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0, 'format' => '' ), $atts, 'abm_event_date' );
		$id   = $this->target_id( $atts );
		$date = self::occurrence_date( $id );
		return esc_html( abm_format_date( $date, $atts['format'] ) );
	}

	public function sc_time( $atts ) {
		$id = $this->target_id( (array) $atts );
		return esc_html( get_post_meta( $id, 'abm_time_display', true ) );
	}

	public function sc_category( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0, 'sep' => ', ' ), $atts, 'abm_event_category' );
		$id    = $this->target_id( $atts );
		$terms = get_the_terms( $id, ABM_TAXONOMY );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}
		$names = wp_list_pluck( $terms, 'name' );
		return esc_html( implode( $atts['sep'], $names ) );
	}

	public function sc_cost( $atts ) {
		$id = $this->target_id( (array) $atts );
		return esc_html( get_post_meta( $id, 'abm_cost_display', true ) );
	}

	public function sc_flyer_url( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0, 'size' => 'large' ), $atts, 'abm_flyer_url' );
		$id   = $this->target_id( $atts );
		$flyer_id = absint( get_post_meta( $id, 'abm_flyer_id', true ) );
		return esc_url( abm_resolve_flyer_url( $id, $flyer_id, sanitize_key( $atts['size'] ) ) );
	}

	public function sc_flyer( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0, 'size' => 'large', 'class' => 'abm-flyer' ), $atts, 'abm_flyer' );
		$id   = $this->target_id( $atts );
		$flyer_id = absint( get_post_meta( $id, 'abm_flyer_id', true ) );
		$url      = abm_resolve_flyer_url( $id, $flyer_id, sanitize_key( $atts['size'] ) );
		if ( ! $url ) {
			return '';
		}
		return sprintf(
			'<img src="%s" alt="%s" class="%s" loading="lazy" />',
			esc_url( $url ),
			esc_attr( get_the_title( $id ) ),
			esc_attr( $atts['class'] )
		);
	}

	public function sc_ical_url( $atts ) {
		$id  = $this->target_id( (array) $atts );
		$url = get_post_meta( $id, 'abm_ical', true ) ?: abm_ical_url( $id );
		if ( $url && ABM_Occurrences::is_recurring( $id ) ) {
			$url = add_query_arg( 'occ', self::occurrence_date( $id ), $url );
		}
		return esc_url( $url );
	}

	public function sc_gcal_url( $atts ) {
		$id = $this->target_id( (array) $atts );
		return esc_url( self::gcal_for_request( $id ) );
	}

	/**
	 * Google Calendar link for the occurrence being viewed. Falls back to the
	 * cached meta when the event has a single date, which keeps the old
	 * behaviour (and the cached value) for the common case.
	 *
	 * @param int $id Event ID.
	 * @return string
	 */
	private static function gcal_for_request( $id ) {
		$cached = (string) get_post_meta( $id, 'abm_gcal', true );
		if ( ! ABM_Occurrences::is_recurring( $id ) ) {
			return $cached;
		}
		$url = abm_build_gcal_url(
			$id,
			self::occurrence_date( $id ),
			(string) get_post_meta( $id, 'abm_event_time_start', true ),
			(string) get_post_meta( $id, 'abm_event_time_end', true )
		);
		return $url ? $url : $cached;
	}

	public function sc_export( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'          => 0,
				'gcal_label'  => __( 'Add to Google Calendar', 'arkon-bar-manager' ),
				'ical_label'  => __( 'Download iCal', 'arkon-bar-manager' ),
				'class'       => 'abm-export',
			),
			$atts,
			'abm_event_export'
		);
		$id   = $this->target_id( $atts );
		$gcal = self::gcal_for_request( $id );
		$ical = get_post_meta( $id, 'abm_ical', true ) ?: abm_ical_url( $id );
		if ( $ical && ABM_Occurrences::is_recurring( $id ) ) {
			$ical = add_query_arg( 'occ', self::occurrence_date( $id ), $ical );
		}

		$out  = '<div class="' . esc_attr( $atts['class'] ) . '">';
		if ( $gcal ) {
			$out .= sprintf(
				'<a class="abm-export-gcal" href="%s" target="_blank" rel="noopener noreferrer">%s</a> ',
				esc_url( $gcal ),
				esc_html( $atts['gcal_label'] )
			);
		}
		if ( $ical ) {
			$out .= sprintf(
				'<a class="abm-export-ical" href="%s">%s</a>',
				esc_url( $ical ),
				esc_html( $atts['ical_label'] )
			);
		}
		$out .= '</div>';
		return $out;
	}
}
