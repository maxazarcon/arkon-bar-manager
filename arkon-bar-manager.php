<?php
/**
 * Plugin Name:       Arkon Event Manager
 * Plugin URI:        https://maxazarcon.com/
 * Description:       Bar event management. Create events with date, time (incl. "Close"), category and flyer, then surface them on the frontend via Themeco Pro/Cornerstone Looper + Dynamic Content, with per-event iCal and Google Calendar export.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Max Azarcon
 * Author URI:        https://maxazarcon.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       arkon-bar-manager
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

define( 'ABM_VERSION', '2.0.0' );
define( 'ABM_FILE', __FILE__ );
define( 'ABM_DIR', plugin_dir_path( __FILE__ ) );
define( 'ABM_URL', plugin_dir_url( __FILE__ ) );

/** Slugs / keys. Everything user-facing in the DB is prefixed abm_. */
define( 'ABM_POST_TYPE', 'abm_event' );
define( 'ABM_TAXONOMY', 'abm_category' );
define( 'ABM_SETTINGS', 'abm_settings' );
define( 'ABM_ICAL_ENDPOINT', 'ical' );

require_once ABM_DIR . 'includes/helpers.php';
require_once ABM_DIR . 'includes/class-abm-post-type.php';
require_once ABM_DIR . 'includes/class-abm-occurrences.php';
require_once ABM_DIR . 'includes/class-abm-legacy-urls.php';
require_once ABM_DIR . 'includes/class-abm-meta.php';
require_once ABM_DIR . 'includes/class-abm-admin.php';
require_once ABM_DIR . 'includes/class-abm-ical.php';
require_once ABM_DIR . 'includes/class-abm-frontend.php';
require_once ABM_DIR . 'includes/class-abm-calendar.php';
require_once ABM_DIR . 'includes/class-abm-import.php';
require_once ABM_DIR . 'includes/class-abm-mec-db.php';
require_once ABM_DIR . 'includes/class-abm-tools.php';

/**
 * Boot the plugin once all plugins are loaded.
 */
function abm_init_plugin() {
	ABM_Post_Type::instance();
	ABM_Occurrences::instance();
	ABM_Legacy_URLs::instance();
	ABM_Meta::instance();
	ABM_Admin::instance();
	ABM_Frontend::instance();
	ABM_Calendar::instance();
	ABM_Import::instance();
	ABM_Tools::instance();
}
add_action( 'plugins_loaded', 'abm_init_plugin' );

/**
 * One-time upgrade routine. Runs after an in-place plugin update (no
 * re-activation): flush rewrite rules so any slug change takes effect, and
 * re-sync events so permalink-derived meta (abm_ical, abm_gcal, abm_flyer_url)
 * reflects the new URLs.
 */
function abm_maybe_upgrade() {
	if ( get_option( 'abm_db_version' ) === ABM_VERSION ) {
		return;
	}
	flush_rewrite_rules();
	ABM_Occurrences::install_table();
	update_option( 'abm_occurrences_schema', ABM_Occurrences::SCHEMA_VERSION );
	if ( class_exists( 'ABM_Meta' ) ) {
		ABM_Meta::instance()->resync_all();
	}
	// Existing events predate the occurrence table, so materialize them once.
	ABM_Occurrences::rebuild_all();
	update_option( 'abm_db_version', ABM_VERSION );
}
add_action( 'admin_init', 'abm_maybe_upgrade' );

/**
 * Activation: register rewrite rules / terms, then flush so /music-and-events/
 * single events and the /ical/ endpoint resolve immediately.
 */
function abm_activate() {
	ABM_Post_Type::register_post_type();
	ABM_Post_Type::register_taxonomy();
	ABM_Post_Type::seed_terms();
	ABM_Frontend::register_endpoint();
	ABM_Occurrences::install_table();
	update_option( 'abm_occurrences_schema', ABM_Occurrences::SCHEMA_VERSION );
	ABM_Occurrences::rebuild_all();
	ABM_Occurrences::schedule_cron();
	flush_rewrite_rules();
	update_option( 'abm_db_version', ABM_VERSION );
}
register_activation_hook( __FILE__, 'abm_activate' );

/**
 * Deactivation: clear rewrite rules. User content (events) is preserved.
 */
function abm_deactivate() {
	ABM_Occurrences::clear_cron();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'abm_deactivate' );
