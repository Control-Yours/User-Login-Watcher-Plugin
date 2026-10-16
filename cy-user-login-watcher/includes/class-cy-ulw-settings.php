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
	 * Register the option and its sanitizer.
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
	 * Output the page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = cy_ulw_settings();
		$roles    = wp_roles()->get_names();
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
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
