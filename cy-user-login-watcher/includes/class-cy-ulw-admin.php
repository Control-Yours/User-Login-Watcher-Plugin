<?php
/**
 * Admin UI: sortable "Last Login" column on the Users screen and the
 * "Recent Logins" Dashboard widget. Loaded only on non-ajax wp-admin requests.
 *
 * @package CY_User_Login_Watcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only display code.
 */
final class CY_ULW_Admin {

	/**
	 * Orderby key used for the sortable Users column.
	 */
	const ORDERBY = 'cy_last_login';

	/**
	 * Column ID on the Users list table.
	 */
	const COLUMN = 'cy_last_login';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_ui' ) );
		add_action( 'pre_user_query', array( __CLASS__, 'apply_orderby' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );
		add_action( 'wp_network_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );
	}

	/* ---------------------------------------------------------------------
	 * Capability
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the current user may see login data.
	 *
	 * @return bool
	 */
	private static function current_user_can_view() {
		$cap = is_network_admin() ? 'manage_network_users' : 'manage_options';

		/**
		 * Filter the capability required to see the Last Login column and widget.
		 *
		 * @param string $cap Default 'manage_options' ('manage_network_users' in the network admin).
		 */
		if ( current_user_can( (string) apply_filters( 'cy_ulw_view_capability', $cap ) ) ) {
			return true;
		}

		// Extra roles allowed by Settings > Login Watcher.
		$user = wp_get_current_user();
		return $user && $user->ID && (bool) array_intersect( (array) $user->roles, (array) cy_ulw_setting( 'view_roles' ) );
	}

	/* ---------------------------------------------------------------------
	 * Users list column
	 * ------------------------------------------------------------------ */

	/**
	 * Register the Users list column hooks for users who may see them.
	 */
	public static function register_ui() {
		if ( ! self::current_user_can_view() ) {
			return;
		}

		foreach ( array( 'users', 'users-network' ) as $screen ) {
			add_filter( "manage_{$screen}_columns", array( __CLASS__, 'add_column' ) );
			add_filter( "manage_{$screen}_sortable_columns", array( __CLASS__, 'add_sortable_column' ) );
		}
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );

		add_action( 'admin_head', array( __CLASS__, 'print_styles' ) );
	}

	/**
	 * Insert the "Last Login" column before the Posts column (or at the end).
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$label = __( 'Last Login', 'cy-user-login-watcher' );

		if ( isset( $columns['posts'] ) ) {
			$new = array();
			foreach ( $columns as $key => $title ) {
				if ( 'posts' === $key ) {
					$new[ self::COLUMN ] = $label;
				}
				$new[ $key ] = $title;
			}
			return $new;
		}

		$columns[ self::COLUMN ] = $label;
		return $columns;
	}

	/**
	 * Make the column sortable. First click sorts DESC (most recent first).
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function add_sortable_column( $columns ) {
		$columns[ self::COLUMN ] = array( self::ORDERBY, true );
		return $columns;
	}

	/**
	 * Render the column cell.
	 *
	 * @param string $output      Current output.
	 * @param string $column_name Column ID.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public static function render_column( $output, $column_name, $user_id ) {
		if ( self::COLUMN !== $column_name ) {
			return $output;
		}
		return self::render_status( (int) $user_id );
	}

	/**
	 * Build the HTML for a user's login status (used by the column and the widget).
	 *
	 * Both meta reads hit the user meta cache that the list table / get_users()
	 * already primed, so this adds no queries per row.
	 *
	 * @param int $user_id User ID.
	 * @return string HTML.
	 */
	public static function render_status( $user_id ) {
		$login  = (int) get_user_meta( $user_id, CY_ULW_Tracker::META_LAST_LOGIN, true );
		$active = (int) get_user_meta( $user_id, CY_ULW_Tracker::META_LAST_ACTIVE, true );

		if ( ! $login && ! CY_ULW_Tracker::is_tracked_role( $user_id ) ) {
			return '<span class="cy-ulw-never" title="' . esc_attr__( 'Users with only an excluded role (Subscriber, Customer) are not tracked.', 'cy-user-login-watcher' ) . '">' . esc_html__( 'Not tracked', 'cy-user-login-watcher' ) . '</span>';
		}

		if ( ! $login ) {
			$html = '<span class="cy-ulw-never">' . esc_html__( 'No login recorded', 'cy-user-login-watcher' ) . '</span>';

			$installed = (int) get_option( CY_ULW_Tracker::OPTION_INSTALLED );
			if ( $installed ) {
				$html .= '<br><span class="cy-ulw-sub">' . sprintf(
					/* translators: %s: date the plugin was activated. */
					esc_html__( 'since tracking began %s', 'cy-user-login-watcher' ),
					esc_html( wp_date( get_option( 'date_format' ), $installed ) )
				) . '</span>';
			}
			return $html;
		}

		$login_ago = self::format_ago( $login );

		$html = sprintf(
			'<span class="cy-ulw-login" title="%1$s">%2$s</span>',
			esc_attr( self::format_exact( $login ) ),
			esc_html( $login_ago )
		);

		// Only show "last active" when it is meaningfully later than the login
		// itself and would not just repeat the same relative time.
		if ( $active && ( $active - $login ) > 5 * MINUTE_IN_SECONDS ) {
			$active_ago = self::format_ago( $active );

			if ( $active_ago !== $login_ago ) {
				$html .= sprintf(
					'<br><span class="cy-ulw-sub" title="%1$s">%2$s</span>',
					esc_attr( self::format_exact( $active ) ),
					esc_html(
						sprintf(
							/* translators: %s: human-readable time difference, e.g. "3 hours ago". */
							__( 'Active %s', 'cy-user-login-watcher' ),
							$active_ago
						)
					)
				);
			}
		}

		return $html;
	}

