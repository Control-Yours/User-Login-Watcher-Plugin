<?php
/**
 * Site inventory: a one-time list of what this site is made of, sent to the
 * Control Yours Admin Dashboard so the team can plan the site's move to
 * SiteForge without logging in to it.
 *
 * Nothing is sent unless an inventory was asked for, in one of two ways:
 *
 *  - the dashboard answers the daily snapshot with {"requests": ["inventory"]}
 *    (see CY_ULW_Reporter::handle_requests()), or
 *  - an administrator presses "Send site inventory now" under
 *    Settings > CY Connector.
 *
 * Both only queue the one-off cy_ulw_inventory_run cron event. The inventory
 * is built and sent there, never in a login, admin or front-end request.
 *
 * What is collected (inventory_version 1): site identity, theme, installed
 * plugins, public content types with counts, published pages (structure and
 * the builder each was made with), menus, a media count and size, and a few
 * yes/no flags derived from the active plugins. What is never collected:
 * users, form entries, orders, customers, option values and post content.
 * Page content is tested for builder markup inside the database query and is
 * never loaded here.
 *
 * It is sent as a series of JSON chunks, each under 1 MB, sharing one
 * inventory ID and numbered from 1; the last chunk is marked complete. A run
 * that is taking too long or too much memory pauses and continues in a new
 * cron run from where it stopped.
 *
 * Everything goes through CY_ULW_Reporter::post_json(), so the on/off switch,
 * the staging rules, the site key and CY_ULW_DASHBOARD_URL apply here exactly
 * as they do to login reports. This class never changes the reporter's state:
 * a failed inventory shows on the settings screen and affects nothing else.
 *
 * Loaded only when the cron event runs, when the dashboard asks for an
 * inventory, and on the settings screen; see cy_ulw_load_inventory().
 *
 * @package CY_User_Login_Watcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and sends the site inventory.
 */
final class CY_ULW_Inventory {

	/**
	 * Option (not autoloaded): the state of the last or current inventory. See defaults().
	 */
	const OPTION = 'cy_ulw_inventory';

	/**
	 * Shape of the payload. Raise it when a field changes meaning or goes away.
	 */
	const VERSION = 1;

	/**
	 * Path on the dashboard that receives the chunks.
	 */
	const PATH = '/api/sites/inventory';

	/**
	 * Pages read per query.
	 */
	const PAGE_BATCH = 200;

	/**
	 * Attachments read per query when adding up media sizes.
	 */
	const MEDIA_BATCH = 500;

	/**
	 * Seconds the media size count may take. Past it the total is sent as incomplete.
	 */
	const MEDIA_SECONDS = 8;

	/**
	 * Largest request body, in bytes. The promise is "under 1 MB"; this leaves room.
	 */
	const MAX_CHUNK_BYTES = 900000;

	/**
	 * Seconds one cron run may spend before it pauses and continues in the next.
	 */
	const TIME_BUDGET = 20;

	/**
	 * Share of the PHP memory limit past which a run pauses.
	 */
	const MEMORY_SHARE = 0.7;

	/**
	 * Most cron runs one inventory may take. A guard against a loop, far above
	 * what any real site needs.
	 */
	const MAX_RUNS = 200;

	/**
	 * Seconds after which a queued or running inventory that shows no sign of
	 * life counts as dead, so a new request may replace it.
	 */
	const STALE_AFTER = 900;

	/**
	 * Published posts above which the site counts as having a blog.
	 */
	const BLOG_MIN_POSTS = 6;

