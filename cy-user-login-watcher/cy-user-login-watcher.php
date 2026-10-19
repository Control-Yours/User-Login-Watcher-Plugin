<?php
/**
 * Plugin Name:       CY User Login Watcher
 * Plugin URI:        https://github.com/Control-Yours/User-Login-Watcher-Plugin
 * Description:       Records each user's last login and last admin activity, adds a sortable "Last Login" column to the Users screen, and lists recent logins in a Dashboard widget. Visible to administrators only.
 * Version:           1.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Control Yours
 * Author URI:        https://controlyours.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cy-user-login-watcher
 * Update URI:        https://github.com/Control-Yours/User-Login-Watcher-Plugin
 *
 * @package CY_User_Login_Watcher
 */

/*
 * Load footprint, by request type:
 *
 *  - Front-end visitors (the vast majority of traffic): this file only. It
 *    registers a handful of hooks and runs no queries, adds no scripts, styles
 *    or output. Nothing else is included.
 *  - Logged-in admin screens / admin-ajax / REST: this file records "last
 *    active" at most once every 15 minutes per user (see CY_ULW_Tracker).
 *  - Non-ajax wp-admin page loads: includes/class-cy-ulw-admin.php adds the
 *    Users column and Dashboard widget for administrators.
 *  - wp-admin, cron and WP-CLI: includes/class-cy-ulw-updater.php checks
 *    GitHub for new versions (WordPress schedules this at most hourly).
 *  - Every request type: includes/class-cy-ulw-reporter.php registers hooks
 *    that send logins and activity to the Control Yours Admin Dashboard as
 *    they happen (non-blocking) and a daily snapshot from cron. It does
 *    nothing on a front-end page view.
 */

defined( 'ABSPATH' ) || exit;

define( 'CY_ULW_VERSION', '1.2.1' );
define( 'CY_ULW_FILE', __FILE__ );
define( 'CY_ULW_BASENAME', plugin_basename( __FILE__ ) );
define( 'CY_ULW_DIR', __DIR__ );
define( 'CY_ULW_SLUG', 'cy-user-login-watcher' );

/** GitHub repository that hosts releases and the update manifest (owner/repo). */
define( 'CY_ULW_GITHUB_REPO', 'Control-Yours/User-Login-Watcher-Plugin' );

/** Option holding the settings from Settings > Login Watcher (one small autoloaded array). */
define( 'CY_ULW_SETTINGS_OPTION', 'cy_ulw_settings' );

/**
 * Default settings.
 *
 * @return array
 */
function cy_ulw_default_settings() {
	return array(
		'excluded_roles'   => array( 'subscriber', 'customer' ),
		'view_roles'       => array( 'administrator' ),
		'activity_minutes' => 15, // Not on the settings page; change with the cy_ulw_activity_throttle filter.
	);
}

/**
 * Saved settings merged over the defaults.
 *
 * @return array
 */
function cy_ulw_settings() {
	$saved = get_option( CY_ULW_SETTINGS_OPTION );
	return array_merge( cy_ulw_default_settings(), is_array( $saved ) ? $saved : array() );
}

/**
 * One setting.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function cy_ulw_setting( $key ) {
	$settings = cy_ulw_settings();
	return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
}

/**
 * Records login and activity timestamps. This is the only code that runs on
 * front-end requests.
 */
final class CY_ULW_Tracker {

	/**
	 * User meta key: Unix timestamp (UTC) of the last successful login.
	 */
	const META_LAST_LOGIN = '_cy_ulw_last_login';

	/**
	 * User meta key: Unix timestamp (UTC) of the last admin/REST activity.
	 */
	const META_LAST_ACTIVE = '_cy_ulw_last_active';

	/**
	 * Option key: Unix timestamp of when the plugin was activated on this site.
	 */
	const OPTION_INSTALLED = 'cy_ulw_installed';