	/**
	 * Sort by last login when requested, keeping users with no record in the list.
	 *
	 * Uses a LEFT JOIN on the indexed (user_id, meta_key) columns so users
	 * without the meta row sort to the bottom (DESC) or top (ASC) instead of
	 * disappearing, which is what an INNER JOIN via meta_key/meta_value_num
	 * would do. Only runs when the admin actually clicks the column header.
	 *
	 * @param WP_User_Query $query The user query (passed by reference).
	 */
	public static function apply_orderby( $query ) {
		if ( self::ORDERBY !== $query->get( 'orderby' ) ) {
			return;
		}

		global $wpdb;

		$order = strtoupper( (string) $query->get( 'order' ) );
		$order = ( 'ASC' === $order ) ? 'ASC' : 'DESC';
		$alias = 'cy_ulw_ll';

		$query->query_from .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->usermeta} AS {$alias} ON ( {$wpdb->users}.ID = {$alias}.user_id AND {$alias}.meta_key = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names and alias are trusted.
			CY_ULW_Tracker::META_LAST_LOGIN
		);

		$query->query_orderby = "ORDER BY COALESCE( CAST( {$alias}.meta_value AS UNSIGNED ), 0 ) {$order}, {$wpdb->users}.user_login ASC";
	}

	/**
	 * Small amount of inline CSS for the column and widget (Users and Dashboard screens only).
	 */
	public static function print_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'users', 'users-network', 'dashboard', 'dashboard-network' ), true ) ) {
			return;
		}

		echo '<style id="cy-ulw-styles">'
			. '.column-cy_last_login{width:14%}'
			. '.cy-ulw-sub,.cy-ulw-never{color:#646970}'
			. '.cy-ulw-sub{font-size:12px}'
			. '#cy_ulw_recent_logins .inside{margin:0;padding:0}'
			. '#cy_ulw_recent_logins table{width:100%;border-collapse:collapse}'
			. '#cy_ulw_recent_logins th,#cy_ulw_recent_logins td{padding:8px 12px;text-align:left;vertical-align:top;border-bottom:1px solid #f0f0f1}'
			. '#cy_ulw_recent_logins th{font-weight:600}'
			. '#cy_ulw_recent_logins tr:last-child td{border-bottom:0}'
			. '#cy_ulw_recent_logins .cy-ulw-footer{padding:8px 12px;color:#646970}'
			. '</style>';
	}

	/* ---------------------------------------------------------------------
	 * Dashboard widget
	 * ------------------------------------------------------------------ */

	/**
	 * Register the "Recent Logins" dashboard widget for admins.
	 */
	public static function register_dashboard_widget() {
		if ( ! self::current_user_can_view() ) {
			return;
		}

		wp_add_dashboard_widget(
			'cy_ulw_recent_logins',
			__( 'Recent Logins', 'cy-user-login-watcher' ),
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	/**
	 * Output the widget contents.
	 */
	public static function render_dashboard_widget() {
		/**
		 * Filter how many users the Recent Logins widget lists.
		 *
		 * @param int $count Default 10.
		 */
		$count = max( 1, (int) apply_filters( 'cy_ulw_widget_count', 10 ) );

		$args = array(
			'meta_key' => CY_ULW_Tracker::META_LAST_LOGIN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Intentional; small, indexed, admin-only.
			'orderby'  => 'meta_value_num',
			'order'    => 'DESC',
			'number'   => $count,
		);
		if ( is_network_admin() ) {
			$args['blog_id'] = 0;
		}

		$users = get_users( $args );

		if ( empty( $users ) ) {
			echo '<p class="cy-ulw-footer">' . esc_html__( 'No logins recorded yet. Logins are tracked from the time this plugin was activated.', 'cy-user-login-watcher' ) . '</p>';
			return;
		}

		$role_names = wp_roles()->role_names;

		echo '<table>';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'User', 'cy-user-login-watcher' ) . '</th>';
		echo '<th>' . esc_html__( 'Role', 'cy-user-login-watcher' ) . '</th>';
		echo '<th>' . esc_html__( 'Last Login', 'cy-user-login-watcher' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $users as $user ) {
			$roles = array();
			foreach ( (array) $user->roles as $role ) {
				$roles[] = isset( $role_names[ $role ] ) ? translate_user_role( $role_names[ $role ] ) : $role;
			}

			$edit_link = get_edit_user_link( $user->ID );

			echo '<tr>';
			echo '<td>';
			if ( $edit_link ) {
				echo '<a href="' . esc_url( $edit_link ) . '"><strong>' . esc_html( $user->display_name ) . '</strong></a>';
			} else {
				echo '<strong>' . esc_html( $user->display_name ) . '</strong>';
			}
			echo '<br><span class="cy-ulw-sub">' . esc_html( $user->user_login ) . '</span>';
			echo '</td>';
			echo '<td>' . esc_html( $roles ? implode( ', ', $roles ) : '—' ) . '</td>';
			echo '<td>' . self::render_status( $user->ID ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_status() escapes everything it outputs.
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Footer: how many tracked users have never logged in since tracking began.
		// Excluded roles (subscribers, customers) are left out so a store does not
		// report every customer as "never logged in".
		$never_args = array(
			'number'       => 1,
			'fields'       => 'ID',
			'count_total'  => true,
			'role__not_in' => CY_ULW_Tracker::excluded_roles(),
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional; runs only on the admin dashboard.
				array(
					'key'     => CY_ULW_Tracker::META_LAST_LOGIN,
					'compare' => 'NOT EXISTS',
				),
			),
		);
		if ( is_network_admin() ) {
			$never_args['blog_id'] = 0;
		}
		$never_query = new WP_User_Query( $never_args );
		$never       = (int) $never_query->get_total();

		$users_url = is_network_admin() ? network_admin_url( 'users.php' ) : admin_url( 'users.php' );

		echo '<p class="cy-ulw-footer">';
		if ( $never > 0 ) {
			printf(
				/* translators: 1: number of users, 2: link to the Users screen sorted by last login. */
				esc_html( _n( '%1$s user has not logged in since tracking began. %2$s', '%1$s users have not logged in since tracking began. %2$s', $never, 'cy-user-login-watcher' ) ),
				esc_html( number_format_i18n( $never ) ),
				'<a href="' . esc_url( add_query_arg( array( 'orderby' => self::ORDERBY, 'order' => 'asc' ), $users_url ) ) . '">' . esc_html__( 'View', 'cy-user-login-watcher' ) . '</a>'
			);
		} else {
			echo '<a href="' . esc_url( add_query_arg( array( 'orderby' => self::ORDERBY, 'order' => 'desc' ), $users_url ) ) . '">' . esc_html__( 'View all users by last login', 'cy-user-login-watcher' ) . '</a>';
		}
		echo '</p>';
	}

	/* ---------------------------------------------------------------------
	 * Formatting helpers
	 * ------------------------------------------------------------------ */

	/**
	 * "3 hours ago" style string.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function format_ago( $timestamp ) {
		$now = time();
		if ( $timestamp > $now ) {
			$timestamp = $now;
		}
		if ( ( $now - $timestamp ) < MINUTE_IN_SECONDS ) {
			return __( 'Just now', 'cy-user-login-watcher' );
		}
		/* translators: %s: human-readable time difference, e.g. "3 hours". */
		return sprintf( __( '%s ago', 'cy-user-login-watcher' ), human_time_diff( $timestamp, $now ) );
	}

	/**
	 * Exact date/time in the site's timezone and formats.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function format_exact( $timestamp ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