	/**
	 * Active plugin folders behind each flag.
	 */
	const FLAG_PLUGINS = array(
		'woocommerce'   => array( 'woocommerce' ),
		'learning'      => array( 'sfwd-lms', 'lifterlms', 'tutor', 'tutor-pro', 'learnpress', 'sensei-lms', 'masterstudy-lms-learning-management-system', 'wp-courseware' ),
		'forum'         => array( 'bbpress', 'buddypress', 'buddyboss-platform', 'wpforo', 'asgaros-forum' ),
		'multilingual'  => array( 'sitepress-multilingual-cms', 'polylang', 'polylang-pro', 'translatepress-multilingual', 'weglot', 'gtranslate', 'qtranslate-xt' ),
		'form_payments' => array(
			'gravityformsstripe',
			'gravityformspaypal',
			'gravityformsppcp',
			'gravityformspaypalpaymentspro',
			'gravityformsauthorizenet',
			'gravityformssquare',
			'gravityformsmollie',
			'gravityforms2checkout',
			'wpforms-stripe',
			'wpforms-paypal-standard',
			'wpforms-paypal-commerce',
			'wpforms-square',
			'wpforms-authorize-net',
			'formidable-stripe',
			'formidable-paypal',
			'ninja-forms-stripe',
			'ninja-forms-paypal-express',
			'give',
			'wp-simple-pay',
			'wp-simple-pay-pro-3',
			'stripe',
			'stripe-payments',
			'wp-full-stripe',
		),
		'events'        => array( 'the-events-calendar', 'events-calendar-pro', 'event-tickets', 'event-tickets-plus', 'events-manager', 'modern-events-calendar', 'modern-events-calendar-lite', 'eventon', 'event-espresso-core', 'sugar-calendar', 'sugar-calendar-lite', 'all-in-one-event-calendar', 'wp-event-solution' ),
	);

