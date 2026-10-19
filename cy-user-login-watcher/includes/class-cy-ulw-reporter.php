<?php
/**
 * Dashboard reporting: tells the Control Yours Admin Dashboard about logins
 * and admin activity as they happen, and sends a full list of tracked users
 * once a day so the team can see who has been in each site without logging
 * in to it.
 *
 * Identity: each site generates its own random key the first time it needs
 * one and sends it with every request. The dashboard lists the site as
 * "pending" until someone approves it there, so nothing a stranger posts is
 * shown, and no shared secret has to live in this (public) repository.
 *
 * Loaded on every request type, because logins happen on wp-login.php,
 * activity on admin/ajax/REST requests and the snapshot runs from cron. On an
 * ordinary front-end page view it only registers hooks.
 *
 * Switches: define CY_ULW_DASHBOARD_REPORTING as false in wp-config.php to
 * send nothing from a site, CY_ULW_DASHBOARD_URL to point it at another
 * dashboard, or untick the box under Settings > Login Watcher.
 *
 * @package CY_User_Login_Watcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends login data to the Admin Dashboard.
 */
final class CY_ULW_Reporter {

	/**
	 * Option (autoloaded array): site_key, last_sent, last_snapshot, last_code,
	 * last_error and disabled. See defaults().
	 */
	const OPTION = 'cy_ulw_reporter';

	/**
	 * WP-cron hook that sends the daily snapshot.
	 */
	const CRON_HOOK = 'cy_ulw_daily_report';

	/**
	 * Intake endpoint, unless CY_ULW_DASHBOARD_URL overrides it.
	 */
	const DEFAULT_URL = 'https://admin.controlyours.com/api/logins/intake';

	/**
	 * Length of the generated site key (32 random bytes as base64url).
	 */
	const SITE_KEY_LENGTH = 43;

