<?php
/**
 * Registers the abm_event post type and abm_category taxonomy.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Post_Type {

	/** @var ABM_Post_Type|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
	}

	/**
	 * Register the event post type. Public so the activation hook can call it directly.
	 */
	public static function register_post_type() {
		$labels = array(
			'name'               => _x( 'Events', 'post type general name', 'arkon-bar-manager' ),
			'singular_name'      => _x( 'Event', 'post type singular name', 'arkon-bar-manager' ),
			'menu_name'          => _x( 'Events', 'admin menu', 'arkon-bar-manager' ),
			'add_new'            => __( 'Add Event', 'arkon-bar-manager' ),
			'add_new_item'       => __( 'Add New Event', 'arkon-bar-manager' ),
			'edit_item'          => __( 'Edit Event', 'arkon-bar-manager' ),
			'new_item'           => __( 'New Event', 'arkon-bar-manager' ),
			'view_item'          => __( 'View Event', 'arkon-bar-manager' ),
			'search_items'       => __( 'Search Events', 'arkon-bar-manager' ),
			'not_found'          => __( 'No events found', 'arkon-bar-manager' ),
			'not_found_in_trash' => __( 'No events found in Trash', 'arkon-bar-manager' ),
			'all_items'          => __( 'All Events', 'arkon-bar-manager' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'show_in_rest'       => true,
			'show_ui'            => true,
			'show_in_menu'       => false, // Attached under our custom top-level menu instead.
			'menu_icon'          => 'dashicons-beer',
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
			// No archive: /music-and-events/ is an existing page (the calendar).
			// An archive on that slug would override the page, so single events
			// live under it while the page itself keeps resolving normally.
			'has_archive'        => false,
			'rewrite'            => array(
				'slug'       => 'music-and-events',
				'with_front' => false,
			),
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
		);

		register_post_type( ABM_POST_TYPE, $args );
	}

	/**
	 * Register the (hierarchical => checklist) event category taxonomy.
	 */
	public static function register_taxonomy() {
		$labels = array(
			'name'              => _x( 'Event Categories', 'taxonomy general name', 'arkon-bar-manager' ),
			'singular_name'     => _x( 'Event Category', 'taxonomy singular name', 'arkon-bar-manager' ),
			'search_items'      => __( 'Search Categories', 'arkon-bar-manager' ),
			'all_items'         => __( 'All Categories', 'arkon-bar-manager' ),
			'edit_item'         => __( 'Edit Category', 'arkon-bar-manager' ),
			'update_item'       => __( 'Update Category', 'arkon-bar-manager' ),
			'add_new_item'      => __( 'Add New Category', 'arkon-bar-manager' ),
			'new_item_name'     => __( 'New Category Name', 'arkon-bar-manager' ),
			'menu_name'         => __( 'Categories', 'arkon-bar-manager' ),
		);

		register_taxonomy(
			ABM_TAXONOMY,
			ABM_POST_TYPE,
			array(
				'labels'            => $labels,
				'hierarchical'      => true, // Renders as a checklist on the editor.
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => 'event-category',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Seed the default Music & Event categories on activation (idempotent).
	 */
	public static function seed_terms() {
		foreach ( array( 'Music', 'Event' ) as $term ) {
			if ( ! term_exists( $term, ABM_TAXONOMY ) ) {
				wp_insert_term( $term, ABM_TAXONOMY );
			}
		}
	}
}
