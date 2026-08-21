<?php
/**
 * Uninstall cleanup.
 *
 * Removes the plugin's settings option only. Event posts, their meta and the
 * category terms are intentionally preserved so uninstalling does not destroy
 * user content. To fully remove events, delete them in the admin first.
 *
 * @package ArkonBarManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'abm_settings' );
delete_option( 'abm_db_version' );