	/**
	 * Default minimum seconds between "last active" writes for one user.
	 */
	const DEFAULT_THROTTLE = 15 * MINUTE_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_login', array( __CLASS__, 'record_login' ), 10, 2 );
		// One-Time Login (one_time_login plugin) signs users in without firing wp_login.
		add_action( 'one_time_login_logged_in', array( __CLASS__, 'record_one_time_login' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_record_activity' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'maybe_record_activity' ), 1 );
	}

	/**
	 * Store the login timestamp when a user signs in.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       User object.
	 */
	public static function record_login( $user_login, $user ) {
		if ( ! ( $user instanceof WP_User ) || ! $user->ID ) {
			return;
		}

		if ( ! self::should_track_user( $user->ID ) ) {
			return;
		}

		$now = time();
		update_user_meta( $user->ID, self::META_LAST_LOGIN, $now );
		update_user_meta( $user->ID, self::META_LAST_ACTIVE, $now );

		/**
		 * Fires after a login has been recorded.
		 *
		 * @param int     $user_id   User ID.
		 * @param int     $timestamp Unix timestamp (UTC).
		 * @param WP_User $user      User object.
		 */
		do_action( 'cy_ulw_login_recorded', $user->ID, $now, $user );
	}

	/**
	 * Record a login made through a One-Time Login link, which bypasses wp_login.
	 *
	 * @param WP_User $user User object passed by the one_time_login_logged_in action.
	 */
	public static function record_one_time_login( $user ) {
		if ( $user instanceof WP_User ) {
			self::record_login( $user->user_login, $user );
		}
	}

	/**
	 * Refresh the "last active" timestamp for the current user, throttled.
	 *
	 * Runs on admin screens, admin-ajax and REST requests. Heartbeat pings are
	 * ignored so an idle tab left open does not count as activity, and cron is
	 * ignored because there is no real user behind it.
	 *
	 * Cost: the meta read is served from the user meta cache WordPress already
	 * primed for capability checks (no query). A write happens at most once per
	 * throttle window per user.
	 */
	public static function maybe_record_activity() {
		if ( wp_doing_cron() || ! is_user_logged_in() ) {
			return;
		}

		/**
		 * Filter whether "last active" tracking is enabled at all.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'cy_ulw_track_activity', true ) ) {
			return;
		}

		// Ignore Heartbeat API pings from idle tabs.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only comparison of the ajax action name.
		if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'heartbeat' === sanitize_key( wp_unslash( $_REQUEST['action'] ) ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! self::should_track_user( $user_id ) ) {
			return;
		}

		/**
		 * Filter the minimum number of seconds between "last active" writes.
		 *
		 * @param int $seconds Default 900 (15 minutes).
		 * @param int $user_id User ID.
		 */
		$throttle = (int) apply_filters( 'cy_ulw_activity_throttle', max( 1, (int) cy_ulw_setting( 'activity_minutes' ) ) * MINUTE_IN_SECONDS, $user_id );

		$now  = time();
		$last = (int) get_user_meta( $user_id, self::META_LAST_ACTIVE, true );

		if ( $last && ( $now - $last ) < $throttle ) {
			return;
		}

		update_user_meta( $user_id, self::META_LAST_ACTIVE, $now );

		/**
		 * Fires after a "last active" timestamp has been recorded.
		 *
		 * Throttled like the write itself: at most once per throttle window
		 * (15 minutes by default) per user.
		 *
		 * @param int $user_id   User ID.
		 * @param int $timestamp Unix timestamp (UTC).
		 */
		do_action( 'cy_ulw_activity_recorded', $user_id, $now );
	}

	/**
	 * Whether a given user should be tracked. Also used by the reporter to
	 * decide who belongs in the daily snapshot.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function should_track_user( $user_id ) {
		/**
		 * Filter whether a specific user should be tracked.
		 *
		 * @param bool $track   Default: false when every role the user holds is excluded, true otherwise.
		 * @param int  $user_id User ID.
		 */
		return (bool) apply_filters( 'cy_ulw_track_user', self::is_tracked_role( $user_id ), (int) $user_id );
	}

	/**
	 * Roles whose users are not tracked by default: site visitors and shop
	 * customers, not people who work in the admin.
	 *
	 * @return string[] Role slugs.
	 */
	public static function excluded_roles() {
		/**
		 * Filter the roles that are not tracked. A user is skipped only when
		 * every role they hold is in this list.
		 *
		 * @param string[] $roles Default: the "Roles not tracked" setting (subscriber and customer unless changed).
		 */
		$roles = apply_filters( 'cy_ulw_excluded_roles', (array) cy_ulw_setting( 'excluded_roles' ) );
		return array_values( array_filter( array_map( 'strval', (array) $roles ) ) );
	}

	/**
	 * Whether a user holds at least one role that is tracked.
	 *
	 * Users with no role at all (for example, multisite users not added to this
	 * site) are not tracked.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_tracked_role( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}
		return (bool) array_diff( (array) $user->roles, self::excluded_roles() );
	}

	/**
	 * Record when tracking began so "no login recorded" can be qualified.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				add_option( self::OPTION_INSTALLED, time(), '', false );
				restore_current_blog();
			}
			return;
		}
		add_option( self::OPTION_INSTALLED, time(), '', false );
	}
}

register_activation_hook( __FILE__, array( 'CY_ULW_Tracker', 'activate' ) );

CY_ULW_Tracker::init();

// Dashboard reporting. Always loaded: logins happen on wp-login.php, activity
// on admin/ajax/REST requests and the daily snapshot on cron. Front-end page
// views only pay for the hook registrations.
require_once CY_ULW_DIR . '/includes/class-cy-ulw-reporter.php';
CY_ULW_Reporter::init();

register_activation_hook( __FILE__, array( 'CY_ULW_Reporter', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CY_ULW_Reporter', 'deactivate' ) );

// Admin UI: Users column + Dashboard widget. Not needed for ajax or the front end.
if ( is_admin() && ! wp_doing_ajax() ) {
	require_once CY_ULW_DIR . '/includes/class-cy-ulw-admin.php';
	CY_ULW_Admin::init();
	require_once CY_ULW_DIR . '/includes/class-cy-ulw-settings.php';
	CY_ULW_Settings::init();
}

// Updater: needed wherever WordPress checks for or installs plugin updates —
// admin page loads, cron (scheduled checks + auto-updates), WP-CLI, and the one
// admin-ajax action the Plugins screen uses for "Update now". Front-end ajax
// (form submissions etc.) never loads it.
if (
	wp_doing_cron()
	|| ( defined( 'WP_CLI' ) && WP_CLI )
	|| ( is_admin() && ! wp_doing_ajax() )
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only used to decide whether to load the updater; core verifies the nonce for the action itself.
	|| ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'update-plugin' === $_REQUEST['action'] )
) {
	require_once CY_ULW_DIR . '/includes/class-cy-ulw-updater.php';
	CY_ULW_Updater::init();
}
