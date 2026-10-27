<?php
/**
 * Switches off the theme and plugin file editors in wp-admin (Appearance >
 * Theme File Editor, Plugins > Plugin File Editor) the same way core does for
 * DISALLOW_FILE_EDIT: the three editing capabilities map to do_not_allow.
 *
 * WP Engine's wp-config.php defines DISALLOW_FILE_EDIT as false, so on most
 * Control Yours sites any administrator could edit PHP on the server from the
 * browser, which turns one stolen admin login into a site takeover. On by
 * default; untick it under Settings > Login Watcher, or return false from the
 * cy_ulw_disable_file_editor filter. DISALLOW_FILE_EDIT set to true still wins.
 *
 * Loaded on wp-admin and admin-ajax requests only: the editors and their save
 * action (wp_ajax_edit_theme_plugin_file) live there.
 *
 * @package CY_User_Login_Watcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * File editor switch.
 */
final class CY_ULW_File_Editor {

	/**
	 * Capabilities core denies when DISALLOW_FILE_EDIT is true.
	 */
	const CAPS = array( 'edit_files', 'edit_plugins', 'edit_themes' );

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 2 );
	}

	/**
	 * Whether this plugin switches the editors off on this site.
	 *
	 * @return bool
	 */
	public static function is_disabled() {
		/**
		 * Filter whether the theme and plugin file editors are switched off.
		 *
		 * @param bool $disabled Default: the "File editor" setting (on unless unticked).
		 */
		return (bool) apply_filters( 'cy_ulw_disable_file_editor', (bool) cy_ulw_setting( 'disable_file_editor' ) );
	}

	/**
	 * Whether wp-config.php already switches the editors off.
	 *
	 * @return bool
	 */
	public static function disabled_by_constant() {
		return defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
	}

	/**
	 * Deny the editing capabilities. Runs on every capability check, so it
	 * looks at the capability name before reading the setting.
	 *
	 * @param string[] $caps Primitive capabilities the meta capability maps to.
	 * @param string   $cap  Capability being checked.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap ) {
		if ( in_array( $cap, self::CAPS, true ) && self::is_disabled() ) {
			$caps[] = 'do_not_allow';
		}
		return $caps;
	}
}