	/**
	 * Users fetched per query when building the snapshot.
	 */
	const SNAPSHOT_BATCH = 500;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'cy_ulw_login_recorded', array( __CLASS__, 'on_login' ), 10, 3 );
		add_action( 'cy_ulw_activity_recorded', array( __CLASS__, 'on_activity' ), 10, 2 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_snapshot' ) );

		// Sites that updated in place never ran the activation routine, so make
		// sure the daily event exists. One read of the (autoloaded) cron option.
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
		if ( wp_doing_cron() ) {
			add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		}

		// Ticking or unticking the settings box takes effect at once rather
		// than on the next admin page load.
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'maybe_schedule' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'maybe_schedule' ) );
	}

	/* ---------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------ */

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'site_key'      => '',    // Generated once; identifies this site on the dashboard.
			'last_sent'     => 0,     // Unix: last request of any type.
			'last_snapshot' => 0,     // Unix: last snapshot the dashboard accepted (200 or 202).
			'last_code'     => 0,     // HTTP status of the last blocking request (0 = no response).
			'last_error'    => '',    // Short description of the last failure, '' when the last snapshot succeeded.
			'disabled'      => false, // True when the settings checkbox is unticked.
		);
	}

	/**
	 * Saved values merged over the defaults. Generates the site key on first use.
	 *
	 * @return array
	 */
	public static function config() {
		$saved  = get_option( self::OPTION );
		$config = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );

		if ( '' === (string) $config['site_key'] ) {
			$config['site_key'] = self::generate_key();
			self::save( $config );
		}

		return $config;
	}

	/**
	 * Store the option.
	 *
	 * @param array $config Full option array.
	 */
	private static function save( array $config ) {
		update_option( self::OPTION, $config, true );
	}

	/**
	 * A new random site key: 43 URL-safe characters.
	 *
	 * @return string
	 */
	private static function generate_key() {
		try {
			$key = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding random bytes, not hiding anything.
		} catch ( Exception $e ) {
			$key = '';
		}

		if ( self::SITE_KEY_LENGTH !== strlen( $key ) && function_exists( 'wp_generate_password' ) ) {
			$key = wp_generate_password( self::SITE_KEY_LENGTH, false );
		}

		return (string) $key;
	}

	/**
	 * Where reports are sent.
	 *
	 * Define CY_ULW_DASHBOARD_URL in wp-config.php to point a site at a
	 * different dashboard (e.g. a local build of it).
	 *
	 * @return string
	 */
	public static function endpoint() {
		if ( defined( 'CY_ULW_DASHBOARD_URL' ) && CY_ULW_DASHBOARD_URL ) {
			return (string) CY_ULW_DASHBOARD_URL;
		}
		return self::DEFAULT_URL;
	}

	/**
	 * Whether reporting is switched off for this site by the wp-config.php constant.
	 *
	 * @return bool
	 */
	public static function disabled_by_constant() {
		return defined( 'CY_ULW_DASHBOARD_REPORTING' ) && ! CY_ULW_DASHBOARD_REPORTING;
	}

	/**
	 * Whether this site reports to the dashboard at all.
	 *
	 * Reads the option directly rather than through config() so that checking
	 * never writes anything.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( self::disabled_by_constant() ) {
			return false;
		}

		$saved   = get_option( self::OPTION );
		$enabled = ! ( is_array( $saved ) && ! empty( $saved['disabled'] ) );

		/**
		 * Filter whether this site sends login data to the Admin Dashboard.
		 *
		 * @param bool $enabled Default: true unless the box under Settings > Login Watcher
		 *                      is unticked. The CY_ULW_DASHBOARD_REPORTING constant wins
		 *                      over this filter.
		 */
		return (bool) apply_filters( 'cy_ulw_reporting_enabled', $enabled );
	}

	/* ---------------------------------------------------------------------
	 * Events
	 * ------------------------------------------------------------------ */

	/**
	 * Send a "login" event. Runs on the cy_ulw_login_recorded action.
	 *
	 * @param int     $user_id   User ID.
	 * @param int     $timestamp Unix timestamp (UTC).
	 * @param WP_User $user      User object.
	 */
	public static function on_login( $user_id, $timestamp, $user = null ) {
		if ( ! ( $user instanceof WP_User ) ) {
			$user = get_userdata( (int) $user_id );
		}
		if ( $user instanceof WP_User ) {
			self::send_event( 'login', $user );
		}
	}

	/**
	 * Send an "activity" event. Runs on the cy_ulw_activity_recorded action,
	 * which the tracker throttles to once per 15 minutes per user.
	 *
	 * @param int $user_id   User ID.
	 * @param int $timestamp Unix timestamp (UTC).
	 */
	public static function on_activity( $user_id, $timestamp ) {
		$user = get_userdata( (int) $user_id );
		if ( $user instanceof WP_User ) {
			self::send_event( 'activity', $user );
		}
	}

	/**
	 * Fire-and-forget request for one user. Nothing here may slow down or
	 * break the login: the request is non-blocking and every failure is swallowed.
	 *
	 * @param string  $type 'login' or 'activity'.
	 * @param WP_User $user User object.
	 */
	private static function send_event( $type, WP_User $user ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		try {
			$config   = self::config();
			$response = self::post( $type, array( self::user_entry( $user ) ), $config['site_key'], false );

			if ( is_wp_error( $response ) ) {
				// Non-blocking requests only error before anything is sent (bad URL, no transport).
				$config['last_error'] = self::short_error( $response->get_error_message() );
			} else {
				$config['last_sent'] = time();
			}
			self::save( $config );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Reporting is best effort; the login itself has already been recorded.
		}
	}

	/* ---------------------------------------------------------------------
	 * Daily snapshot
	 * ------------------------------------------------------------------ */

	/**
	 * Send every tracked user, including those who have never logged in, so the
	 * dashboard can show "never logged in" and drop users that no longer exist.
	 * Runs from the cy_ulw_daily_report cron event (blocking, 10 s timeout).
	 *
	 * @return bool True when the dashboard accepted (200) or parked (202) the snapshot.
	 */
	public static function send_snapshot() {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$ok = false;

		try {
			$config   = self::config();
			$response = self::post( 'snapshot', self::snapshot_users(), $config['site_key'], true );

			$config['last_sent'] = time();

			if ( is_wp_error( $response ) ) {
				$config['last_code']  = 0;
				$config['last_error'] = self::short_error( $response->get_error_message() );
			} else {
				$code                = (int) wp_remote_retrieve_response_code( $response );
				$config['last_code'] = $code;

				if ( 200 === $code || 202 === $code ) {
					$config['last_snapshot'] = time();
					$config['last_error']    = '';
					$ok                      = true;
				} else {
					$config['last_error'] = self::describe_code( $code );
				}
			}

			self::save( $config );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never let a reporting failure surface in cron output.
		}

		return $ok;
	}

	/**
	 * Every tracked user on this site, in batches so large sites stay within memory.
	 *
	 * Uses the tracker's own rule (not all of the user's roles excluded, plus the
	 * cy_ulw_track_user filter) rather than role__not_in, which would also drop
	 * users who hold an excluded role alongside a tracked one.
	 *
	 * @return array[] One entry per user, see user_entry().
	 */
	private static function snapshot_users() {
		$users = array();
		$paged = 1;

		do {
			$batch = get_users(
				array(
					'number'      => self::SNAPSHOT_BATCH,
					'paged'       => $paged,
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'fields'      => 'all',
					'count_total' => false,
				)
			);

			foreach ( $batch as $user ) {
				if ( CY_ULW_Tracker::should_track_user( $user->ID ) ) {
					$users[] = self::user_entry( $user );
				}
			}

			$paged++;
		} while ( count( $batch ) === self::SNAPSHOT_BATCH );

		return $users;
	}

	/* ---------------------------------------------------------------------
	 * Cron scheduling
	 * ------------------------------------------------------------------ */

	/**
	 * Keep the daily event in step with the enabled state: schedule it when
	 * missing, remove it when reporting is switched off. Runs on admin_init and
	 * at the start of every cron run.
	 */
	public static function maybe_schedule() {
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( ! self::is_enabled() ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}

		if ( ! $next ) {
			self::config(); // Make sure the site key exists before the first snapshot.
			self::schedule();
		}
	}

	/**
	 * Schedule the daily snapshot, starting at a random point in the next hour
	 * so the client sites do not all report at the same minute.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + wp_rand( 5, 60 ) * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the daily snapshot event.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Activation: create the site key and schedule the daily snapshot.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ) {
		self::on_each_site( $network_wide, array( __CLASS__, 'install' ) );
	}

	/**
	 * Deactivation: remove the cron event. The option (and the site key) stays
	 * so reactivating does not create a second pending entry on the dashboard.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 */
	public static function deactivate( $network_wide = false ) {
		self::on_each_site( $network_wide, array( __CLASS__, 'unschedule' ) );
	}

	/**
	 * Per-site activation work.
	 */
	public static function install() {
		self::config();
		if ( self::is_enabled() ) {
			self::schedule();
		}
	}

	/**
	 * Run a callback once, or once per site when network-activating.
	 *
	 * @param bool     $network_wide Whether the action applies to the whole network.
	 * @param callable $callback     What to run.
	 */
	private static function on_each_site( $network_wide, $callback ) {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				call_user_func( $callback );
				restore_current_blog();
			}
			return;
		}
		call_user_func( $callback );
	}

	/* ---------------------------------------------------------------------
	 * Transport
	 * ------------------------------------------------------------------ */

	/**
	 * POST one report to the dashboard.
	 *
	 * @param string  $type     'login', 'activity' or 'snapshot'.
	 * @param array[] $users    User entries from user_entry().
	 * @param string  $site_key This site's key, sent as the X-CY-Site-Key header.
	 * @param bool    $blocking Wait for the response (snapshot) or fire and forget (events).
	 * @return array|WP_Error wp_remote_post() result. For non-blocking requests the
	 *                        response code is always empty.
	 */
	private static function post( $type, array $users, $site_key, $blocking ) {
		$body = wp_json_encode(
			array(
				'type'    => $type,
				'sent_at' => time(),
				'site'    => self::site_info(),
				'users'   => array_values( $users ),
			)
		);

		if ( false === $body ) {
			return new WP_Error( 'cy_ulw_json', 'Could not encode the report as JSON.' );
		}

		return wp_remote_post(
			self::endpoint(),
			array(
				'blocking'   => (bool) $blocking,
				'timeout'    => $blocking ? 10 : 3,
				'headers'    => array(
					'Content-Type'  => 'application/json',
					'X-CY-Site-Key' => (string) $site_key,
				),
				'body'       => $body,
				'user-agent' => 'CY-User-Login-Watcher/' . CY_ULW_VERSION . '; ' . home_url(),
			)
		);
	}

	/**
	 * The "site" block of every report.
	 *
	 * @return array
	 */
	private static function site_info() {
		return array(
			'home_url'       => home_url(),
			'name'           => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'install'        => defined( 'PWP_NAME' ) ? (string) PWP_NAME : null, // WP Engine install name.
			'plugin_version' => CY_ULW_VERSION,
			'wp_version'     => (string) get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
		);
	}

	/**
	 * One user as the dashboard expects it. Timestamps are null when nothing
	 * has been recorded yet.
	 *
	 * @param WP_User $user User object.
	 * @return array
	 */
	private static function user_entry( WP_User $user ) {
		$login  = (int) get_user_meta( $user->ID, CY_ULW_Tracker::META_LAST_LOGIN, true );
		$active = (int) get_user_meta( $user->ID, CY_ULW_Tracker::META_LAST_ACTIVE, true );

		return array(
			'id'           => (int) $user->ID,
			'login'        => (string) $user->user_login,
			'display_name' => (string) $user->display_name,
			'email'        => (string) $user->user_email,
			'roles'        => array_values( array_map( 'strval', (array) $user->roles ) ),
			'last_login'   => $login > 0 ? $login : null,
			'last_active'  => $active > 0 ? $active : null,
		);
	}

	/**
	 * Short, body-free description of a failed response, for the settings screen.
	 *
	 * @param int $code HTTP status code.
	 * @return string
	 */
	private static function describe_code( $code ) {
		switch ( (int) $code ) {
			case 0:
				return 'No response from the dashboard';
			case 400:
				return 'HTTP 400: the dashboard rejected the report as invalid';
			case 401:
				return 'HTTP 401: site key missing';
			case 409:
				return 'HTTP 409: site key does not match the one approved on the dashboard';
		}
		return 'HTTP ' . (int) $code;
	}

	/**
	 * Trim an error message to something that fits in the option and on screen.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	private static function short_error( $message ) {
		$message = trim( wp_strip_all_tags( (string) $message ) );
		if ( strlen( $message ) > 160 ) {
			$message = substr( $message, 0, 157 ) . '...';
		}
		return '' === $message ? 'Request failed' : $message;
	}
}
