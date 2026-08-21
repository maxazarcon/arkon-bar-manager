<?php
/**
 * Cornerstone / Pro Looper Provider.
 *
 * Registers a custom Looper Provider so an event list can be built out of native
 * Cornerstone elements and styled visually, instead of dropping a shortcode in
 * as an opaque block.
 *
 * Why a custom provider rather than Cornerstone's built-in post loopers: those
 * iterate posts, and here one post holds many dates. A weekly night is a single
 * post with hundreds of occurrences, so a post looper renders it once and the
 * calendar silently loses most of its rows. This provider iterates the
 * occurrence table instead, so each date is its own loop item.
 *
 * Usage in Cornerstone:
 *   Looper Provider -> Custom -> "abm_occurrences"
 *   Params: count, offset, category, from, to, include_past
 *   Then read fields with {{dc:looper:field key="title"}} and friends.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Looper {

	/** @var ABM_Looper|null */
	private static $instance = null;

	/** Looper Provider key, as typed into Cornerstone. */
	const KEY = 'abm_occurrences';

	/** Safety ceiling: a looper builds full markup per item, so keep it bounded. */
	const MAX_ITEMS = 200;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Two registration paths, deliberately exclusive.
		//
		// cs_looper_provider_register() is the richer one: the provider appears in
		// Cornerstone's provider list with a proper label and labelled controls,
		// so nobody has to remember the key or the parameter names. It registers
		// its own filter internally, which is why the bare add_filter() below is
		// the fallback rather than an addition — running both would attach two
		// callbacks and execute the query twice per loop.
		add_action( 'init', array( __CLASS__, 'register_provider' ), 20 );

		// Convenience shortcode for the field reference, so the available keys can
		// be looked up without digging through this file.
		add_shortcode( 'abm_looper_fields', array( __CLASS__, 'sc_fields' ) );
	}

	/**
	 * Register with Pro's Looper API when available, otherwise fall back to the
	 * plain filter so the provider still works on older builds (it just has to be
	 * addressed by key rather than picked from a list).
	 */
	public static function register_provider() {
		if ( ! function_exists( 'cs_looper_provider_register' ) ) {
			add_filter( 'cs_looper_custom_' . self::KEY, array( __CLASS__, 'provide' ), 10, 2 );
			return;
		}

		cs_looper_provider_register(
			self::KEY,
			array(
				'label'  => __( 'Bar Events (occurrences)', 'arkon-bar-manager' ),
				'values' => array(
					'count'        => 10,
					'offset'       => 0,
					'category'     => '',
					'from'         => '',
					'to'           => '',
					'include_past' => false,
				),
				'controls' => array(
					array(
						'key'   => 'count',
						'type'  => 'number',
						'label' => __( 'How many', 'arkon-bar-manager' ),
					),
					array(
						'key'   => 'offset',
						'type'  => 'number',
						'label' => __( 'Skip first', 'arkon-bar-manager' ),
					),
					array(
						'key'   => 'category',
						'type'  => 'text',
						'label' => __( 'Category slug', 'arkon-bar-manager' ),
					),
					array(
						'key'   => 'from',
						'type'  => 'text',
						'label' => __( 'From (YYYY-MM-DD)', 'arkon-bar-manager' ),
					),
					array(
						'key'   => 'to',
						'type'  => 'text',
						'label' => __( 'To (YYYY-MM-DD)', 'arkon-bar-manager' ),
					),
					array(
						'key'   => 'include_past',
						'type'  => 'toggle',
						'label' => __( 'Include past dates', 'arkon-bar-manager' ),
					),
				),
				'filter' => array( __CLASS__, 'provide' ),
			)
		);
	}

	/**
	 * Build the loop items.
	 *
	 * @param mixed $result Incoming value from Cornerstone (unused).
	 * @param array $args   Params entered in the Looper Provider UI.
	 * @return array<int,array<string,mixed>>
	 */
	public static function provide( $result, $args = array() ) {
		$args = is_array( $args ) ? $args : array();

		$count  = isset( $args['count'] ) ? (int) $args['count'] : 10;
		$count  = max( 1, min( self::MAX_ITEMS, $count ) );
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$category = isset( $args['category'] ) ? sanitize_title( $args['category'] ) : '';
		$from     = isset( $args['from'] ) ? abm_sanitize_date( $args['from'] ) : '';
		$to       = isset( $args['to'] ) ? abm_sanitize_date( $args['to'] ) : '';

		// Past dates are excluded by default: a venue calendar means "what's on".
		$include_past = ! empty( $args['include_past'] );
		if ( '' === $from && ! $include_past ) {
			$from = current_time( 'Y-m-d' );
		}

		$rows = self::query( $count, $offset, $category, $from, $to );

		$items     = array();
		$last_month = '';
		foreach ( $rows as $row ) {
			$item = self::build_item( $row );

			// A flag the layout can use to render a month heading without needing
			// a second nested looper just to work out where months change.
			$item['is_month_start'] = ( $item['month_key'] !== $last_month );
			$last_month             = $item['month_key'];

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Fetch occurrence rows joined to their published events.
	 *
	 * @param int    $count    Limit.
	 * @param int    $offset   Offset.
	 * @param string $category Category slug or ''.
	 * @param string $from     Y-m-d or ''.
	 * @param string $to       Y-m-d or ''.
	 * @return array<int,object>
	 */
	private static function query( $count, $offset, $category, $from, $to ) {
		global $wpdb;

		$occ   = ABM_Occurrences::table();
		$posts = $wpdb->posts;

		$where = array( 'p.post_type = %s', "p.post_status = 'publish'" );
		$args  = array( ABM_POST_TYPE );

		if ( '' !== $from ) {
			$where[] = 'o.occur_date >= %s';
			$args[]  = $from;
		}
		if ( '' !== $to ) {
			$where[] = 'o.occur_date <= %s';
			$args[]  = $to;
		}

		$join = '';
		if ( '' !== $category ) {
			$join    = " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id ";
			$where[] = 'tt.taxonomy = %s';
			$where[] = 't.slug = %s';
			array_push( $args, ABM_TAXONOMY, $category );
		}

		$args[] = $count;
		$args[] = $offset;

		$sql = "SELECT o.id, o.post_id, o.occur_date, o.start_time, o.end_time
			FROM {$occ} o
			INNER JOIN {$posts} p ON p.ID = o.post_id
			{$join}
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY o.occur_date ASC, o.start_time ASC, o.id ASC
			LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Flatten one occurrence into the fields a Cornerstone layout will want.
	 *
	 * Everything is pre-resolved to a display-ready string so the layout never
	 * has to call a helper or know about the plugin's meta keys.
	 *
	 * @param object $row Occurrence row.
	 * @return array<string,mixed>
	 */
	public static function build_item( $row ) {
		$post_id   = (int) $row->post_id;
		$permalink = (string) get_permalink( $post_id );
		$recurring = ABM_Occurrences::is_recurring( $post_id );

		// Recurring rows carry their own date so the single view shows the night
		// that was actually clicked.
		$link = ( $permalink && $recurring )
			? add_query_arg( 'occ', $row->occur_date, $permalink )
			: $permalink;

		$flyer_id  = absint( get_post_meta( $post_id, 'abm_flyer_id', true ) );
		$flyer_url = (string) get_post_meta( $post_id, 'abm_flyer_url', true );
		if ( '' === $flyer_url ) {
			$flyer_url = abm_resolve_flyer_url( $post_id, $flyer_id );
		}

		$terms = get_the_terms( $post_id, ABM_TAXONOMY );
		$cats  = ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : array();

		$ts = abm_date_to_timestamp( $row->occur_date );

		$excerpt = (string) get_post_field( 'post_excerpt', $post_id );
		if ( '' === $excerpt ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 28, '…' );
		}

		return array(
			// Identity
			'id'             => (int) $row->id,
			'post_id'        => $post_id,
			// Decoded to plain text: the layout escapes on output, and handing it
			// pre-encoded entities makes "Rock & Roll" render as "Rock &amp; Roll".
			'title'          => wp_specialchars_decode( get_the_title( $post_id ), ENT_QUOTES ),
			'permalink'      => $link,
			'permalink_base' => $permalink,

			// Date
			'date'           => $row->occur_date,
			'date_display'   => abm_format_date( $row->occur_date ),
			'date_full'      => $ts ? date_i18n( 'l, F j, Y', $ts ) : '',
			'day'            => $ts ? date_i18n( 'j', $ts ) : '',
			'month_short'    => $ts ? date_i18n( 'M', $ts ) : '',
			'weekday'        => $ts ? date_i18n( 'l', $ts ) : '',
			'year'           => $ts ? date_i18n( 'Y', $ts ) : '',
			'month_key'      => substr( (string) $row->occur_date, 0, 7 ),
			'month_label'    => $ts ? date_i18n( 'F Y', $ts ) : '',

			// Time
			'start_time'     => $row->start_time,
			'end_time'       => $row->end_time,
			'time_display'   => abm_format_time_range( $row->start_time, $row->end_time ),

			// Presentation
			'flyer_url'      => $flyer_url,
			'flyer_id'       => $flyer_id,
			'categories'     => implode( ', ', $cats ),
			'category_slugs' => implode( ',', ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'slug' ) : array() ),
			'cost_display'   => (string) get_post_meta( $post_id, 'abm_cost_display', true ),
			'excerpt'        => $excerpt,

			// Export links, resolved for this occurrence
			'ical_url'       => $recurring ? add_query_arg( 'occ', $row->occur_date, abm_ical_url( $post_id ) ) : abm_ical_url( $post_id ),
			'gcal_url'       => abm_build_gcal_url( $post_id, $row->occur_date, $row->start_time, $row->end_time ),

			// Flags
			'is_recurring'   => $recurring,
			'has_cost'       => ( '' !== (string) get_post_meta( $post_id, 'abm_cost_display', true ) ),
			'has_flyer'      => ( '' !== $flyer_url ),
		);
	}

	/**
	 * The field keys, as a readable table. Drop [abm_looper_fields] on any page
	 * (or read it from the Tools screen) rather than reading source to find them.
	 *
	 * @return string
	 */
	public static function sc_fields() {
		$sample = array(
			'id', 'post_id', 'title', 'permalink', 'permalink_base',
			'date', 'date_display', 'date_full', 'day', 'month_short', 'weekday', 'year',
			'month_key', 'month_label', 'is_month_start',
			'start_time', 'end_time', 'time_display',
			'flyer_url', 'flyer_id', 'categories', 'category_slugs', 'cost_display', 'excerpt',
			'ical_url', 'gcal_url',
			'is_recurring', 'has_cost', 'has_flyer',
		);

		$out  = '<p>Looper Provider key: <code>' . esc_html( self::KEY ) . '</code>. ';
		$out .= 'Params: <code>count</code>, <code>offset</code>, <code>category</code>, <code>from</code>, <code>to</code>, <code>include_past</code>.</p>';
		$out .= '<p>Fields, read as <code>{{dc:looper:field key="&hellip;"}}</code>:</p><ul>';
		foreach ( $sample as $k ) {
			$out .= '<li><code>' . esc_html( $k ) . '</code></li>';
		}
		$out .= '</ul>';

		return $out;
	}
}
