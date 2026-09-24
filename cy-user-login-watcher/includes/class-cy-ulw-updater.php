<?php
/**
 * Private update channel via GitHub.
 *
 * WordPress core handles the schedule: wp_update_plugins() runs from cron at
 * most twice a day and from admin page loads at most once an hour. Because the
 * plugin header declares an "Update URI" on github.com, core skips asking
 * wordpress.org about this plugin and instead calls our filter, which reads a
 * small JSON manifest from the repository and points core at the zip attached
 * to the matching GitHub Release. Core does the download and install.
 *
 * Manifest: https://raw.githubusercontent.com/<owner>/<repo>/main/update.json
 * Package:  https://github.com/<owner>/<repo>/releases/download/v<ver>/cy-user-login-watcher-<ver>.zip
 *
 * Loaded only in wp-admin, cron and WP-CLI. Never on front-end requests.
 *
 * @package CY_User_Login_Watcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * GitHub-backed updater.
 */
final class CY_ULW_Updater {

	/**
	 * Site transient holding the fetched manifest (or a negative-cache marker).
	 */
	const CACHE_KEY = 'cy_ulw_update_manifest';

	/**
	 * How long a good manifest is cached. Core throttles checks anyway; this
	 * just avoids re-fetching when several checks land in the same window.
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * How long to wait before retrying after a failed fetch.
	 */
	const ERROR_TTL = HOUR_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_for_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_folder' ), 10, 4 );
	}

	/**
	 * URL of the update manifest.
	 *
	 * Define CY_ULW_UPDATE_MANIFEST in wp-config.php to point a site at a
	 * different manifest (e.g. a staging branch).
	 *
	 * @return string
	 */
	public static function manifest_url() {
		if ( defined( 'CY_ULW_UPDATE_MANIFEST' ) && CY_ULW_UPDATE_MANIFEST ) {
			return (string) CY_ULW_UPDATE_MANIFEST;
		}
		return 'https://raw.githubusercontent.com/' . CY_ULW_GITHUB_REPO . '/main/update.json';
	}

	/**
	 * Prefix every release package URL must start with.
	 *
	 * @return string
	 */
	private static function package_prefix() {
		return 'https://github.com/' . CY_ULW_GITHUB_REPO . '/releases/download/';
	}

	/* ---------------------------------------------------------------------
	 * Core integration
	 * ------------------------------------------------------------------ */

	/**
	 * Tell core about the latest release.
	 *
	 * Runs inside wp_update_plugins() for plugins whose Update URI host is
	 * github.com. Return value is stored in the update_plugins site transient.
	 *
	 * @param array|false $update      Current value (false).
	 * @param array       $plugin_data Plugin header data.
	 * @param string      $plugin_file Plugin basename.
	 * @param string[]    $locales     Installed locales.
	 * @return array|false
	 */
	public static function check_for_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( CY_ULW_BASENAME !== $plugin_file ) {
			return $update;
		}

		$manifest = self::get_manifest();
		if ( ! $manifest ) {
			return $update;
		}

		return array(
			'slug'           => CY_ULW_SLUG,
			'version'        => $manifest['version'],
			'url'            => $manifest['homepage'],
			'package'        => $manifest['download_url'],
			'tested'         => $manifest['tested'],
			'requires'       => $manifest['requires'],
			'requires_php'   => $manifest['requires_php'],
			'icons'          => array(),
			'banners'        => array(),
			'banners_rtl'    => array(),
			'upgrade_notice' => $manifest['upgrade_notice'],
		);
	}

	/**
	 * Populate the "View version x.y.z details" modal from the manifest.
	 *
	 * @param false|object|array $result Default result.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || CY_ULW_SLUG !== $args->slug ) {
			return $result;
		}

		$manifest = self::get_manifest();
		if ( ! $manifest ) {
			return $result;
		}

		$info                 = new stdClass();
		$info->name           = $manifest['name'];
		$info->slug           = CY_ULW_SLUG;
		$info->version        = $manifest['version'];
		$info->author         = sprintf( '<a href="%s">%s</a>', esc_url( $manifest['author_homepage'] ), esc_html( $manifest['author'] ) );
		$info->author_profile = $manifest['author_homepage'];
		$info->homepage       = $manifest['homepage'];
		$info->requires       = $manifest['requires'];
		$info->tested         = $manifest['tested'];
		$info->requires_php   = $manifest['requires_php'];
		$info->last_updated   = $manifest['last_updated'];
		$info->download_link  = $manifest['download_url'];
		$info->trunk          = $manifest['download_url'];
		$info->sections       = $manifest['sections'];
		$info->banners        = array();
		$info->external       = true;

		return $info;
	}

	/**
	 * Make sure the unpacked folder is named after the plugin slug so the
	 * update lands on top of the existing install. Only touches our own package.
	 *
	 * @param string|WP_Error $source        Path to the unpacked source folder.
	 * @param string          $remote_source Path to the temp folder containing $source.
	 * @param WP_Upgrader     $upgrader      Upgrader instance.
	 * @param array           $hook_extra    Extra arguments (contains 'plugin' for plugin updates).
	 * @return string|WP_Error
	 */
	public static function fix_source_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( is_wp_error( $source ) || empty( $hook_extra['plugin'] ) || CY_ULW_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		$expected = trailingslashit( $remote_source ) . CY_ULW_SLUG;
		if ( untrailingslashit( $source ) === $expected ) {
			return $source;
		}

		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), $expected ) ) {
			return trailingslashit( $expected );
		}

		return $source;
	}

	/* ---------------------------------------------------------------------
	 * Manifest
	 * ------------------------------------------------------------------ */

	/**
	 * Fetch and validate the manifest, with caching.
	 *
	 * @return array|null Normalised manifest, or null when unavailable/invalid.
	 */
	public static function get_manifest() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Mirrors core's own force-check handling on update-core.php.
		$force = is_admin() && ! empty( $_GET['force-check'] );

		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$manifest = self::fetch_manifest();

		if ( $manifest ) {
			set_site_transient( self::CACHE_KEY, $manifest, self::CACHE_TTL );
			return $manifest;
		}

		// Negative cache so a dead URL is not retried on every check.
		set_site_transient( self::CACHE_KEY, array( 'error' => true ), self::ERROR_TTL );
		return null;
	}

	/**
	 * Download and validate the manifest.
	 *
	 * @return array|null
	 */
	private static function fetch_manifest() {
		$response = wp_remote_get(
			self::manifest_url(),
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		return self::normalise( $data );
	}

	/**
	 * Validate required fields and fill defaults.
	 *
	 * @param array $data Decoded JSON.
	 * @return array|null
	 */
	private static function normalise( $data ) {
		$version = isset( $data['version'] ) ? trim( (string) $data['version'] ) : '';
		if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
			return null;
		}

		$download = isset( $data['download_url'] ) ? trim( (string) $data['download_url'] ) : '';
		if ( 0 !== strpos( $download, self::package_prefix() ) ) {
			// Refuse to install a package from anywhere but this repository's releases.
			return null;
		}

		$sections = isset( $data['sections'] ) && is_array( $data['sections'] ) ? $data['sections'] : array();
		$sections = array_map( 'wp_kses_post', array_filter( $sections, 'is_string' ) );
		if ( empty( $sections['changelog'] ) ) {
			$sections['changelog'] = '<p>' . esc_html__( 'See the GitHub release notes.', 'cy-user-login-watcher' ) . '</p>';
		}

		return array(
			'name'            => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : 'CY User Login Watcher',
			'version'         => $version,
			'download_url'    => esc_url_raw( $download ),
			'homepage'        => isset( $data['homepage'] ) ? esc_url_raw( $data['homepage'] ) : 'https://github.com/' . CY_ULW_GITHUB_REPO,
			'requires'        => isset( $data['requires'] ) ? sanitize_text_field( $data['requires'] ) : '',
			'tested'          => isset( $data['tested'] ) ? sanitize_text_field( $data['tested'] ) : '',
			'requires_php'    => isset( $data['requires_php'] ) ? sanitize_text_field( $data['requires_php'] ) : '',
			'last_updated'    => isset( $data['last_updated'] ) ? sanitize_text_field( $data['last_updated'] ) : '',
			'author'          => isset( $data['author'] ) ? sanitize_text_field( $data['author'] ) : 'Control Yours',
			'author_homepage' => isset( $data['author_homepage'] ) ? esc_url_raw( $data['author_homepage'] ) : 'https://controlyours.com',
			'upgrade_notice'  => isset( $data['upgrade_notice'] ) ? sanitize_text_field( $data['upgrade_notice'] ) : '',
			'sections'        => $sections,
		);
	}
}
