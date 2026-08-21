<?php
/**
 * Uninstall cleanup.
 *
 * Event posts, their meta and the category terms are intentionally preserved so
 * uninstalling does not destroy user content. To fully remove events, delete
 * them in the admin first.
 *
 * Everything this plugin generated *about* that content is removed, because it
 * is derived and can be rebuilt from the posts at any time: the occurrence
 * table, its schema marker, and the scheduled task that extends it. Leaving the
 * table behind orphans it in the database forever, and leaving the cron event
 * registered means WordPress keeps firing a hook with no listener.
 *
 * @package ArkonBarManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'abm_settings' );
delete_option( 'abm_db_version' );
delete_option( 'abm_occurrences_schema' );

// Deactivation normally clears this, but uninstall can be reached without a
// clean deactivation (a failed update, a manual removal), so do not assume.
$timestamp = wp_next_scheduled( 'abm_extend_occurrences' );
while ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'abm_extend_occurrences' );
	$timestamp = wp_next_scheduled( 'abm_extend_occurrences' );
}
wp_clear_scheduled_hook( 'abm_extend_occurrences' );

// Derived data: safe to drop, rebuildable from the event posts.
$table = $wpdb->prefix . 'abm_occurrences';
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
