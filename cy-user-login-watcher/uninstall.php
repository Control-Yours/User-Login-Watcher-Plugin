<?php
/**
 * Uninstall CY User Login Watcher.
 *
 * Removes all tracked timestamps and the activation option. Runs only when the
 * plugin is deleted from the Plugins screen (not on deactivation).
 *
 * @package CY_User_Login_Watcher
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// User meta is a single global table, so this covers every site on multisite too.
delete_metadata( 'user', 0, '_cy_ulw_last_login', '', true );
delete_metadata( 'user', 0, '_cy_ulw_last_active', '', true );

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $cy_ulw_site_id ) {
		delete_blog_option( $cy_ulw_site_id, 'cy_ulw_installed' );
		delete_blog_option( $cy_ulw_site_id, 'cy_ulw_settings' );
	}
} else {
	delete_option( 'cy_ulw_installed' );
	delete_option( 'cy_ulw_settings' );
}