	/**
	 * State of the run in progress. Loaded by run(), saved by save().
	 *
	 * @var array
	 */
	private static $state = array();

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------ */

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'status'       => '',  // '', queued, running, paused, complete, failed.
			'requested_at' => 0,   // Unix: when the inventory was asked for.
			'requested_by' => '',  // 'dashboard' or 'button'.
			'inventory_id' => '',  // UUID shared by every chunk of one inventory.
			'phase'        => '',  // What is sent next: site, menus, pages, final.
			'chunk'        => 0,   // Chunks the dashboard has accepted so far.
			'after_id'     => 0,   // Pages up to this ID have been sent.
			'pages'        => 0,   // Pages sent so far.
			'runs'         => 0,   // Cron runs this inventory has used.
			'started_at'   => 0,   // Unix: first cron run.
			'heartbeat'    => 0,   // Unix: last sign of life from a run.
			'finished_at'  => 0,   // Unix: when it completed or failed.
			'code'         => 0,   // HTTP status of the last chunk (0 = no response).
			'message'      => '',  // Why it failed, '' otherwise.
		);
	}

	/**
	 * Saved state merged over the defaults.
	 *
	 * @return array
	 */
	public static function state() {
		$saved = get_option( self::OPTION );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Store the state. Not autoloaded: only the settings screen and cron read it.
	 */
	private static function save() {
		update_option( self::OPTION, self::$state, false );
	}

	/**
	 * Whether an inventory is queued or under way and still showing signs of life.
	 *
	 * @param array $state State array.
	 * @return bool
	 */
	private static function is_active( array $state ) {
		if ( ! in_array( $state['status'], array( 'queued', 'running', 'paused' ), true ) ) {
			return false;
		}
		return max( (int) $state['requested_at'], (int) $state['heartbeat'] ) > time() - self::STALE_AFTER;
	}

	/* ---------------------------------------------------------------------
	 * Asking for an inventory
	 * ------------------------------------------------------------------ */

	/**
	 * Queue an inventory: remember who asked and schedule the one-off cron
	 * event. Does nothing when reporting is off or this is a staging or local
	 * copy, and nothing new when one is already queued or running.
	 *
	 * @param string $source 'dashboard' (asked for in a snapshot response) or 'button'.
	 * @return bool Whether an inventory is now queued or running.
	 */
	public static function request( $source ) {
		if ( ! CY_ULW_Reporter::is_enabled() ) {
			return false;
		}

		$state = self::state();

		if ( ! self::is_active( $state ) ) {
			self::$state = array_merge(
				self::defaults(),
				array(
					'status'       => 'queued',
					'requested_at' => time(),
					'requested_by' => 'button' === $source ? 'button' : 'dashboard',
				)
			);
			self::save();
			$state = self::$state;
		}

		// A run in progress needs no event; a queued or paused one does, and
		// may have lost it (the plugin was switched off and on again).
		if ( 'running' !== $state['status'] ) {
			self::schedule();
		}

		return true;
	}

	/**
	 * Schedule the one-off cron event unless it is already waiting.
	 */
	private static function schedule() {
		if ( ! wp_next_scheduled( CY_ULW_INVENTORY_HOOK ) ) {
			wp_schedule_single_event( time(), CY_ULW_INVENTORY_HOOK );
		}
	}

	/* ---------------------------------------------------------------------
	 * The run
	 * ------------------------------------------------------------------ */

	/**
	 * Build and send the inventory, or continue one that paused. Runs on the
	 * cy_ulw_inventory_run cron event. Does nothing unless an inventory is
	 * queued or paused, so a stray event sends nothing.
	 */
	public static function run() {
		self::$state = self::state();

		if ( ! in_array( self::$state['status'], array( 'queued', 'paused' ), true ) ) {
			return;
		}

		if ( ! CY_ULW_Reporter::is_enabled() ) {
			self::finish( 'failed', 'Dashboard reporting is switched off for this site, so nothing was sent.' );
			return;
		}

		if ( 'queued' === self::$state['status'] ) {
			self::$state['inventory_id'] = wp_generate_uuid4();
			self::$state['phase']        = 'site';
			self::$state['started_at']   = time();
		}

		self::$state['status']    = 'running';
		self::$state['heartbeat'] = time();
		self::$state['runs']      = (int) self::$state['runs'] + 1;
		self::save();

		if ( self::$state['runs'] > self::MAX_RUNS ) {
			self::finish( 'failed', 'Gave up after ' . self::MAX_RUNS . ' background runs without finishing.' );
			return;
		}

		$started = microtime( true );

		try {
			while ( true ) {
				if ( self::step() ) {
					self::finish( 'complete', '' );
					break;
				}

				self::$state['heartbeat'] = time();
				self::save();

				if ( self::should_pause( $started ) ) {
					self::$state['status'] = 'paused';
					self::save();
					self::schedule();
					break;
				}
			}
		} catch ( RuntimeException $e ) {
			// A chunk the dashboard did not take, or one that cannot be sent.
			$message = $e->getMessage();
			if ( (int) self::$state['chunk'] > 0 ) {
				$message .= ' Stopped after chunk ' . (int) self::$state['chunk'] . '; the dashboard holds an incomplete inventory.';
			}
			self::finish( 'failed', $message );
		} catch ( Throwable $e ) {
			self::finish( 'failed', 'Stopped by an error in the plugin: ' . self::short( $e->getMessage() ) );
		}
	}

	/**
	 * Send the next part of the inventory and move the state on.
	 *
	 * @return bool True when the final chunk has been accepted.
	 * @throws RuntimeException When a chunk cannot be sent or is not accepted.
	 */
	private static function step() {
		switch ( self::$state['phase'] ) {
			case 'site':
				$plugins = self::plugins();
				self::send(
					array(
						'site'        => self::site(),
						'theme'       => self::theme(),
						'plugins'     => $plugins['plugins'],
						'mu_plugins'  => $plugins['mu_plugins'],
						'post_types'  => self::post_types(),
						'media'       => self::media(),
						'flags'       => self::flags( $plugins['active_slugs'] ),
					)
				);
				self::$state['phase'] = 'menus';
				return false;

			case 'menus':
				self::send_list( 'menus', self::menus() );
				self::$state['phase'] = 'pages';
				return false;

			case 'pages':
				$pages = self::pages( (int) self::$state['after_id'] );
				if ( $pages ) {
					self::send_list( 'pages', $pages );
					self::$state['after_id'] = (int) $pages[ count( $pages ) - 1 ]['id'];
					self::$state['pages']    = (int) self::$state['pages'] + count( $pages );
				}
				if ( count( $pages ) < self::PAGE_BATCH ) {
					self::$state['phase'] = 'final';
				}
				return false;

			case 'final':
				self::send(
					array(),
					array(
						'chunks' => (int) self::$state['chunk'] + 1,
						'pages'  => (int) self::$state['pages'],
					)
				);
				return true;
		}

		throw new RuntimeException( 'The saved inventory state is not usable; ask for the inventory again.' );
	}

	/**
	 * Whether this cron run should stop and let the next one continue.
	 *
	 * @param float $started microtime( true ) at the start of the run.
	 * @return bool
	 */
	private static function should_pause( $started ) {
		/**
		 * Filter how many seconds one cron run may spend on the inventory
		 * before it pauses and continues in the next run.
		 *
		 * @param int $seconds Default 20.
		 */
		$budget = (int) apply_filters( 'cy_ulw_inventory_time_budget', self::TIME_BUDGET );

		if ( microtime( true ) - $started >= $budget ) {
			return true;
		}

		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $limit > 0 && memory_get_usage( true ) > $limit * self::MEMORY_SHARE;
	}

	/**
	 * Record how the inventory ended.
	 *
	 * @param string $status  'complete' or 'failed'.
	 * @param string $message Why it failed, '' when it did not.
	 */
	private static function finish( $status, $message ) {
		self::$state['status']      = $status;
		self::$state['message']     = self::short( $message, 300 );
		self::$state['finished_at'] = time();
		self::$state['heartbeat']   = time();
		self::save();
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	/**
	 * Send one list section, split over as many chunks as it takes to keep
	 * each under the size limit.
	 *
	 * @param string $section Key under "data", e.g. 'pages'.
	 * @param array  $items   List of entries.
	 * @throws RuntimeException When a chunk cannot be sent or is not accepted.
	 */
	private static function send_list( $section, array $items ) {
		$items = array_values( $items );
		$count = count( $items );

		if ( $count > 1 && strlen( (string) wp_json_encode( $items ) ) > self::MAX_CHUNK_BYTES - 2000 ) {
			$half = (int) ceil( $count / 2 );
			self::send_list( $section, array_slice( $items, 0, $half ) );
			self::send_list( $section, array_slice( $items, $half ) );
			return;
		}

		self::send( array( $section => $items ) );
	}

	/**
	 * Send one chunk and count it. The final chunk is the one with totals.
	 *
	 * @param array      $data   Sections carried by this chunk.
	 * @param array|null $totals Totals for the final chunk, null for every other.
	 * @throws RuntimeException When the chunk cannot be sent or is not accepted.
	 */
	private static function send( array $data, $totals = null ) {
		$number = (int) self::$state['chunk'] + 1;

		$chunk = array(
			'type'              => 'inventory',
			'inventory_version' => self::VERSION,
			'inventory_id'      => (string) self::$state['inventory_id'],
			'chunk'             => $number,
			'complete'          => null !== $totals,
			'requested_by'      => (string) self::$state['requested_by'],
			'sent_at'           => time(),
			'site'              => array(
				'home_url'       => home_url(),
				'plugin_version' => CY_ULW_VERSION,
			),
			'data'              => $data ? $data : new stdClass(),
		);
		if ( null !== $totals ) {
			$chunk['totals'] = $totals;
		}

		$json = wp_json_encode( $chunk );
		if ( false === $json ) {
			throw new RuntimeException( 'Could not encode chunk ' . $number . ' as JSON.' );
		}
		if ( strlen( $json ) > self::MAX_CHUNK_BYTES ) {
			throw new RuntimeException( 'Chunk ' . $number . ' is larger than the dashboard accepts and cannot be split.' );
		}

		$response = CY_ULW_Reporter::post_json( self::PATH, $json );

		if ( is_wp_error( $response ) ) {
			self::$state['code'] = 0;
			throw new RuntimeException( 'No answer from the dashboard: ' . self::short( $response->get_error_message() ) );
		}

		$code                = (int) wp_remote_retrieve_response_code( $response );
		self::$state['code'] = $code;

		if ( $code < 200 || $code > 299 ) {
			throw new RuntimeException( self::describe_code( $code ) );
		}

		self::$state['chunk'] = $number;
	}

	/**
	 * Plain description of a refused chunk, for the settings screen.
	 *
	 * @param int $code HTTP status code.
	 * @return string
	 */
	private static function describe_code( $code ) {
		switch ( (int) $code ) {
			case 0:
				return 'No answer from the dashboard.';
			case 404:
			case 405:
				return 'The dashboard does not accept site inventories yet (HTTP ' . (int) $code . '), so nothing was stored. Login reporting is not affected.';
			case 401:
				return 'HTTP 401: site key missing.';
			case 409:
				return 'HTTP 409: site key does not match the one approved on the dashboard.';
			case 413:
				return 'HTTP 413: the dashboard found the chunk too large.';
			case 302:
			case 403:
				return 'HTTP ' . (int) $code . ': blocked in front of the dashboard (Cloudflare Access needs a bypass for ' . self::PATH . ').';
		}
		return 'The dashboard answered HTTP ' . (int) $code . '.';
	}

	/**
	 * Trim a message to something that fits in the option and on screen.
	 *
	 * @param string $message Raw message.
	 * @param int    $length  Longest result.
	 * @return string
	 */
	private static function short( $message, $length = 160 ) {
		$message = trim( wp_strip_all_tags( (string) $message ) );
		if ( strlen( $message ) > $length ) {
			$message = substr( $message, 0, $length - 3 ) . '...';
		}
		return $message;
	}

	/* ---------------------------------------------------------------------
	 * Settings screen
	 * ------------------------------------------------------------------ */

	/**
	 * One or two sentences on the last or current inventory.
	 *
	 * @return string
	 */
	public static function status_text() {
		$state = self::state();
		$now   = time();
		$by    = 'button' === $state['requested_by']
			? __( 'with the button on this screen', 'cy-user-login-watcher' )
			: __( 'by the Admin Dashboard', 'cy-user-login-watcher' );

		switch ( $state['status'] ) {
			case 'queued':
				return sprintf(
					/* translators: 1: time difference, e.g. "3 mins", 2: who asked. */
					__( 'Asked for %1$s ago %2$s. Waiting for the next background run, usually within a few minutes.', 'cy-user-login-watcher' ),
					human_time_diff( min( $now, (int) $state['requested_at'] ), $now ),
					$by
				);

			case 'running':
			case 'paused':
				if ( ! self::is_active( $state ) ) {
					return sprintf(
						/* translators: %s: time difference, e.g. "3 hours". */
						__( 'The last inventory stopped without finishing %s ago. Nothing else is affected; it can be sent again.', 'cy-user-login-watcher' ),
						human_time_diff( min( $now, (int) $state['heartbeat'] ), $now )
					);
				}
				return sprintf(
					/* translators: 1: number of chunks, 2: number of pages. */
					__( 'Being sent now: %1$d parts so far, %2$d pages.', 'cy-user-login-watcher' ),
					(int) $state['chunk'],
					(int) $state['pages']
				);

			case 'complete':
				return sprintf(
					/* translators: 1: time difference, 2: date and time, 3: number of pages, 4: number of chunks, 5: who asked. */
					__( 'Sent %1$s ago (%2$s): %3$d pages in %4$d parts, all accepted by the dashboard. Asked for %5$s.', 'cy-user-login-watcher' ),
					human_time_diff( min( $now, (int) $state['finished_at'] ), $now ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['finished_at'] ),
					(int) $state['pages'],
					(int) $state['chunk'],
					$by
				);

			case 'failed':
				return sprintf(
					/* translators: 1: time difference, 2: date and time, 3: reason. */
					__( 'Not sent, %1$s ago (%2$s). %3$s', 'cy-user-login-watcher' ),
					human_time_diff( min( $now, (int) $state['finished_at'] ), $now ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['finished_at'] ),
					(string) $state['message']
				);
		}

		return __( 'No inventory has been asked for on this site, so none has been sent.', 'cy-user-login-watcher' );
	}

	/* ---------------------------------------------------------------------
	 * Collectors
	 * ------------------------------------------------------------------ */

	/**
	 * A stored name as plain text.
	 *
	 * @param string $text Text that may hold tags or HTML entities.
	 * @return string
	 */
	private static function plain( $text ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Site identity.
	 *
	 * @return array
	 */
	private static function site() {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		$icon    = get_site_icon_url( 512 );

		return array(
			'name'                => self::plain( get_bloginfo( 'name' ) ),
			'tagline'             => self::plain( get_bloginfo( 'description' ) ),
			'home_url'            => home_url(),
			'language'            => (string) get_locale(),
			'permalink_structure' => (string) get_option( 'permalink_structure' ),
			'logo_url'            => $logo ? (string) $logo : null,
			'icon_url'            => $icon ? (string) $icon : null,
			'wp_version'          => (string) get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'multisite'           => is_multisite(),
		);
	}

	/**
	 * Active theme and its parent, when it is a child theme.
	 *
	 * @return array
	 */
	private static function theme() {
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		return array(
			'name'    => self::plain( $theme->get( 'Name' ) ),
			'slug'    => (string) $theme->get_stylesheet(),
			'version' => (string) $theme->get( 'Version' ),
			'parent'  => $parent ? array(
				'name'    => self::plain( $parent->get( 'Name' ) ),
				'slug'    => (string) $parent->get_stylesheet(),
				'version' => (string) $parent->get( 'Version' ),
			) : null,
		);
	}

	/**
	 * Folder of a plugin, or its file name without ".php" for a single-file plugin.
	 *
	 * @param string $file Plugin file relative to the plugins folder.
	 * @return string
	 */
	private static function plugin_slug( $file ) {
		$file = (string) $file;
		return false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
	}

	/**
	 * Every installed plugin, the must-use plugins, and the folders of the active ones.
	 *
	 * @return array { plugins: array[], mu_plugins: array[], active_slugs: string[] }
	 */
	private static function plugins() {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active  = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
		$network = is_multisite() ? array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) : array();

		$plugins      = array();
		$active_slugs = array();

		foreach ( get_plugins() as $file => $data ) {
			$slug      = self::plugin_slug( $file );
			$is_active = in_array( (string) $file, $active, true ) || in_array( (string) $file, $network, true );

			if ( $is_active ) {
				$active_slugs[] = $slug;
			}

			$plugins[] = array(
				'slug'    => $slug,
				'file'    => (string) $file,
				'name'    => self::plain( isset( $data['Name'] ) ? $data['Name'] : '' ),
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'active'  => $is_active,
			);
		}

		$mu_plugins = array();
		foreach ( get_mu_plugins() as $file => $data ) {
			$mu_plugins[] = array(
				'slug'    => self::plugin_slug( $file ),
				'file'    => (string) $file,
				'name'    => self::plain( isset( $data['Name'] ) ? $data['Name'] : '' ),
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			);
		}

		return array(
			'plugins'      => $plugins,
			'mu_plugins'   => $mu_plugins,
			'active_slugs' => array_values( array_unique( $active_slugs ) ),
		);
	}

	/**
	 * Public content types with their published counts. Media is summarised
	 * separately, so attachments are left out.
	 *
	 * @return array[]
	 */
	private static function post_types() {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$counts  = wp_count_posts( $type->name );
			$types[] = array(
				'name'      => (string) $type->name,
				'label'     => self::plain( $type->label ),
				'published' => isset( $counts->publish ) ? (int) $counts->publish : 0,
				'builtin'   => (bool) $type->_builtin,
			);
		}

		return $types;
	}

	/**
	 * Yes/no flags the migration plan needs, from the active plugins and the
	 * number of published posts.
	 *
	 * @param string[] $active_slugs Folders of the active plugins.
	 * @return array
	 */
	private static function flags( array $active_slugs ) {
		$flags   = array();
		$matched = array();

		foreach ( self::FLAG_PLUGINS as $flag => $slugs ) {
			$found          = array_values( array_intersect( $slugs, $active_slugs ) );
			$flags[ $flag ] = (bool) $found;
			if ( $found ) {
				$matched[ $flag ] = $found;
			}
		}

		$posts              = wp_count_posts( 'post' );
		$published          = isset( $posts->publish ) ? (int) $posts->publish : 0;
		$flags['blog']       = $published >= self::BLOG_MIN_POSTS;
		$flags['blog_posts'] = $published;
		$flags['matched']    = $matched ? $matched : new stdClass();

		return $flags;
	}

	/**
	 * Media library: how many items and how many bytes the original files take.
	 * Generated image sizes are not counted. The size comes from the stored
	 * attachment details and, for older uploads without one, from the file on
	 * disk. On a very large library the count stops after a few seconds and
	 * says so.
	 *
	 * @return array
	 */
	private static function media() {
		global $wpdb;

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );

		$uploads  = wp_get_upload_dir();
		$basedir  = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		$bytes    = 0;
		$measured = 0;
		$after    = 0;
		$complete = true;
		$started  = microtime( true );

		do {
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
						$after,
						self::MEDIA_BATCH
					)
				)
			);
			if ( ! $ids ) {
				break;
			}
			$after = (int) end( $ids );

			$rows = $wpdb->get_results(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ") AND meta_key IN ('_wp_attached_file', '_wp_attachment_metadata')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IDs are integers from the query above.
			);

			$files = array();
			$sizes = array();
			foreach ( (array) $rows as $row ) {
				if ( '_wp_attached_file' === $row->meta_key ) {
					$files[ (int) $row->post_id ] = (string) $row->meta_value;
				} elseif ( preg_match( '/s:8:"filesize";i:(\d+);/', (string) $row->meta_value, $match ) ) {
					// The first "filesize" in the stored details is the original file's.
					$sizes[ (int) $row->post_id ] = (int) $match[1];
				}
			}
			unset( $rows );

			foreach ( $ids as $id ) {
				if ( isset( $sizes[ $id ] ) ) {
					$bytes += $sizes[ $id ];
					$measured++;
				} elseif ( isset( $files[ $id ] ) && '' !== $basedir && false === strpos( $files[ $id ], '://' ) && false === strpos( $files[ $id ], '..' ) ) {
					$path = 0 === strpos( $files[ $id ], '/' ) ? $files[ $id ] : $basedir . '/' . $files[ $id ];
					$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file is expected and simply not counted.
					if ( false !== $size ) {
						$bytes += (int) $size;
						$measured++;
					}
				}
			}

			if ( microtime( true ) - $started > self::MEDIA_SECONDS ) {
				$complete = false;
				break;
			}
		} while ( count( $ids ) === self::MEDIA_BATCH );

		return array(
			'count'          => $count,
			'bytes'          => $bytes,
			'files_measured' => $measured,
			'bytes_complete' => $complete,
		);
	}

	/**
	 * Every menu with its theme locations and items.
	 *
	 * @return array[]
	 */
	private static function menus() {
		$locations = array();
		foreach ( (array) get_nav_menu_locations() as $location => $menu_id ) {
			$locations[ (int) $menu_id ][] = (string) $location;
		}

		$menus = array();

		foreach ( (array) wp_get_nav_menus() as $menu ) {
			$items = array();
			$found = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );

			foreach ( is_array( $found ) ? $found : array() as $item ) {
				$items[] = array(
					'id'        => (int) $item->ID,
					'label'     => self::plain( $item->title ),
					'url'       => (string) $item->url,
					'type'      => (string) $item->type,      // post_type, taxonomy, custom or post_type_archive.
					'object'    => (string) $item->object,    // e.g. page, category, custom.
					'object_id' => (int) $item->object_id,    // The page or term the item points at; the item's own ID for a custom link.
					'parent'    => (int) $item->menu_item_parent,
				);
			}

			$menus[] = array(
				'id'        => (int) $menu->term_id,
				'name'      => self::plain( $menu->name ),
				'slug'      => (string) $menu->slug,
				'locations' => isset( $locations[ (int) $menu->term_id ] ) ? $locations[ (int) $menu->term_id ] : array(),
				'items'     => $items,
			);
		}

		return $menus;
	}

	/**
	 * The next batch of published pages after a given ID.
	 *
	 * Content never leaves the database: the query only reports whether it
	 * holds WPBakery or block markup. Only the four small meta values needed
	 * are read, not the page's whole meta (an Elementor page keeps megabytes
	 * of layout data there). The address comes from get_permalink(), given a
	 * post object built from the row (see bare_post()) so that WordPress does
	 * not load and cache every page, content included, to work it out. Parent
	 * pages are still loaded by WordPress, once each; on a site where that
	 * adds up, the memory check in should_pause() ends the run early and the
	 * next one continues.
	 *
	 * @param int $after_id Last page ID already sent.
	 * @return array[]
	 */
	private static function pages( $after_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_parent, menu_order, post_modified_gmt,
					( post_content LIKE %s ) AS has_wpbakery,
					( post_content LIKE %s ) AS has_blocks
				FROM {$wpdb->posts}
				WHERE post_type = 'page' AND post_status = 'publish' AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				'%' . $wpdb->esc_like( '[vc_row' ) . '%',
				'%' . $wpdb->esc_like( '<!-- wp:' ) . '%',
				(int) $after_id,
				self::PAGE_BATCH
			)
		);

		if ( ! $rows ) {
			return array();
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row->ID;
		}

		$meta      = array();
		$meta_rows = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ") AND meta_key IN ('_wp_page_template', '_elementor_edit_mode', '_et_pb_use_builder', '_fl_builder_enabled')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IDs are integers from the query above.
		);
		foreach ( (array) $meta_rows as $row ) {
			$meta[ (int) $row->post_id ][ (string) $row->meta_key ] = (string) $row->meta_value;
		}

		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$blog  = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;

		$pages = array();

		foreach ( $rows as $row ) {
			$id        = (int) $row->ID;
			$page_meta = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$template  = isset( $page_meta['_wp_page_template'] ) && '' !== $page_meta['_wp_page_template'] ? $page_meta['_wp_page_template'] : 'default';
			$modified  = (string) $row->post_modified_gmt;
			$url       = get_permalink( self::bare_post( $row ) );

			$pages[] = array(
				'id'            => $id,
				'title'         => self::plain( $row->post_title ),
				'slug'          => (string) $row->post_name,
				'parent'        => (int) $row->post_parent,
				'menu_order'    => (int) $row->menu_order,
				'template'      => $template,
				'url'           => $url ? (string) $url : null,
				'modified_gmt'  => '' !== $modified && 0 !== strpos( $modified, '0000' ) ? str_replace( ' ', 'T', $modified ) . 'Z' : null,
				'is_front_page' => $front > 0 && $id === $front,
				'is_posts_page' => $blog > 0 && $id === $blog,
				'builder'       => self::builder( $page_meta, (bool) $row->has_wpbakery, (bool) $row->has_blocks ),
			);
		}

		return $pages;
	}

	/**
	 * A post object carrying only what get_permalink() needs, and no content.
	 *
	 * @param object $row Row from the query in pages().
	 * @return WP_Post
	 */
	private static function bare_post( $row ) {
		return new WP_Post(
			(object) array(
				'ID'          => (int) $row->ID,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => (string) $row->post_title,
				'post_name'   => (string) $row->post_name,
				'post_parent' => (int) $row->post_parent,
				'filter'      => 'raw', // Already "sanitized": get_post() hands the object back untouched.
			)
		);
	}

	/**
	 * Which builder a page was made with.
	 *
	 * @param array $meta         The page's builder meta values.
	 * @param bool  $has_wpbakery Whether the content holds a [vc_row shortcode.
	 * @param bool  $has_blocks   Whether the content holds block markup.
	 * @return string elementor, wpbakery, divi, beaver-builder, blocks or classic.
	 */
	private static function builder( array $meta, $has_wpbakery, $has_blocks ) {
		if ( isset( $meta['_elementor_edit_mode'] ) && 'builder' === $meta['_elementor_edit_mode'] ) {
			return 'elementor';
		}
		if ( $has_wpbakery ) {
			return 'wpbakery';
		}
		if ( isset( $meta['_et_pb_use_builder'] ) && 'on' === $meta['_et_pb_use_builder'] ) {
			return 'divi';
		}
		if ( ! empty( $meta['_fl_builder_enabled'] ) ) {
			return 'beaver-builder';
		}
		return $has_blocks ? 'blocks' : 'classic';
	}
}
