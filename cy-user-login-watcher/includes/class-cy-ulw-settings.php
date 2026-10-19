<?php
/**
 * Settings screen: Settings > Login Watcher. Loaded only on non-ajax wp-admin
 * requests. Reading settings elsewhere goes through cy_ulw_setting() in the
 * main plugin file.
 *
 * @package CY_User_Login_Watcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page.
 */
final class CY_ULW_Settings {

	/**
	 * Settings API group / page slug.
	 */
	const PAGE = 'cy-user-login-watcher';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'plugin_action_links_' . CY_ULW_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add Settings > Login Watcher.
	 */
	public static function add_page() {
		add_options_page(
			__( 'Login Watcher', 'cy-user-login-watcher' ),
			__( 'Login Watcher', 'cy-user-login-watcher' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Settings link on the Plugins row.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'cy-user-login-watcher' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Register the options and their sanitizers.
	 */
	public static function register() {
		register_setting(
			self::PAGE,
			CY_ULW_SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => cy_ulw_default_settings(),
			)
		);
		register_setting(
			self::PAGE,
			CY_ULW_Reporter::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_reporter' ),
			)
		);
	}

	/**
	 * Validate submitted settings. Unknown roles are dropped. The activity interval is not
	 * exposed on the page; it keeps its default unless the cy_ulw_activity_throttle filter changes it.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = cy_ulw_default_settings();
		$input    = is_array( $input ) ? $input : array();
		$known    = array_keys( wp_roles()->roles );

		$excluded = isset( $input['excluded_roles'] ) ? array_map( 'sanitize_key', (array) $input['excluded_roles'] ) : array();
		$view     = isset( $input['view_roles'] ) ? array_map( 'sanitize_key', (array) $input['view_roles'] ) : array();

		return array(
			'excluded_roles'   => array_values( array_intersect( $excluded, $known ) ),
			'view_roles'       => array_values( array_intersect( $view, $known ) ),
		);
	}

	/**
	 * Store the "Send login data" checkbox as the reporter's disabled flag.
	 *
	 * The form only posts an "enabled" field (a hidden 0 followed by the
	 * checkbox). Everything else in the option (site key, timestamps, last
	 * error) is written by CY_ULW_Reporter through update_option(), which
	 * runs through this same sanitizer on admin requests, so an array without
	 * an "enabled" key is passed straight through.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize_reporter( $input ) {
		$current = get_option( CY_ULW_Reporter::OPTION );
		$current = is_array( $current ) ? $current : array();

		if ( is_array( $input ) && isset( $input['enabled'] ) ) {
			$current['disabled'] = empty( $input['enabled'] );
			return $current;
		}

		return is_array( $input ) ? $input : $current;
	}

	/**
	 * Output the page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = cy_ulw_settings();
		$roles    = wp_roles()->get_names();
		$reporter = CY_ULW_Reporter::config();
		$locked   = CY_ULW_Reporter::disabled_by_constant();
		$host     = wp_parse_url( CY_ULW_Reporter::endpoint(), PHP_URL_HOST );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Login Watcher', 'cy-user-login-watcher' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Roles not tracked', 'cy-user-login-watcher' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Roles not tracked', 'cy-user-login-watcher' ); ?></legend>
								<?php foreach ( $roles as $slug => $name ) : ?>
									<label style="display:block;margin-bottom:4px">
										<input type="checkbox" name="<?php echo esc_attr( CY_ULW_SETTINGS_OPTION ); ?>[excluded_roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $settings['excluded_roles'], true ) ); ?>>
										<?php echo esc_html( translate_user_role( $name ) ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Logins and activity are not recorded for users whose only roles are ticked here. They show "Not tracked" on the Users screen and are left out of the Dashboard widget count. Untick Subscriber on a site where members matter.', 'cy-user-login-watcher' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Who can see login data', 'cy-user-login-watcher' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Who can see login data', 'cy-user-login-watcher' ); ?></legend>
								<?php foreach ( $roles as $slug => $name ) : ?>
									<label style="display:block;margin-bottom:4px">
										<input type="checkbox" name="<?php echo esc_attr( CY_ULW_SETTINGS_OPTION ); ?>[view_roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( 'administrator' === $slug || in_array( $slug, $settings['view_roles'], true ) ); ?> <?php disabled( 'administrator' === $slug ); ?>>
										<?php echo esc_html( translate_user_role( $name ) ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'The Last Login column and the Recent Logins widget are shown to these roles. Administrators always see them.', 'cy-user-login-watcher' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Dashboard reporting', 'cy-user-login-watcher' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Admin Dashboard', 'cy-user-login-watcher' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Admin Dashboard', 'cy-user-login-watcher' ); ?></legend>
								<?php if ( ! $locked ) : ?>
									<input type="hidden" name="<?php echo esc_attr( CY_ULW_Reporter::OPTION ); ?>[enabled]" value="0">
								<?php endif; ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( CY_ULW_Reporter::OPTION ); ?>[enabled]" value="1" <?php checked( ! $locked && empty( $reporter['disabled'] ) ); ?> <?php disabled( $locked ); ?>>
									<?php esc_html_e( 'Send login data to the Control Yours Admin Dashboard', 'cy-user-login-watcher' ); ?>
								</label>
								<?php if ( $locked ) : ?>
									<p class="description"><?php esc_html_e( 'Switched off by the CY_ULW_DASHBOARD_REPORTING constant in wp-config.php.', 'cy-user-login-watcher' ); ?></p>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'Logins and admin activity are sent as they happen, and the full list of tracked users once a day, so the team can see who has been in the site without logging in here. Nothing else leaves the site.', 'cy-user-login-watcher' ); ?></p>
								<?php endif; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'cy-user-login-watcher' ); ?></th>
						<td>
							<p>
								<?php esc_html_e( 'Dashboard:', 'cy-user-login-watcher' ); ?>
								<code><?php echo esc_html( $host ? $host : CY_ULW_Reporter::endpoint() ); ?></code>
							</p>
							<p>
								<?php esc_html_e( 'Site key:', 'cy-user-login-watcher' ); ?>
								<code><?php echo esc_html( substr( (string) $reporter['site_key'], 0, 8 ) ); ?>&hellip;</code>
								<span class="description"><?php esc_html_e( 'The Pending list on the dashboard shows this same prefix; approve the site there to start seeing its logins.', 'cy-user-login-watcher' ); ?></span>
							</p>
							<p>
								<?php esc_html_e( 'Last sent:', 'cy-user-login-watcher' ); ?>
								<?php echo esc_html( self::format_time( (int) $reporter['last_sent'] ) ); ?>
							</p>
							<p>
								<?php esc_html_e( 'Last snapshot:', 'cy-user-login-watcher' ); ?>
								<?php echo esc_html( self::format_time( (int) $reporter['last_snapshot'] ) ); ?>
								<?php if ( 202 === (int) $reporter['last_code'] ) : ?>
									<span class="description"><?php esc_html_e( '(received, waiting for approval on the dashboard)', 'cy-user-login-watcher' ); ?></span>
								<?php endif; ?>
							</p>
							<p>
								<?php esc_html_e( 'Last error:', 'cy-user-login-watcher' ); ?>
								<?php echo esc_html( '' !== (string) $reporter['last_error'] ? $reporter['last_error'] : __( 'None', 'cy-user-login-watcher' ) ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * "3 hours ago (19 October 2026 14:05)" or "Never".
	 *
	 * @param int $timestamp Unix timestamp, 0 for never.
	 * @return string
	 */
	private static function format_time( $timestamp ) {
		if ( $timestamp <= 0 ) {
			return __( 'Never', 'cy-user-login-watcher' );
		}

		$now = time();
		if ( $timestamp > $now ) {
			$timestamp = $now;
		}

		return sprintf(
			/* translators: 1: human-readable time difference, e.g. "3 hours", 2: exact date and time. */
			__( '%1$s ago (%2$s)', 'cy-user-login-watcher' ),
			human_time_diff( $timestamp, $now ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp )
		);
	}
}
