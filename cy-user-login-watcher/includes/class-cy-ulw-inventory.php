<?php
/**
 * Site inventory: a one-time list of what this site is made of, sent to the
 * Control Yours Admin Dashboard so the team can plan the site's move to
 * SiteForge without logging in to it.
 *
 * Nothing is sent unless an inventory was asked for, in one of three ways:
 *
 *  - the dashboard answers the daily snapshot with {"requests": ["inventory"]}
 *    (see CY_ULW_Reporter::handle_requests()),
 *  - an administrator presses "Send site inventory now" under
 *    Settings > CY Connector, or
 *  - staff working on the server ask from WP-CLI (request( 'cli' ), since 1.7.1).
 *
 * Both only queue the one-off cy_ulw_inventory_run cron event. The inventory
 * is built and sent there, never in a login, admin or front-end request.
 *
 * What is collected (inventory_version 1): site identity, theme, installed
 * plugins, public content types with counts, published pages (structure and
 * the builder each was made with), menus, a media count and size, and a few
 * yes/no flags derived from the active plugins. Since 1.7.0 also: the SEO
 * title, description and focus keyword of each page and post, the newest
 * published posts of every other public content type, redirect rules, and
 * forms (field labels and types, who is notified, where each is embedded).
 * What is never collected: users, form entries, orders, customers, option
 * values and post content. Page content is tested for builder markup inside
 * the database query. The one place content is read is to find which form a
 * page embeds: a page that holds a form shortcode, block or Elementor form
 * widget is read to pick out the form's ID, and nothing else of it is kept.
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
	 * Page meta read for each batch of pages: the template, and the keys that
	 * tell which builder a page was made with. Plain names only; they go into
	 * the query as they are.
	 */
	const PAGE_META_KEYS = array(
		'_wp_page_template',
		'_elementor_edit_mode',       // Elementor: "builder".
		'_et_pb_use_builder',         // Divi: "on".
		'_fl_builder_enabled',        // Beaver Builder.
		'fusion_builder_status',      // Avada: "active".
		'ct_builder_json',            // Oxygen 4.
		'_ct_builder_json',           // The same, on a site where Oxygen hides its meta keys.
		'ct_builder_shortcodes',      // Oxygen before 4.
		'_ct_builder_shortcodes',
		'tcb_editor_enabled',         // Thrive Architect.
		'panels_data',                // SiteOrigin Page Builder.
		'page-option-item-xml',       // GoodLayers themes with a built-in builder.
		'gdlr-core-page-builder',     // GoodLayers Core.
	);

	/**
	 * Characters read from the start of each of those values (the template is
	 * read whole).
	 */
	const META_PREFIX = 191;

	/**
	 * Most posts listed for one content type other than pages: the newest
	 * ones. The type's full count is in post_types.
	 */
	const POSTS_PER_TYPE = 500;

	/**
	 * Post meta holding the SEO title, description and focus keyword, by the
	 * SEO plugin that wrote it. Plain names only; they go into the query as
	 * they are.
	 */
	const SEO_META_KEYS = array(
		'yoast'     => array(
			'title'         => '_yoast_wpseo_title',
			'description'   => '_yoast_wpseo_metadesc',
			'focus_keyword' => '_yoast_wpseo_focuskw',
		),
		'rank-math' => array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
		),
		'seopress'  => array(
			'title'         => '_seopress_titles_title',
			'description'   => '_seopress_titles_desc',
			'focus_keyword' => '_seopress_analysis_target_kw',
		),
	);

	/**
	 * Active plugin folders behind each SEO plugin name, in the order they are tried.
	 */
	const SEO_PLUGINS = array(
		'yoast'     => array( 'wordpress-seo', 'wordpress-seo-premium' ),
		'rank-math' => array( 'seo-by-rank-math', 'seo-by-rank-math-pro' ),
		'seopress'  => array( 'wp-seopress', 'wp-seopress-pro' ),
	);

	/**
	 * Characters kept of an SEO value. Longer than any search engine shows.
	 */
	const SEO_MAX_LENGTH = 600;

	/**
	 * Where redirect rules are read from, in this order.
	 */
	const REDIRECT_ORIGINS = array( 'redirection', 'yoast-premium', 'rank-math', 'seopress' );

	/**
	 * Redirect rows read per query.
	 */
	const REDIRECT_BATCH = 500;

	/**
	 * Most redirect rules listed in one inventory.
	 */
	const REDIRECT_CAP = 5000;

	/**
	 * Form plugins read, in this order.
	 */
	const FORM_ORIGINS = array( 'gravityforms', 'formidable', 'wpforms', 'contact-form-7', 'elementor' );

	/**
	 * Most forms listed for one form plugin, most fields listed for one form,
	 * and most pages named as embedding one form.
	 */
	const FORMS_PER_PLUGIN = 200;
	const FIELDS_PER_FORM  = 200;
	const EMBEDS_PER_FORM  = 100;

	/**
	 * Most pages looked at when finding where forms are embedded, and the
	 * largest page content or Elementor layout that is read for it, in bytes.
	 */
	const EMBED_SCAN_POSTS = 2000;
	const EMBED_MAX_BYTES  = 4000000;

	/**
	 * Seconds one such look through pages may take. Past it the look stops
	 * and the inventory says so, instead of running into PHP's time limit.
	 */
	const EMBED_SECONDS = 8;

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
	 * Seconds after which a running inventory that shows no sign of life
	 * counts as dead, so a new request may replace it.
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

	/**
	 * True while run() is at work, so on_shutdown() can tell a run that was
	 * cut short from one that ended by itself.
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * Whether on_shutdown() has been registered in this request.
	 *
	 * @var bool
	 */
	private static $guarded = false;

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
			'requested_by' => '',  // 'dashboard', 'button' or 'cli'.
			'inventory_id' => '',  // UUID shared by every chunk of one inventory.
			'phase'        => '',  // What is sent next: site, menus, pages, posts, redirects, forms, final.
			'chunk'        => 0,   // Chunks the dashboard has accepted so far.
			'after_id'     => 0,   // Pages up to this ID have been sent.
			'pages'        => 0,   // Pages sent so far.
			'type_index'   => 0,   // Posts: which content type is being listed (see listed_types()).
			'before_id'    => 0,   // Posts of that type down to this ID have been sent; 0 = none yet.
			'type_sent'    => 0,   // Posts of that type sent so far.
			'posts'        => 0,   // Posts sent so far, all types.
			'origin_index' => 0,   // Redirects, then forms: which source is being read.
			'origin_after' => 0,   // Redirects: rows of that source up to this ID have been read.
			'origin_stats' => array(), // Redirects: rules listed and left out for the source being read.
			'sources'      => array(), // Redirects: one summary per source already read.
			'redirects'    => 0,   // Redirect rules sent so far.
			'forms'        => 0,   // Forms sent so far.
			'notes'        => array(), // Parts that could not be read, in plain words. Sent with the last chunk.
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
	 * Whether an inventory is really waiting or under way. A queued or paused
	 * one is alive for as long as its cron event exists, however long cron
	 * takes to come round; without the event (the plugin was switched off and
	 * on again) it will never run. A running one is alive while its heartbeat
	 * is recent.
	 *
	 * @param array $state State array.
	 * @return bool
	 */
	private static function is_active( array $state ) {
		switch ( $state['status'] ) {
			case 'queued':
			case 'paused':
				return (bool) wp_next_scheduled( CY_ULW_INVENTORY_HOOK );
			case 'running':
				return (int) $state['heartbeat'] > time() - self::STALE_AFTER;
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Asking for an inventory
	 * ------------------------------------------------------------------ */

	/**
	 * Queue an inventory: remember who asked and schedule the one-off cron
	 * event. Does nothing when reporting is off or this is a staging or local
	 * copy, and nothing new when one is already queued or running.
	 *
	 * @param string $source 'dashboard' (asked for in a snapshot response), 'button' (the
	 *                       settings screen) or 'cli' (asked for from WP-CLI, by a person or a
	 *                       script working on the server). Anything else counts as 'dashboard'.
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
					'requested_by' => self::source( $source ),
				)
			);
			self::save();
			$state = self::$state;
		}

		// A run in progress needs no event. Anything else here is queued, new
		// or old, and schedule() leaves an event that already exists alone.
		if ( 'running' !== $state['status'] ) {
			self::schedule();
		}

		return true;
	}

	/**
	 * Who asked, as it is recorded and sent: 'button', 'cli' or 'dashboard'.
	 * 'cli' is only believed inside a WP-CLI run, so nothing on the web side
	 * can label a request as coming from the command line.
	 *
	 * @param string $source What the caller said.
	 * @return string
	 */
	private static function source( $source ) {
		if ( 'button' === $source ) {
			return 'button';
		}
		if ( 'cli' === $source && defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		return 'dashboard';
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

		// try/catch cannot see a memory or time limit; a shutdown function can.
		self::$running = true;
		if ( ! self::$guarded ) {
			self::$guarded = true;
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		}

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
		} finally {
			self::$running = false;
		}
	}

	/**
	 * Record a run that PHP ended from outside (memory limit, time limit, a
	 * fatal error in other code), so the settings screen says what happened
	 * and the inventory can be asked for again at once.
	 */
	public static function on_shutdown() {
		if ( ! self::$running ) {
			return;
		}
		self::$running = false;

		try {
			$error   = error_get_last();
			$message = 'The background run was stopped by PHP before it finished';
			if ( is_array( $error ) && ! empty( $error['message'] ) ) {
				$message .= ': ' . self::short( $error['message'] );
			}
			if ( (int) self::$state['chunk'] > 0 ) {
				$message .= '. The dashboard holds an incomplete inventory';
			}
			self::finish( 'failed', $message . '.' );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Nothing more can be done this late.
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
				if ( $pages || 0 === (int) self::$state['after_id'] ) {
					self::send_list( 'pages', $pages );
				}
				if ( $pages ) {
					self::$state['after_id'] = (int) $pages[ count( $pages ) - 1 ]['id'];
					self::$state['pages']    = (int) self::$state['pages'] + count( $pages );
				}
				if ( count( $pages ) < self::PAGE_BATCH ) {
					self::$state['phase'] = 'posts';
				}
				return false;

			case 'posts':
				self::step_posts();
				return false;

			case 'redirects':
				self::step_redirects();
				return false;

			case 'forms':
				self::step_forms();
				return false;

			case 'final':
				$notes = array_values( array_filter( array_map( 'strval', (array) self::$state['notes'] ) ) );
				self::send(
					$notes ? array( 'notes' => $notes ) : array(),
					array(
						'chunks'    => (int) self::$state['chunk'] + 1,
						'pages'     => (int) self::$state['pages'],
						'posts'     => (int) self::$state['posts'],
						'redirects' => (int) self::$state['redirects'],
						'forms'     => (int) self::$state['forms'],
					)
				);
				return true;
		}

		throw new RuntimeException( 'The saved inventory state is not usable; ask for the inventory again.' );
	}

	/**
	 * Posts phase: send the next batch of the content type being listed, or
	 * move on to the next type. Every public type other than pages and media
	 * is listed, newest first, up to POSTS_PER_TYPE each.
	 *
	 * @throws RuntimeException When a chunk cannot be sent or is not accepted.
	 */
	private static function step_posts() {
		$types = self::listed_types();
		$index = (int) self::$state['type_index'];

		if ( ! isset( $types[ $index ] ) ) {
			if ( 0 === (int) self::$state['posts'] ) {
				self::send_list( 'posts', array() ); // The section is always present.
			}
			self::$state['phase']        = 'redirects';
			self::$state['origin_index'] = 0;
			self::$state['origin_after'] = 0;
			self::$state['origin_stats'] = array();
			return;
		}

		$want  = min( self::PAGE_BATCH, self::POSTS_PER_TYPE - (int) self::$state['type_sent'] );
		$posts = $want > 0 ? self::posts( $types[ $index ], (int) self::$state['before_id'], $want ) : array();

		if ( $posts ) {
			self::send_list( 'posts', $posts );
			self::$state['before_id'] = (int) $posts[ count( $posts ) - 1 ]['id'];
			self::$state['type_sent'] = (int) self::$state['type_sent'] + count( $posts );
			self::$state['posts']     = (int) self::$state['posts'] + count( $posts );
		}

		if ( count( $posts ) < $want || (int) self::$state['type_sent'] >= self::POSTS_PER_TYPE ) {
			self::$state['type_index'] = $index + 1;
			self::$state['before_id']  = 0;
			self::$state['type_sent']  = 0;
		}
	}

	/**
	 * Redirects phase: read the next batch of rules from the source being
	 * read, or move on to the next source. Ends by sending one summary per
	 * source, so a site without redirects still says so.
	 *
	 * A source whose data cannot be read is skipped and named in the notes:
	 * another plugin's odd data must not cost the whole inventory.
	 *
	 * @throws RuntimeException When a chunk cannot be sent or is not accepted.
	 */
	private static function step_redirects() {
		$index = (int) self::$state['origin_index'];

		if ( ! isset( self::REDIRECT_ORIGINS[ $index ] ) ) {
			$data = array( 'redirect_sources' => array_values( (array) self::$state['sources'] ) );
			if ( 0 === (int) self::$state['redirects'] ) {
				$data['redirects'] = array(); // The section is always present.
			}
			self::send( $data );
			self::$state['phase']        = 'forms';
			self::$state['origin_index'] = 0;
			self::$state['origin_after'] = 0;
			return;
		}

		$origin = self::REDIRECT_ORIGINS[ $index ];
		$stats  = array_merge(
			array(
				'listed'  => 0,
				'skipped' => 0,
			),
			(array) self::$state['origin_stats']
		);
		$room   = max( 0, self::REDIRECT_CAP - (int) self::$state['redirects'] );
		$batch  = null;

		// Only the reading is guarded. A chunk the dashboard refuses still
		// ends the inventory, as everywhere else.
		try {
			$batch = self::redirect_batch( $origin, (int) self::$state['origin_after'] );
		} catch ( Throwable $e ) {
			self::note( 'Redirects from ' . $origin . ' could not be read: ' . $e->getMessage() );
		}

		if ( null === $batch ) {
			$in_use   = true;
			$complete = false;
		} else {
			$items             = array_slice( $batch['items'], 0, $room );
			$cut               = count( $items ) < count( $batch['items'] ); // The cap was reached.
			$stats['listed']  += count( $items );
			$stats['skipped'] += (int) $batch['skipped'];

			if ( $items ) {
				self::send_list( 'redirects', $items );
				self::$state['redirects'] = (int) self::$state['redirects'] + count( $items );
			}

			if ( ! $cut && ! $batch['done'] ) {
				self::$state['origin_after'] = (int) $batch['after'];
				self::$state['origin_stats'] = $stats;
				return;
			}

			$in_use   = (bool) $batch['in_use'];
			$complete = ! $cut;
		}

		// A source that is not in use on this site is left out of the summary.
		if ( $in_use ) {
			$sources                = (array) self::$state['sources'];
			$sources[]              = array(
				'origin'   => $origin,
				'listed'   => (int) $stats['listed'],
				'skipped'  => (int) $stats['skipped'],
				'complete' => $complete,
			);
			self::$state['sources'] = $sources;
		}

		self::$state['origin_index'] = $index + 1;
		self::$state['origin_after'] = 0;
		self::$state['origin_stats'] = array();
	}

	/**
	 * Forms phase: send the forms of the next form plugin. A plugin whose
	 * data cannot be read is skipped and named in the notes.
	 *
	 * @throws RuntimeException When a chunk cannot be sent or is not accepted.
	 */
	private static function step_forms() {
		$index = (int) self::$state['origin_index'];

		if ( ! isset( self::FORM_ORIGINS[ $index ] ) ) {
			if ( 0 === (int) self::$state['forms'] ) {
				self::send_list( 'forms', array() ); // The section is always present.
			}
			self::$state['phase'] = 'final';
			return;
		}

		$origin = self::FORM_ORIGINS[ $index ];
		$forms  = array();

		try {
			$forms = self::forms( $origin );
		} catch ( Throwable $e ) {
			self::note( 'Forms from ' . $origin . ' could not be read: ' . $e->getMessage() );
		}

		if ( $forms ) {
			self::send_list( 'forms', $forms );
			self::$state['forms'] = (int) self::$state['forms'] + count( $forms );
		}

		self::$state['origin_index'] = $index + 1;
	}

	/**
	 * Remember that a part of the inventory could not be read. The notes go
	 * out with the last chunk.
	 *
	 * @param string $message What happened, in plain words.
	 */
	private static function note( $message ) {
		$notes   = (array) self::$state['notes'];
		$message = self::short( $message, 200 );
		if ( count( $notes ) < 20 && ! in_array( $message, $notes, true ) ) {
			$notes[]              = $message;
			self::$state['notes'] = $notes;
		}
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
	 * Send one list section, over as many chunks as it takes to keep each
	 * under the size limit. An empty list is sent too, so the section is
	 * always present.
	 *
	 * @param string $section Key under "data", e.g. 'pages'.
	 * @param array  $items   List of entries.
	 * @throws RuntimeException When a chunk cannot be sent or is not accepted.
	 */
	private static function send_list( $section, array $items ) {
		$limit = self::MAX_CHUNK_BYTES - 2000; // Room for the envelope around the list.
		$group = array();
		$size  = 0;

		foreach ( $items as $item ) {
			$length = strlen( (string) wp_json_encode( $item ) ) + 1;

			if ( $group && $size + $length > $limit ) {
				self::send( array( $section => $group ) );
				$group = array();
				$size  = 0;
			}

			$group[] = $item;
			$size   += $length;
		}

		self::send( array( $section => $group ) );
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
			if ( in_array( $response->get_error_code(), array( 'cy_ulw_disabled', 'cy_ulw_url' ), true ) ) {
				// Nothing was sent; the reporter's message says why.
				throw new RuntimeException( $response->get_error_message() );
			}
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
		// Whole characters only: text cut mid-character cannot be saved in the option.
		$message = trim( wp_strip_all_tags( wp_check_invalid_utf8( (string) $message, true ) ) );
		if ( mb_strlen( $message ) > $length ) {
			$message = mb_substr( $message, 0, $length - 3 ) . '...';
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
		if ( 'button' === $state['requested_by'] ) {
			$by = __( 'with the button on this screen', 'cy-user-login-watcher' );
		} elseif ( 'cli' === $state['requested_by'] ) {
			$by = __( 'from the command line (WP-CLI)', 'cy-user-login-watcher' );
		} else {
			$by = __( 'by the Admin Dashboard', 'cy-user-login-watcher' );
		}

		switch ( $state['status'] ) {
			case 'queued':
				if ( ! self::is_active( $state ) ) {
					return sprintf(
						/* translators: %s: time difference, e.g. "3 hours". */
						__( 'An inventory was asked for %s ago but its background run is no longer scheduled, so it was not sent. It can be sent again.', 'cy-user-login-watcher' ),
						human_time_diff( min( $now, (int) $state['requested_at'] ), $now )
					);
				}
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
						human_time_diff( min( $now, max( (int) $state['heartbeat'], (int) $state['requested_at'] ) ), $now )
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
			'languages'           => self::site_languages(),
			'seo_plugin'          => self::seo_plugin(), // yoast, rank-math, seopress or null. Since 1.7.0.
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
	 * Also used by the daily snapshot (CY_ULW_Reporter::snapshot_plugins()).
	 *
	 * @return array { plugins: array[], mu_plugins: array[], active_slugs: string[] }
	 */
	public static function plugins() {
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
	 * Media library: how many items, and how many bytes their main files take.
	 * The main file is the one WordPress serves as "full size"; the smaller
	 * image sizes it generates are not counted. The size comes from the stored
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
				} else {
					$size = self::stored_filesize( (string) $row->meta_value );
					if ( $size > 0 ) {
						$sizes[ (int) $row->post_id ] = $size;
					}
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

			$more = count( $ids ) === self::MEDIA_BATCH;

			if ( $more && microtime( true ) - $started > self::MEDIA_SECONDS ) {
				$complete = false;
				break;
			}
		} while ( $more );

		return array(
			'count'          => $count,
			'bytes'          => $bytes,
			'files_measured' => $measured,
			'bytes_complete' => $complete,
		);
	}

	/**
	 * The main file's size as WordPress stored it with the attachment (since
	 * 6.0), or 0. Only the top-level value counts: the same key also appears
	 * under each generated size, and for a PDF those come first.
	 *
	 * @param string $value Raw _wp_attachment_metadata value.
	 * @return int Bytes.
	 */
	private static function stored_filesize( $value ) {
		if ( false === strpos( $value, '"filesize"' ) ) {
			return 0;
		}
		$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Core's own array, read without instantiating anything; a broken value is simply not counted.
		return is_array( $data ) && isset( $data['filesize'] ) && is_numeric( $data['filesize'] ) ? (int) $data['filesize'] : 0;
	}

	/**
	 * Every menu with its theme locations and items.
	 *
	 * @return array[]
	 */
	private static function menus() {
		global $wpdb;

		if ( ! self::multilingual() ) {
			$locations = array();
			foreach ( (array) get_nav_menu_locations() as $location => $menu_id ) {
				$locations[ (int) $menu_id ][] = (string) $location;
			}

			$menus = array();

			foreach ( (array) wp_get_nav_menus() as $menu ) {
				$menus[] = array(
					'id'        => (int) $menu->term_id,
					'name'      => self::plain( $menu->name ),
					'slug'      => (string) $menu->slug,
					'locations' => isset( $locations[ (int) $menu->term_id ] ) ? $locations[ (int) $menu->term_id ] : array(),
					'items'     => self::menu_items( (int) $menu->term_id ),
					'language'  => null,
				);
			}

			return $menus;
		}

		// With WPML, wp_get_nav_menus() hands back every menu as its
		// translation in the current language, so a Spanish menu would arrive
		// as a second copy of the English one. The menus are read straight
		// from the database, and each one's items are worked out in the
		// menu's own language.
		$terms = $wpdb->get_results(
			"SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'nav_menu' ORDER BY t.name ASC, t.term_id ASC"
		);

		$known = self::translations_of( 'tax_nav_menu', wp_list_pluck( (array) $terms, 'term_taxonomy_id' ) );

		// A theme location is saved against one menu; WPML shows that menu's
		// translation there in the other languages. So a location belongs to
		// every menu in the same translation group.
		$assigned = array();
		foreach ( (array) get_nav_menu_locations() as $location => $menu_id ) {
			$assigned[ (int) $menu_id ][] = (string) $location;
		}
		$shared = array();
		foreach ( (array) $terms as $term ) {
			$id = (int) $term->term_id;
			if ( isset( $assigned[ $id ], $known[ (int) $term->term_taxonomy_id ] ) ) {
				$group            = $known[ (int) $term->term_taxonomy_id ]['group'];
				$shared[ $group ] = array_merge( isset( $shared[ $group ] ) ? $shared[ $group ] : array(), $assigned[ $id ] );
			}
		}

		$languages = array();
		foreach ( (array) $terms as $index => $term ) {
			$languages[ $index ] = isset( $known[ (int) $term->term_taxonomy_id ] ) ? $known[ (int) $term->term_taxonomy_id ]['language'] : null;
		}

		return self::each_in_language(
			(array) $terms,
			$languages,
			function ( $term, $language ) use ( $known, $shared, $assigned ) {
				$id      = (int) $term->term_id;
				$details = isset( $known[ (int) $term->term_taxonomy_id ] ) ? $known[ (int) $term->term_taxonomy_id ] : null;

				if ( $details && isset( $shared[ $details['group'] ] ) ) {
					$locations = array_values( array_unique( $shared[ $details['group'] ] ) );
				} else {
					$locations = isset( $assigned[ $id ] ) ? $assigned[ $id ] : array();
				}

				return array(
					'id'        => $id,
					'name'      => self::plain( $term->name ),
					'slug'      => (string) $term->slug,
					'locations' => $locations,
					'items'     => self::menu_items( $id ),
					'language'  => $language,
				);
			}
		);
	}

	/**
	 * The items of one menu, in menu order.
	 *
	 * Read straight from the database instead of with wp_get_nav_menu_items(),
	 * which loads every page a menu links to together with all of its meta
	 * (megabytes of layout data on a page-builder site). An item follows the
	 * same rules as in WordPress: its own label wins, otherwise the title of
	 * what it points at; an item whose target no longer exists is left out.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return array[]
	 */
	private static function menu_items( $menu_id ) {
		global $wpdb;

		$ids = get_objects_in_term( $menu_id, 'nav_menu' );
		$ids = is_array( $ids ) ? array_filter( array_map( 'intval', $ids ) ) : array();
		if ( ! $ids ) {
			return array();
		}
		$in = implode( ',', $ids );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Every ID list below is made of integers.
		$rows = $wpdb->get_results(
			"SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($in) AND post_type = 'nav_menu_item' AND post_status = 'publish' ORDER BY menu_order ASC, ID ASC"
		);

		$meta      = array();
		$meta_rows = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ('_menu_item_type', '_menu_item_menu_item_parent', '_menu_item_object_id', '_menu_item_object', '_menu_item_url')"
		);
		foreach ( (array) $meta_rows as $row ) {
			$meta[ (int) $row->post_id ][ (string) $row->meta_key ] = (string) $row->meta_value;
		}

		// The posts and pages the items point at: a few small columns, no content.
		$targets    = array();
		$target_ids = array();
		foreach ( $meta as $item_meta ) {
			if ( isset( $item_meta['_menu_item_type'], $item_meta['_menu_item_object_id'] ) && 'post_type' === $item_meta['_menu_item_type'] && (int) $item_meta['_menu_item_object_id'] > 0 ) {
				$target_ids[] = (int) $item_meta['_menu_item_object_id'];
			}
		}
		if ( $target_ids ) {
			$target_rows = $wpdb->get_results(
				"SELECT ID, post_title, post_name, post_parent, post_type, post_status, post_date, post_date_gmt, post_author FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_unique( $target_ids ) ) . ')'
			);
			foreach ( (array) $target_rows as $row ) {
				$targets[ (int) $row->ID ] = $row;
			}
		}
		// phpcs:enable

		$items = array();

		foreach ( (array) $rows as $row ) {
			$id        = (int) $row->ID;
			$item_meta = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$type      = isset( $item_meta['_menu_item_type'] ) ? $item_meta['_menu_item_type'] : '';
			$object    = isset( $item_meta['_menu_item_object'] ) ? $item_meta['_menu_item_object'] : '';
			$object_id = isset( $item_meta['_menu_item_object_id'] ) ? (int) $item_meta['_menu_item_object_id'] : 0;
			$label     = (string) $row->post_title;
			$url       = isset( $item_meta['_menu_item_url'] ) && '' !== $item_meta['_menu_item_url'] ? $item_meta['_menu_item_url'] : null;

			if ( 'post_type' === $type ) {
				if ( ! isset( $targets[ $object_id ] ) || 'trash' === $targets[ $object_id ]->post_status ) {
					continue;
				}
				$label = '' !== $label ? $label : (string) $targets[ $object_id ]->post_title;
				$url   = get_permalink( self::bare_post( $targets[ $object_id ] ) );
			} elseif ( 'taxonomy' === $type ) {
				$term = get_term( $object_id, $object );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$label = '' !== $label ? $label : (string) $term->name;
				$url   = get_term_link( $term );
			} elseif ( 'post_type_archive' === $type ) {
				$post_type = get_post_type_object( $object );
				if ( ! $post_type ) {
					continue;
				}
				$label = '' !== $label ? $label : (string) $post_type->labels->archives;
				$url   = get_post_type_archive_link( $object );
			}

			$items[] = array(
				'id'        => $id,
				'label'     => self::plain( $label ),
				'url'       => is_string( $url ) && '' !== $url ? $url : null,
				'type'      => (string) $type,   // post_type, taxonomy, post_type_archive or custom.
				'object'    => (string) $object, // e.g. page, category, custom.
				'object_id' => $object_id,       // The page or term the item points at; the item's own ID for a custom link.
				'parent'    => isset( $item_meta['_menu_item_menu_item_parent'] ) ? (int) $item_meta['_menu_item_menu_item_parent'] : 0,
			);
		}

		return $items;
	}

	/**
	 * The next batch of published pages after a given ID.
	 *
	 * Content never leaves the database: the query only reports whether it
	 * holds a builder's or the block editor's markup. Only the start of the
	 * few meta values needed is read, not the page's whole meta (a builder
	 * page keeps megabytes of layout data there). The address comes from
	 * get_permalink(), given a post object built from the row (see
	 * bare_post()) so that WordPress does not load and cache every page,
	 * content included, to work it out. Parent
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
					( post_content LIKE %s ) AS has_blocks,
					( post_content LIKE %s ) AS has_avada,
					( post_content LIKE %s ) AS has_kingcomposer,
					( post_content LIKE %s ) AS has_bold
				FROM {$wpdb->posts}
				WHERE post_type = 'page' AND post_status = 'publish' AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				'%' . $wpdb->esc_like( '[vc_row' ) . '%',
				'%' . $wpdb->esc_like( '<!-- wp:' ) . '%',
				'%' . $wpdb->esc_like( '[fusion_builder_container' ) . '%',
				'%' . $wpdb->esc_like( '[kc_row' ) . '%',
				'%' . $wpdb->esc_like( '[bt_bb_section' ) . '%',
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

		// Only the start of each builder value is read: some of these keys hold
		// a whole page layout, and its first characters are enough to tell an
		// empty layout from a real one. Where a key has more than one row, the
		// oldest counts, as it does for get_post_meta().
		$meta      = array();
		$meta_rows = $wpdb->get_results(
			"SELECT post_id, meta_key, IF( meta_key = '_wp_page_template', meta_value, LEFT( meta_value, " . self::META_PREFIX . " ) ) AS meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ") AND meta_key IN ('" . implode( "', '", self::PAGE_META_KEYS ) . "') ORDER BY meta_id ASC" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IDs are integers from the query above; the keys and the length are constants of this class.
		);
		foreach ( (array) $meta_rows as $row ) {
			if ( ! isset( $meta[ (int) $row->post_id ][ (string) $row->meta_key ] ) ) {
				$meta[ (int) $row->post_id ][ (string) $row->meta_key ] = (string) $row->meta_value;
			}
		}

		// On a WPML site each page is worked out in its own language: asked in
		// another one, WPML answers with the translation's address, and the
		// front page and posts page are different pages in each language.
		$known     = self::translations_of( 'post_page', $ids );
		$languages = array();
		foreach ( $rows as $index => $row ) {
			$languages[ $index ] = isset( $known[ (int) $row->ID ] ) ? $known[ (int) $row->ID ]['language'] : null;
		}
		$special = array();
		$seo     = self::seo_for( $ids );

		return self::each_in_language(
			$rows,
			$languages,
			function ( $row, $language, $answering ) use ( $meta, $seo, &$special ) {
				$id        = (int) $row->ID;
				$page_meta = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
				$template  = isset( $page_meta['_wp_page_template'] ) && '' !== $page_meta['_wp_page_template'] ? $page_meta['_wp_page_template'] : 'default';
				$modified  = (string) $row->post_modified_gmt;

				if ( ! isset( $special[ (string) $answering ] ) ) {
					$static                         = 'page' === get_option( 'show_on_front' );
					$special[ (string) $answering ] = array(
						$static ? (int) get_option( 'page_on_front' ) : 0,
						$static ? (int) get_option( 'page_for_posts' ) : 0,
					);
				}
				list( $front, $blog ) = $special[ (string) $answering ];

				$url = get_permalink( self::bare_post( $row ) );

				return array(
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
					'builder'       => self::builder(
						$page_meta,
						array(
							'wpbakery'     => (bool) $row->has_wpbakery,
							'blocks'       => (bool) $row->has_blocks,
							'avada'        => (bool) $row->has_avada,
							'kingcomposer' => (bool) $row->has_kingcomposer,
							'bold-builder' => (bool) $row->has_bold,
						)
					),
					'language'      => $language, // WPML language code; null on a site with one language.
					'seo'           => isset( $seo[ $id ] ) ? $seo[ $id ] : null, // Since 1.7.0.
				);
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Collectors added in 1.7.0: SEO fields, posts, redirects, forms
	 * ------------------------------------------------------------------ */

	/**
	 * Folders of the active plugins, read once per request.
	 *
	 * @return string[]
	 */
	private static function active_slugs() {
		static $slugs = null;
		if ( null === $slugs ) {
			$list  = self::plugins();
			$slugs = $list['active_slugs'];
		}
		return $slugs;
	}

	/**
	 * Whether any of these plugin folders is active.
	 *
	 * @param string[] $slugs Plugin folders.
	 * @return bool
	 */
	private static function has_plugin( array $slugs ) {
		return (bool) array_intersect( $slugs, self::active_slugs() );
	}

	/**
	 * Whether a database table exists.
	 *
	 * @param string $table Full table name, prefix included.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/**
	 * Rows of a query on another plugin's data, or an exception when the
	 * query failed: a table laid out differently by an old version must read
	 * as "could not be read", not as "there are none". The callers' steps
	 * turn the exception into a note.
	 *
	 * @param string $sql Query, already prepared.
	 * @return object[]
	 * @throws Exception When the database reports an error.
	 */
	private static function rows( $sql ) {
		global $wpdb;

		$show = $wpdb->hide_errors(); // The error is reported in the notes, not printed into the cron run.
		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by the caller.
		$fail = (string) $wpdb->last_error;
		if ( $show ) {
			$wpdb->show_errors();
		}

		if ( '' !== $fail ) {
			throw new Exception( 'the database answered "' . self::short( $fail, 120 ) . '"' );
		}
		return (array) $rows;
	}

	/**
	 * The SEO plugin whose fields are read: the first active one of Yoast,
	 * Rank Math and SEOPress. Fields left behind by one that was switched off
	 * are not read, since the site no longer shows them.
	 *
	 * @return string|null yoast, rank-math, seopress or null.
	 */
	private static function seo_plugin() {
		foreach ( self::SEO_PLUGINS as $name => $slugs ) {
			if ( self::has_plugin( $slugs ) ) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * The SEO title, description and focus keyword saved for each post, as
	 * the SEO plugin stores them: a title can hold the plugin's own
	 * placeholders (%%title%%, %sep%). Posts with none of the three are left
	 * out.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array[] { title: string|null, description: string|null, focus_keyword: string|null } by post ID.
	 */
	private static function seo_for( array $ids ) {
		global $wpdb;

		$plugin = self::seo_plugin();
		$ids    = array_filter( array_map( 'intval', $ids ) );
		if ( null === $plugin || ! $ids ) {
			return array();
		}

		$fields = array_flip( self::SEO_META_KEYS[ $plugin ] ); // Meta key => field.

		$rows = $wpdb->get_results(
			'SELECT post_id, meta_key, LEFT( meta_value, ' . ( self::SEO_MAX_LENGTH + 1 ) . " ) AS meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ") AND meta_key IN ('" . implode( "', '", array_keys( $fields ) ) . "') ORDER BY meta_id ASC" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The IDs are integers; the keys and the length are constants of this class.
		);

		$seo = array();
		foreach ( (array) $rows as $row ) {
			$id    = (int) $row->post_id;
			$field = $fields[ (string) $row->meta_key ];
			$value = self::clip( self::plain( $row->meta_value ), self::SEO_MAX_LENGTH );
			if ( '' === $value || isset( $seo[ $id ][ $field ] ) ) {
				continue; // Empty, or a second row for the same key: the oldest counts.
			}
			if ( ! isset( $seo[ $id ] ) ) {
				$seo[ $id ] = array(
					'title'         => null,
					'description'   => null,
					'focus_keyword' => null,
				);
			}
			$seo[ $id ][ $field ] = $value;
		}

		return $seo;
	}

	/**
	 * Text cut to a number of whole characters.
	 *
	 * @param string $text   Text.
	 * @param int    $length Most characters kept.
	 * @return string
	 */
	private static function clip( $text, $length ) {
		$text = (string) wp_check_invalid_utf8( (string) $text, true );
		return mb_strlen( $text ) > $length ? mb_substr( $text, 0, $length ) : $text;
	}

	/**
	 * The content types whose posts are listed: the public ones whose items
	 * have an address of their own on the site, all but pages (listed in full
	 * under "pages") and media. A type that is public in name only, with no
	 * addresses, may be a plugin's own records and is left alone. Sorted by
	 * name, so the order is the same in every cron run of one inventory.
	 *
	 * @return string[]
	 */
	private static function listed_types() {
		$types = array_values(
			array_diff(
				array_map(
					'strval',
					array_keys(
						get_post_types(
							array(
								'public'             => true,
								'publicly_queryable' => true,
							)
						)
					)
				),
				array( 'page', 'attachment' )
			)
		);
		sort( $types );
		return $types;
	}

	/**
	 * The next batch of published posts of one content type, newest first
	 * (highest ID first). No content, excerpt or author is read.
	 *
	 * @param string $type      Post type.
	 * @param int    $before_id Lowest post ID already sent; 0 to start at the newest.
	 * @param int    $limit     Most posts returned.
	 * @return array[]
	 */
	private static function posts( $type, $before_id, $limit ) {
		global $wpdb;

		// post_author and the dates are only there for get_permalink(): they
		// appear in the addresses of posts on some permalink settings.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_parent, post_type, post_status, post_date, post_date_gmt, post_modified_gmt, post_author
				FROM {$wpdb->posts}
				WHERE post_type = %s AND post_status = 'publish' AND ( %d = 0 OR ID < %d )
				ORDER BY ID DESC
				LIMIT %d",
				(string) $type,
				(int) $before_id,
				(int) $before_id,
				(int) $limit
			)
		);

		if ( ! $rows ) {
			return array();
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row->ID;
		}

		$seo       = self::seo_for( $ids );
		$known     = self::translations_of( 'post_' . $type, $ids );
		$languages = array();
		foreach ( $rows as $index => $row ) {
			$languages[ $index ] = isset( $known[ (int) $row->ID ] ) ? $known[ (int) $row->ID ]['language'] : null;
		}

		return self::each_in_language(
			$rows,
			$languages,
			function ( $row, $language ) use ( $seo ) {
				$id  = (int) $row->ID;
				$url = get_permalink( self::bare_post( $row ) );

				return array(
					'id'           => $id,
					'type'         => (string) $row->post_type,
					'title'        => self::plain( $row->post_title ),
					'slug'         => (string) $row->post_name,
					'parent'       => (int) $row->post_parent,
					'url'          => $url ? (string) $url : null,
					'date_gmt'     => self::gmt( $row->post_date_gmt ),
					'modified_gmt' => self::gmt( $row->post_modified_gmt ),
					'language'     => $language,
					'seo'          => isset( $seo[ $id ] ) ? $seo[ $id ] : null,
				);
			}
		);
	}

	/**
	 * A stored GMT date as ISO 8601, or null when the column is empty.
	 *
	 * @param string $date MySQL datetime.
	 * @return string|null
	 */
	private static function gmt( $date ) {
		$date = (string) $date;
		return '' !== $date && 0 !== strpos( $date, '0000' ) ? str_replace( ' ', 'T', $date ) . 'Z' : null;
	}

	/**
	 * One redirect rule in the shape that is sent.
	 *
	 * @param string      $origin  Where it was read from: one of REDIRECT_ORIGINS.
	 * @param string      $source  Address or pattern that is redirected.
	 * @param string      $match   exact, regex, contains, start or end.
	 * @param string|null $target  Where it goes; null for a rule that only answers with a status (410).
	 * @param int         $code    HTTP status.
	 * @param bool        $enabled Whether the rule is switched on.
	 * @return array
	 */
	private static function redirect( $origin, $source, $match, $target, $code, $enabled ) {
		$target = null === $target ? '' : trim( (string) $target );
		return array(
			'source'  => self::clip( trim( (string) $source ), 2000 ),
			'match'   => in_array( $match, array( 'exact', 'regex', 'contains', 'start', 'end' ), true ) ? $match : 'exact',
			'target'  => '' !== $target ? self::clip( $target, 2000 ) : null,
			'code'    => (int) $code,
			'enabled' => (bool) $enabled,
			'origin'  => (string) $origin,
		);
	}

	/**
	 * The next batch of redirect rules from one source.
	 *
	 * Sources: the Redirection plugin (its own table), Yoast SEO Premium (one
	 * option), Rank Math (its own table) and SEOPress (one post per rule).
	 * Each is read only while its plugin is active. Rules that do more than
	 * send one address to another (Redirection's rules by login state,
	 * referrer, browser and the like, or its "error" and "pass-through"
	 * actions) are counted as skipped, not sent.
	 *
	 * @param string $origin One of REDIRECT_ORIGINS.
	 * @param int    $after  Last row ID already read, for the sources read in batches.
	 * @return array { in_use: bool, items: array[], skipped: int, after: int, done: bool }
	 */
	private static function redirect_batch( $origin, $after ) {
		global $wpdb;

		$result = array(
			'in_use'  => false,
			'items'   => array(),
			'skipped' => 0,
			'after'   => (int) $after,
			'done'    => true,
		);

		switch ( $origin ) {
			case 'redirection':
				$table = $wpdb->prefix . 'redirection_items';
				if ( ! self::has_plugin( array( 'redirection' ) ) || ! self::table_exists( $table ) ) {
					return $result;
				}
				// Redirection applies a rule only when the rule and the group it
				// is filed under are both enabled.
				$groups = $wpdb->prefix . 'redirection_groups';
				$rows   = self::rows(
					self::table_exists( $groups )
						? $wpdb->prepare(
							"SELECT i.id, i.url, i.regex, i.status, i.action_type, i.action_code, i.action_data, i.match_type, g.status AS group_status FROM {$table} i LEFT JOIN {$groups} g ON g.id = i.group_id WHERE i.id > %d ORDER BY i.id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table names are the prefix plus fixed names.
							(int) $after,
							self::REDIRECT_BATCH
						)
						: $wpdb->prepare(
							"SELECT id, url, regex, status, action_type, action_code, action_data, match_type, NULL AS group_status FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is the prefix plus a fixed name.
							(int) $after,
							self::REDIRECT_BATCH
						)
				);
				$result['in_use'] = true;
				foreach ( (array) $rows as $row ) {
					$result['after'] = (int) $row->id;
					if ( 'url' !== $row->action_type || 'url' !== $row->match_type ) {
						$result['skipped']++;
						continue;
					}
					$result['items'][] = self::redirect( $origin, $row->url, (int) $row->regex ? 'regex' : 'exact', $row->action_data, $row->action_code, 'enabled' === $row->status && 'disabled' !== $row->group_status );
				}
				$result['done'] = count( (array) $rows ) < self::REDIRECT_BATCH;
				return $result;

			case 'yoast-premium':
				if ( ! self::has_plugin( array( 'wordpress-seo-premium' ) ) ) {
					return $result;
				}
				$result['in_use'] = true;
				if ( $after > 0 ) {
					return $result; // Read in one go; a second call has nothing left.
				}
				$rules = get_option( 'wpseo-premium-redirects-base' );
				foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
					if ( ! is_array( $rule ) || ! isset( $rule['origin'] ) || ! is_scalar( $rule['origin'] ) ) {
						$result['skipped']++;
						continue;
					}
					$result['items'][] = self::redirect(
						$origin,
						$rule['origin'],
						isset( $rule['format'] ) && 'regex' === $rule['format'] ? 'regex' : 'exact',
						isset( $rule['url'] ) && is_scalar( $rule['url'] ) ? $rule['url'] : null,
						isset( $rule['type'] ) ? (int) $rule['type'] : 301,
						true
					);
				}
				$result['after'] = 1;
				return $result;

			case 'rank-math':
				$table = $wpdb->prefix . 'rank_math_redirections';
				if ( ! self::has_plugin( self::SEO_PLUGINS['rank-math'] ) || ! self::table_exists( $table ) ) {
					return $result;
				}
				$rows = self::rows(
					$wpdb->prepare(
						"SELECT id, sources, url_to, header_code, status FROM {$table} WHERE id > %d AND status <> 'trashed' ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is the prefix plus a fixed name.
						(int) $after,
						self::REDIRECT_BATCH
					)
				);
				$result['in_use'] = true;
				// Rank Math applies its rules only while its Redirections module is switched on.
				$module_on = in_array( 'redirections', (array) get_option( 'rank_math_modules', array() ), true );
				foreach ( (array) $rows as $row ) {
					$result['after'] = (int) $row->id;
					$sources         = @unserialize( (string) $row->sources, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Rank Math's own array, read without instantiating anything; a broken value is counted as skipped.
					$listed          = 0;
					foreach ( is_array( $sources ) ? $sources : array() as $source ) {
						if ( ! is_array( $source ) || ! isset( $source['pattern'] ) || ! is_scalar( $source['pattern'] ) || '' === trim( (string) $source['pattern'] ) ) {
							continue;
						}
						$result['items'][] = self::redirect( $origin, $source['pattern'], isset( $source['comparison'] ) ? (string) $source['comparison'] : 'exact', $row->url_to, $row->header_code, $module_on && 'active' === $row->status );
						$listed++;
					}
					if ( ! $listed ) {
						$result['skipped']++;
					}
				}
				$result['done'] = count( (array) $rows ) < self::REDIRECT_BATCH;
				return $result;

			case 'seopress':
				if ( ! self::has_plugin( self::SEO_PLUGINS['seopress'] ) ) {
					return $result;
				}
				// One post per rule, of the same type as SEOPress's log of
				// 404s; only the ones that carry a target are rules.
				$rows = self::rows(
					$wpdb->prepare(
						"SELECT p.ID, p.post_title, p.post_status, MIN( LEFT( m.meta_value, 2001 ) ) AS target
						FROM {$wpdb->posts} p
						INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_seopress_redirections_value' AND m.meta_value <> ''
						WHERE p.post_type = 'seopress_404' AND p.post_status IN ( 'publish', 'draft' ) AND p.ID > %d
						GROUP BY p.ID
						ORDER BY p.ID ASC
						LIMIT %d",
						(int) $after,
						self::REDIRECT_BATCH
					)
				);
				$result['in_use'] = (bool) $rows || $after > 0;
				$ids              = array();
				foreach ( (array) $rows as $row ) {
					$ids[] = (int) $row->ID;
				}
				$meta = array();
				if ( $ids ) {
					$meta_rows = self::rows(
						"SELECT post_id, meta_key, LEFT( meta_value, 20 ) AS meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ") AND meta_key IN ('_seopress_redirections_type', '_seopress_redirections_enabled', '_seopress_redirections_enabled_regex') ORDER BY meta_id ASC" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The IDs are integers from the query above.
					);
					foreach ( (array) $meta_rows as $meta_row ) {
						if ( ! isset( $meta[ (int) $meta_row->post_id ][ (string) $meta_row->meta_key ] ) ) {
							$meta[ (int) $meta_row->post_id ][ (string) $meta_row->meta_key ] = (string) $meta_row->meta_value;
						}
					}
				}
				foreach ( (array) $rows as $row ) {
					$id                = (int) $row->ID;
					$rule              = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
					$result['after']   = $id;
					$result['items'][] = self::redirect(
						$origin,
						$row->post_title,
						isset( $rule['_seopress_redirections_enabled_regex'] ) && 'yes' === $rule['_seopress_redirections_enabled_regex'] ? 'regex' : 'exact',
						$row->target,
						isset( $rule['_seopress_redirections_type'] ) ? (int) $rule['_seopress_redirections_type'] : 301,
						'publish' === $row->post_status && isset( $rule['_seopress_redirections_enabled'] ) && 'yes' === $rule['_seopress_redirections_enabled']
					);
				}
				$result['done'] = count( (array) $rows ) < self::REDIRECT_BATCH;
				return $result;
		}

		return $result;
	}

	/**
	 * The forms of one form plugin, or an empty list when it is not active.
	 *
	 * Each form: which plugin, its ID there, its title, whether it is switched
	 * on, its fields (label and type, never a value), its notification emails
	 * (who they go to) and the published posts that embed it. Entries are
	 * never read.
	 *
	 * @param string $origin One of FORM_ORIGINS.
	 * @return array[]
	 */
	private static function forms( $origin ) {
		switch ( $origin ) {
			case 'gravityforms':
				return self::has_plugin( array( 'gravityforms' ) ) ? self::forms_gravity() : array();
			case 'formidable':
				return self::has_plugin( array( 'formidable', 'formidable-pro' ) ) ? self::forms_formidable() : array();
			case 'wpforms':
				return self::has_plugin( array( 'wpforms-lite', 'wpforms' ) ) ? self::forms_wpforms() : array();
			case 'contact-form-7':
				return self::has_plugin( array( 'contact-form-7' ) ) ? self::forms_cf7() : array();
			case 'elementor':
				return self::has_plugin( array( 'elementor-pro', 'pro-elements' ) ) ? self::forms_elementor() : array();
		}
		return array();
	}

	/**
	 * One form in the shape that is sent.
	 *
	 * @param string  $plugin        One of FORM_ORIGINS.
	 * @param string  $id            The form's ID in its plugin.
	 * @param string  $title         Form title.
	 * @param bool    $active        Whether the form is switched on.
	 * @param array[] $fields        From field(), or null when they are not stored.
	 * @param array[] $notifications From notification().
	 * @param array[] $embedded_on   { id, type } of the posts that embed it.
	 * @return array
	 */
	private static function form( $plugin, $id, $title, $active, $fields, array $notifications, array $embedded_on ) {
		return array(
			'plugin'        => (string) $plugin,
			'id'            => (string) $id,
			'title'         => self::clip( self::plain( $title ), 200 ),
			'active'        => (bool) $active,
			'fields'        => is_array( $fields ) ? array_slice( $fields, 0, self::FIELDS_PER_FORM ) : null,
			'notifications' => array_slice( $notifications, 0, 20 ),
			'embedded_on'   => array_slice( $embedded_on, 0, self::EMBEDS_PER_FORM ),
		);
	}

	/**
	 * One form field in the shape that is sent.
	 *
	 * @param string $label    What the visitor sees next to the field (or the field's name where there is no label).
	 * @param string $type     The plugin's own name for the kind of field.
	 * @param bool   $required Whether it must be filled in.
	 * @return array
	 */
	private static function field( $label, $type, $required ) {
		return array(
			'label'    => self::clip( self::plain( is_scalar( $label ) ? $label : '' ), 200 ),
			'type'     => self::clip( is_scalar( $type ) ? (string) $type : '', 60 ),
			'required' => (bool) $required,
		);
	}

	/**
	 * One notification email in the shape that is sent.
	 *
	 * @param string $name   The notification's name in the form plugin.
	 * @param bool   $active Whether it is switched on.
	 * @param mixed  $to     Recipients as the plugin stores them: a string or a list of strings.
	 * @return array
	 */
	private static function notification( $name, $active, $to ) {
		return array(
			'name'   => self::clip( self::plain( is_scalar( $name ) ? $name : '' ), 200 ),
			'active' => (bool) $active,
			'to'     => self::recipients( $to ),
		);
	}

	/**
	 * Recipients as a list. The form plugins' placeholders for the site's
	 * admin email are replaced by that address; any other placeholder (the
	 * visitor's own email field, usually) is left as written, so the reader
	 * can tell it from a fixed address.
	 *
	 * @param mixed $raw A string with one or more recipients, or a list of such strings.
	 * @return string[]
	 */
	private static function recipients( $raw ) {
		$parts = array();
		foreach ( (array) $raw as $value ) {
			if ( is_scalar( $value ) ) {
				$parts = array_merge( $parts, (array) preg_split( '/[,;\r\n]+/', (string) $value ) );
			}
		}

		$admin = (string) get_option( 'admin_email' );
		$list  = array();
		foreach ( $parts as $part ) {
			$part = trim( str_ireplace( array( '{admin_email}', '[admin_email]', '[_site_admin_email]' ), $admin, (string) $part ) );
			// "Front Desk <office@example.com>": keep the address, which
			// would otherwise be removed as if it were an HTML tag.
			if ( preg_match( '/<\s*([^<>\s]+@[^<>\s]+)\s*>/', $part, $address ) ) {
				$part = $address[1];
			}
			if ( '' !== $part ) {
				$list[] = self::clip( self::plain( $part ), 200 );
			}
		}

		return array_slice( array_values( array_unique( $list ) ), 0, 20 );
	}

	/**
	 * Stored JSON, or a serialized array from an older version of a form
	 * plugin, as an array. Anything else is an empty array.
	 *
	 * @param mixed $value Raw column or post content.
	 * @return array
	 */
	private static function decode( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$data = json_decode( $value, true );
		if ( ! is_array( $data ) ) {
			$data = json_decode( wp_unslash( $value ), true );
		}
		if ( ! is_array( $data ) && is_serialized( $value ) ) {
			$data = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- A form plugin's own array, read without instantiating anything; a broken value is simply empty.
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Gravity Forms: forms and their settings are in its own two tables.
	 *
	 * @return array[]
	 */
	private static function forms_gravity() {
		global $wpdb;

		$forms_table = $wpdb->prefix . 'gf_form';
		$meta_table  = $wpdb->prefix . 'gf_form_meta';
		if ( ! self::table_exists( $forms_table ) || ! self::table_exists( $meta_table ) ) {
			return array();
		}

		$rows = self::rows(
			$wpdb->prepare(
				"SELECT id, title, is_active FROM {$forms_table} WHERE is_trash = 0 ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is the prefix plus a fixed name.
				self::FORMS_PER_PLUGIN
			)
		);
		if ( ! $rows ) {
			return array();
		}

		$embeds = self::embeds(
			array( '[gravityform', 'wp:gravityforms/form' ),
			array(
				'/\[gravityforms?\b[^\]]*?\bid\s*=\s*["\']?(\d+)/i',
				'/wp:gravityforms\/form\s*\{[^}]*?"formId"\s*:\s*"?(\d+)/',
			)
		);

		$forms = array();

		foreach ( $rows as $row ) {
			$id   = (int) $row->id;
			$meta = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT display_meta, notifications FROM {$meta_table} WHERE form_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is the prefix plus a fixed name.
					$id
				)
			);

			$display = $meta ? self::decode( $meta->display_meta ) : array();
			$fields  = array();
			foreach ( isset( $display['fields'] ) && is_array( $display['fields'] ) ? $display['fields'] : array() as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$label    = isset( $field['label'] ) && '' !== $field['label'] ? $field['label'] : ( isset( $field['adminLabel'] ) ? $field['adminLabel'] : '' );
				$fields[] = self::field( $label, isset( $field['type'] ) ? $field['type'] : '', ! empty( $field['isRequired'] ) );
			}

			$notifications = array();
			foreach ( $meta ? self::decode( $meta->notifications ) : array() as $notification ) {
				if ( ! is_array( $notification ) ) {
					continue;
				}
				$kind = isset( $notification['toType'] ) ? (string) $notification['toType'] : 'email';
				if ( 'routing' === $kind ) {
					// Sent to one of several addresses, chosen by an answer. Only the addresses are read.
					$to = array();
					foreach ( isset( $notification['routing'] ) && is_array( $notification['routing'] ) ? $notification['routing'] : array() as $route ) {
						if ( is_array( $route ) && isset( $route['email'] ) ) {
							$to[] = $route['email'];
						}
					}
				} elseif ( 'field' === $kind ) {
					// Sent to the address the visitor types into one of the form's fields.
					$to = '{field:' . ( isset( $notification['toField'] ) && is_scalar( $notification['toField'] ) ? (string) $notification['toField'] : '' ) . '}';
				} else {
					$to = isset( $notification['to'] ) && '' !== $notification['to'] ? $notification['to'] : ( isset( $notification['toEmail'] ) ? $notification['toEmail'] : '' );
				}
				$notifications[] = self::notification(
					isset( $notification['name'] ) ? $notification['name'] : '',
					! isset( $notification['isActive'] ) || $notification['isActive'],
					$to
				);
			}

			$forms[] = self::form( 'gravityforms', $id, $row->title, (int) $row->is_active, $fields, $notifications, isset( $embeds[ (string) $id ] ) ? $embeds[ (string) $id ] : array() );
		}

		return $forms;
	}

	/**
	 * Formidable Forms: forms and fields are in its own tables, and each
	 * email it sends is a post of type frm_form_actions.
	 *
	 * @return array[]
	 */
	private static function forms_formidable() {
		global $wpdb;

		$forms_table  = $wpdb->prefix . 'frm_forms';
		$fields_table = $wpdb->prefix . 'frm_fields';
		if ( ! self::table_exists( $forms_table ) || ! self::table_exists( $fields_table ) ) {
			return array();
		}

		// Templates are not forms on the site, and a form with a parent is a
		// repeating section of that parent.
		$rows = self::rows(
			$wpdb->prepare(
				"SELECT id, form_key, name, status FROM {$forms_table} WHERE is_template = 0 AND ( parent_form_id IS NULL OR parent_form_id = 0 ) AND status IN ( 'published', 'draft' ) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is the prefix plus a fixed name.
				self::FORMS_PER_PLUGIN
			)
		);
		if ( ! $rows ) {
			return array();
		}

		$ids  = array();
		$keys = array(); // A shortcode can name a form by its key instead of its ID.
		foreach ( $rows as $row ) {
			$ids[]                                         = (int) $row->id;
			$keys[ strtolower( (string) $row->form_key ) ] = (string) (int) $row->id;
		}
		$in = implode( ',', $ids );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is the prefix plus a fixed name; the IDs are integers.
		$fields = array();
		foreach ( (array) self::rows( "SELECT form_id, name, type, required FROM {$fields_table} WHERE form_id IN ($in) ORDER BY form_id ASC, field_order ASC, id ASC" ) as $field ) {
			$fields[ (int) $field->form_id ][] = self::field( $field->name, $field->type, (int) $field->required );
		}

		$notifications = array();
		foreach ( (array) self::rows( "SELECT post_title, post_status, menu_order, post_content FROM {$wpdb->posts} WHERE post_type = 'frm_form_actions' AND post_excerpt = 'email' AND post_status IN ( 'publish', 'draft' ) AND menu_order IN ($in) ORDER BY ID ASC" ) as $action ) {
			$settings                                     = self::decode( $action->post_content );
			$notifications[ (int) $action->menu_order ][] = self::notification( $action->post_title, 'publish' === $action->post_status, isset( $settings['email_to'] ) ? $settings['email_to'] : '' );
		}
		// phpcs:enable

		$embeds = self::embeds(
			array( '[formidable', 'wp:formidable/simple-form' ),
			array(
				'/\[formidable\b[^\]]*?\bid\s*=\s*["\']?([A-Za-z0-9_-]+)/i',
				'/wp:formidable\/simple-form\s*\{[^}]*?"formId"\s*:\s*"?(\d+)/',
			),
			$keys
		);

		$forms = array();
		foreach ( $rows as $row ) {
			$id      = (int) $row->id;
			$forms[] = self::form(
				'formidable',
				$id,
				$row->name,
				'published' === $row->status,
				isset( $fields[ $id ] ) ? $fields[ $id ] : array(),
				isset( $notifications[ $id ] ) ? $notifications[ $id ] : array(),
				isset( $embeds[ (string) $id ] ) ? $embeds[ (string) $id ] : array()
			);
		}

		return $forms;
	}

	/**
	 * WPForms: each form is a post of type wpforms whose content is the
	 * form's settings as JSON.
	 *
	 * @return array[]
	 */
	private static function forms_wpforms() {
		global $wpdb;

		$rows = self::rows(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_type = 'wpforms' AND post_status = 'publish' ORDER BY ID ASC LIMIT %d",
				self::FORMS_PER_PLUGIN
			)
		);
		if ( ! $rows ) {
			return array();
		}

		$embeds = self::embeds(
			array( '[wpforms', 'wp:wpforms/form-selector' ),
			array(
				'/\[wpforms\b[^\]]*?\bid\s*=\s*["\']?(\d+)/i',
				'/wp:wpforms\/form-selector\s*\{[^}]*?"formId"\s*:\s*"?(\d+)/',
			)
		);

		$forms = array();

		foreach ( $rows as $row ) {
			$id       = (int) $row->ID;
			$data     = self::decode( $row->post_content );
			$settings = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array();

			$fields = array();
			foreach ( isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array() as $field ) {
				if ( is_array( $field ) ) {
					$fields[] = self::field( isset( $field['label'] ) ? $field['label'] : '', isset( $field['type'] ) ? $field['type'] : '', ! empty( $field['required'] ) );
				}
			}

			// One switch for the whole form, and one per notification that counts as on unless it says 0.
			$enabled       = ! empty( $settings['notification_enable'] );
			$notifications = array();
			foreach ( isset( $settings['notifications'] ) && is_array( $settings['notifications'] ) ? $settings['notifications'] : array() as $notification ) {
				if ( is_array( $notification ) ) {
					$notifications[] = self::notification(
						isset( $notification['notification_name'] ) ? $notification['notification_name'] : '',
						$enabled && ( ! isset( $notification['enable'] ) || $notification['enable'] ),
						isset( $notification['email'] ) ? $notification['email'] : ''
					);
				}
			}
			if ( ! $notifications && ! empty( $settings['notification_email'] ) ) {
				$notifications[] = self::notification( '', $enabled, $settings['notification_email'] ); // The single address older forms keep, which WPForms itself still falls back to.
			}

			$title   = isset( $settings['form_title'] ) && is_scalar( $settings['form_title'] ) && '' !== (string) $settings['form_title'] ? $settings['form_title'] : $row->post_title;
			$forms[] = self::form( 'wpforms', $id, $title, true, $fields, $notifications, isset( $embeds[ (string) $id ] ) ? $embeds[ (string) $id ] : array() );
		}

		return $forms;
	}

	/**
	 * Contact Form 7: each form is a post of type wpcf7_contact_form, with
	 * its template and its two emails in post meta. A field has no label of
	 * its own, so its name stands in.
	 *
	 * @return array[]
	 */
	private static function forms_cf7() {
		global $wpdb;

		$rows = self::rows(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'wpcf7_contact_form' AND post_status = 'publish' ORDER BY ID ASC LIMIT %d",
				self::FORMS_PER_PLUGIN
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
		$meta_rows = self::rows(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ") AND meta_key IN ('_form', '_mail', '_mail_2', '_hash') ORDER BY meta_id ASC" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The IDs are integers from the query above.
		);
		foreach ( (array) $meta_rows as $meta_row ) {
			if ( ! isset( $meta[ (int) $meta_row->post_id ][ (string) $meta_row->meta_key ] ) ) {
				$meta[ (int) $meta_row->post_id ][ (string) $meta_row->meta_key ] = (string) $meta_row->meta_value;
			}
		}

		// Since Contact Form 7 5.8 a shortcode names its form by the start of
		// the form's hash; older ones use the post ID.
		$hashes = array();
		foreach ( $ids as $id ) {
			if ( isset( $meta[ $id ]['_hash'] ) && '' !== $meta[ $id ]['_hash'] ) {
				$hashes[ strtolower( $meta[ $id ]['_hash'] ) ] = (string) $id;
			}
		}

		$found = self::embeds(
			array( '[contact-form-7', 'wp:contact-form-7/contact-form-selector' ),
			array(
				'/\[contact-form-7\b[^\]]*?\bid\s*=\s*["\']?([0-9a-f]+)/i',
				'/wp:contact-form-7\/contact-form-selector\s*\{[^}]*?"id"\s*:\s*"?(\d+)/',
			)
		);

		$embeds = array();
		foreach ( $found as $ref => $posts ) {
			$ref    = strtolower( (string) $ref );
			$target = null;
			if ( ctype_digit( $ref ) && in_array( (int) $ref, $ids, true ) ) {
				$target = (string) (int) $ref;
			} elseif ( strlen( $ref ) >= 7 ) {
				foreach ( $hashes as $hash => $form_id ) {
					if ( 0 === strpos( $hash, $ref ) ) {
						$target = $form_id;
						break;
					}
				}
			}
			if ( null !== $target ) {
				$embeds[ $target ] = self::merge_embeds( isset( $embeds[ $target ] ) ? $embeds[ $target ] : array(), $posts );
			}
		}

		$forms = array();

		foreach ( $rows as $row ) {
			$id     = (int) $row->ID;
			$fields = array();

			// Form tags: [type name options...] with a * on the type when
			// required. A tag without a name (submit) is not a field.
			if ( isset( $meta[ $id ]['_form'] ) && preg_match_all( '/\[([a-zA-Z][0-9a-zA-Z:._-]*?)(\*?)\s+([a-zA-Z][0-9a-zA-Z:._-]*)(?=[\s\]])/', $meta[ $id ]['_form'], $tags, PREG_SET_ORDER ) ) {
				foreach ( $tags as $tag ) {
					if ( ! in_array( strtolower( $tag[1] ), array( 'submit', 'response' ), true ) ) {
						$fields[] = self::field( $tag[3], strtolower( $tag[1] ), '*' === $tag[2] );
					}
				}
			}

			$notifications = array();
			foreach ( array(
				'_mail'   => 'Mail',
				'_mail_2' => 'Mail (2)',
			) as $key => $name ) {
				$mail = isset( $meta[ $id ][ $key ] ) ? self::decode( $meta[ $id ][ $key ] ) : array();
				if ( ! $mail || ! isset( $mail['recipient'] ) ) {
					continue;
				}
				// The first mail is always sent; the second only when switched on.
				$notifications[] = self::notification( $name, '_mail' === $key || ! empty( $mail['active'] ), $mail['recipient'] );
			}

			$forms[] = self::form( 'contact-form-7', $id, $row->post_title, true, $fields, $notifications, isset( $embeds[ (string) $id ] ) ? $embeds[ (string) $id ] : array() );
		}

		return $forms;
	}

	/**
	 * Elementor Pro (and PRO Elements) forms: a form is a widget inside a
	 * page's or template's layout, so each layout that holds one is read, one
	 * at a time, and only its form widgets are looked at.
	 *
	 * Elementor saves only what differs from its defaults. A form whose
	 * fields were never changed has none stored: its "fields" is null, and it
	 * shows Elementor's default Name, Email and Message fields.
	 *
	 * @return array[]
	 */
	private static function forms_elementor() {
		global $wpdb;

		$types = self::embed_types();
		if ( ! $types ) {
			return array();
		}

		$posts = self::rows(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, m.meta_id, LENGTH( m.meta_value ) AS bytes
				FROM {$wpdb->postmeta} m
				INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key = '_elementor_data' AND p.post_status = 'publish' AND p.post_type IN ('" . implode( "', '", $types ) . "') AND m.meta_value LIKE %s
				ORDER BY p.ID ASC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.QuotedDynamicPlaceholderGeneration -- The post types went through esc_sql() in embed_types().
				'%' . $wpdb->esc_like( '"widgetType":"form"' ) . '%',
				self::EMBED_SCAN_POSTS
			)
		);

		$forms   = array();
		$seen    = array();
		$too_big = 0;
		$started = microtime( true );

		foreach ( (array) $posts as $post ) {
			if ( count( $forms ) >= self::FORMS_PER_PLUGIN || isset( $seen[ (int) $post->ID ] ) ) {
				continue;
			}
			if ( microtime( true ) - $started > self::EMBED_SECONDS ) {
				self::note( 'Reading Elementor forms was stopped after ' . self::EMBED_SECONDS . ' seconds, so some may be missing.' );
				break;
			}
			$seen[ (int) $post->ID ] = true;

			if ( (int) $post->bytes > self::EMBED_MAX_BYTES ) {
				$too_big++;
				continue;
			}

			$layout = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", (int) $post->meta_id ) ), true );
			if ( ! is_array( $layout ) ) {
				continue;
			}

			$widgets = array();
			self::elementor_forms( $layout, $widgets, 0 );
			unset( $layout );

			foreach ( $widgets as $widget ) {
				$forms[] = self::elementor_form( $widget, (int) $post->ID, (string) $post->post_type );
			}
		}

		if ( $too_big ) {
			self::note( $too_big . ' Elementor layouts with a form were too large to read, so their forms are not listed.' );
		}

		return array_slice( $forms, 0, self::FORMS_PER_PLUGIN );
	}

	/**
	 * One Elementor form widget as a form.
	 *
	 * @param array  $widget    The widget element from the layout.
	 * @param int    $post_id   The page or template whose layout holds it.
	 * @param string $post_type That post's type.
	 * @return array
	 */
	private static function elementor_form( array $widget, $post_id, $post_type ) {
		$settings = isset( $widget['settings'] ) && is_array( $widget['settings'] ) ? $widget['settings'] : array();

		$fields = null;
		if ( isset( $settings['form_fields'] ) && is_array( $settings['form_fields'] ) ) {
			$fields = array();
			foreach ( $settings['form_fields'] as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$label = '';
				foreach ( array( 'field_label', 'placeholder', 'custom_id' ) as $key ) {
					if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) && '' !== (string) $field[ $key ] ) {
						$label = $field[ $key ];
						break;
					}
				}
				// A text field has no type stored: it is the default.
				$fields[] = self::field( $label, isset( $field['field_type'] ) && '' !== $field['field_type'] ? $field['field_type'] : 'text', ! empty( $field['required'] ) && 'false' !== $field['required'] );
			}
		}

		// Without stored actions the form sends one email, to the site's
		// admin address: Elementor's defaults.
		$actions       = isset( $settings['submit_actions'] ) && is_array( $settings['submit_actions'] ) ? $settings['submit_actions'] : array( 'email' );
		$notifications = array();
		if ( in_array( 'email', $actions, true ) ) {
			$notifications[] = self::notification( 'Email', true, isset( $settings['email_to'] ) && is_scalar( $settings['email_to'] ) && '' !== (string) $settings['email_to'] ? $settings['email_to'] : '{admin_email}' );
		}
		if ( in_array( 'email2', $actions, true ) ) {
			$notifications[] = self::notification( 'Email 2', true, isset( $settings['email_to_2'] ) ? $settings['email_to_2'] : '' );
		}

		return self::form(
			'elementor',
			isset( $widget['id'] ) && is_scalar( $widget['id'] ) ? (string) $widget['id'] : '',
			isset( $settings['form_name'] ) && is_scalar( $settings['form_name'] ) ? $settings['form_name'] : '',
			true,
			$fields,
			$notifications,
			array(
				array(
					'id'   => (int) $post_id,
					'type' => (string) $post_type,
				),
			)
		);
	}

	/**
	 * Collect the form widgets in an Elementor layout.
	 *
	 * @param array $elements Elements at one level of the layout.
	 * @param array $found    Form widgets found so far; added to.
	 * @param int   $depth    How deep this level is; a guard against a layout that loops.
	 */
	private static function elementor_forms( array $elements, array &$found, $depth ) {
		if ( $depth > 30 ) {
			return;
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['widgetType'] ) && 'form' === $element['widgetType'] ) {
				$found[] = $element;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::elementor_forms( $element['elements'], $found, $depth + 1 );
			}
		}
	}

	/**
	 * The public content types looked at when finding where forms are
	 * embedded, ready for a query: every public type but media.
	 *
	 * @return string[]
	 */
	private static function embed_types() {
		$types = array();
		foreach ( array_keys( get_post_types( array( 'public' => true ) ) ) as $type ) {
			if ( 'attachment' !== $type ) {
				$types[] = esc_sql( (string) $type );
			}
		}
		return $types;
	}

	/**
	 * Which published posts embed which form.
	 *
	 * Looks in post content (shortcodes and blocks, which is also where
	 * WPBakery, Divi and Avada keep theirs) and in Elementor layouts
	 * (shortcode widgets). The database finds the posts that hold one of the
	 * markers; only those are read, a few at a time, to pick out the form's
	 * ID, and nothing else of them is kept. A form placed by a sidebar
	 * widget, a theme file or a pop-up plugin is not found.
	 *
	 * @param string[] $needles  Markers, e.g. '[gravityform'.
	 * @param string[] $patterns Regular expressions whose first group is the form's ID or key.
	 * @param string[] $aliases  Form ID by lower-case key, for plugins whose shortcode may use either.
	 * @return array[] List of { id, type } by form ID (as a string).
	 */
	private static function embeds( array $needles, array $patterns, array $aliases = array() ) {
		global $wpdb;

		$types = self::embed_types();
		if ( ! $types || ! $needles ) {
			return array();
		}
		$in_types = "'" . implode( "', '", $types ) . "'";

		$content_like = array();
		$meta_like    = array();
		$values       = array();
		foreach ( $needles as $needle ) {
			$content_like[] = 'p.post_content LIKE %s';
			$meta_like[]    = 'm.meta_value LIKE %s';
			$values[]       = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The post types went through esc_sql(); the LIKE clauses are built from placeholders only.
		$in_content = self::rows(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, LENGTH( p.post_content ) AS bytes FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND p.post_type IN ($in_types) AND ( " . implode( ' OR ', $content_like ) . ' ) ORDER BY p.ID ASC LIMIT %d',
				array_merge( $values, array( self::EMBED_SCAN_POSTS ) )
			)
		);
		$in_layout  = self::rows(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, m.meta_id, LENGTH( m.meta_value ) AS bytes FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_elementor_data' AND p.post_status = 'publish' AND p.post_type IN ($in_types) AND ( " . implode( ' OR ', $meta_like ) . ' ) ORDER BY p.ID ASC LIMIT %d',
				array_merge( $values, array( self::EMBED_SCAN_POSTS ) )
			)
		);
		// phpcs:enable

		$embeds = array();

		$collect = function ( $post, $text ) use ( &$embeds, $patterns, $aliases ) {
			// Elementor keeps a shortcode inside JSON, with its quotes escaped.
			$text = str_replace( '\\', '', (string) $text );
			foreach ( $patterns as $pattern ) {
				if ( ! preg_match_all( $pattern, $text, $matches ) ) {
					continue;
				}
				foreach ( $matches[1] as $ref ) {
					$ref = strtolower( (string) $ref );
					$ref = isset( $aliases[ $ref ] ) ? $aliases[ $ref ] : $ref;
					$embeds[ $ref ] = self::merge_embeds(
						isset( $embeds[ $ref ] ) ? $embeds[ $ref ] : array(),
						array(
							array(
								'id'   => (int) $post->ID,
								'type' => (string) $post->post_type,
							),
						)
					);
				}
			}
		};

		$started = microtime( true );
		$too_big = 0;
		$stopped = false;

		foreach ( array( 'content' => (array) $in_content, 'layout' => (array) $in_layout ) as $where => $posts ) {
			foreach ( $posts as $post ) {
				if ( microtime( true ) - $started > self::EMBED_SECONDS ) {
					$stopped = true;
					break 2;
				}
				if ( (int) $post->bytes > self::EMBED_MAX_BYTES ) {
					$too_big++;
					continue;
				}
				$collect(
					$post,
					'content' === $where
						? $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", (int) $post->ID ) )
						: $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", (int) $post->meta_id ) )
				);
			}
		}

		if ( $stopped ) {
			self::note( 'Looking for the pages that embed forms (' . $needles[0] . ') was stopped after ' . self::EMBED_SECONDS . ' seconds, so "embedded_on" may be incomplete.' );
		}
		if ( $too_big ) {
			self::note( $too_big . ' pages that may embed a form (' . $needles[0] . ') were too large to read, so "embedded_on" may be incomplete.' );
		}

		return $embeds;
	}

	/**
	 * Add posts to a form's list of embedding posts, once each.
	 *
	 * @param array[] $list  { id, type } entries so far.
	 * @param array[] $posts Entries to add.
	 * @return array[]
	 */
	private static function merge_embeds( array $list, array $posts ) {
		$have = array();
		foreach ( $list as $entry ) {
			$have[ (int) $entry['id'] ] = true;
		}
		foreach ( $posts as $entry ) {
			if ( ! isset( $have[ (int) $entry['id'] ] ) && count( $list ) < self::EMBEDS_PER_FORM ) {
				$list[]                     = $entry;
				$have[ (int) $entry['id'] ] = true;
			}
		}
		return $list;
	}

	/**
	 * Build one result per item, each in its own WPML language.
	 *
	 * The items are taken one language at a time, so WPML is switched once
	 * per language and not once per item; the results come back in the order
	 * the items were given. The language in use before is put back whatever
	 * happens. Without WPML this is a plain loop.
	 *
	 * @param array    $items     Items, any keys.
	 * @param array    $languages Language code or null for each item, by the same keys.
	 * @param callable $build     Called with the item, its language (or null) and the
	 *                            language WPML is answering in (or null); returns the result.
	 * @return array[]
	 */
	private static function each_in_language( array $items, array $languages, $build ) {
		$original = self::language();
		$current  = $original;

		$groups = array();
		foreach ( $items as $key => $item ) {
			$groups[ isset( $languages[ $key ] ) ? (string) $languages[ $key ] : '' ][] = $key;
		}

		$position = array_flip( array_keys( $items ) );
		$results  = array();

		try {
			foreach ( $groups as $code => $keys ) {
				$language = '' !== (string) $code ? (string) $code : null;
				$current  = self::switch_language( null !== $language ? $language : $original, $current );
				foreach ( $keys as $key ) {
					$results[ $position[ $key ] ] = call_user_func( $build, $items[ $key ], $language, $current );
				}
			}
		} finally {
			self::switch_language( $original, $current );
		}

		ksort( $results );
		return array_values( $results );
	}

	/**
	 * The languages a WPML site is published in, default first; null on a
	 * site with one language.
	 *
	 * @return array|null { default: string, active: string[] }
	 */
	private static function site_languages() {
		if ( ! self::multilingual() ) {
			return null;
		}

		$default = apply_filters( 'wpml_default_language', null );
		$active  = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$codes   = is_array( $active ) ? array_map( 'strval', array_keys( $active ) ) : array();
		$default = is_string( $default ) ? $default : '';

		// Default first, the rest by code.
		$codes = array_values( array_diff( $codes, array( $default ) ) );
		sort( $codes );
		if ( '' !== $default ) {
			array_unshift( $codes, $default );
		}

		return array(
			'default' => $default,
			'active'  => $codes,
		);
	}

	/**
	 * Whether WPML is running and set up. With it, WordPress answers questions about a
	 * page, menu or term in another language with that item's translation, so
	 * the inventory asks each one in its own language.
	 *
	 * @return bool
	 */
	private static function multilingual() {
		static $running = null;
		if ( null === $running ) {
			// Loaded, and set up: a WPML that never finished its setup has no default language.
			$default = defined( 'ICL_SITEPRESS_VERSION' ) && has_action( 'wpml_switch_language' ) ? apply_filters( 'wpml_default_language', null ) : null;
			$running = is_string( $default ) && '' !== $default;
		}
		return $running;
	}

	/**
	 * The language WPML is answering in, or null without WPML.
	 *
	 * @return string|null
	 */
	private static function language() {
		if ( ! self::multilingual() ) {
			return null;
		}
		$code = apply_filters( 'wpml_current_language', null );
		return is_string( $code ) && '' !== $code ? $code : null;
	}

	/**
	 * Ask WPML to answer in another language. Does nothing without WPML, or
	 * when that language is already the current one. A language WPML does not
	 * have (pages left from one that was switched off) leaves it where it was.
	 *
	 * @param string|null $code    Language wanted.
	 * @param string|null $current Language in use now.
	 * @return string|null The language in use afterwards.
	 */
	private static function switch_language( $code, $current ) {
		if ( null === $code || $code === $current || ! self::multilingual() ) {
			return $current;
		}
		do_action( 'wpml_switch_language', $code );

		// WPML ignores a language it no longer has; believe what it says it is using.
		$now = self::language();
		return null !== $now ? $now : $code;
	}

	/**
	 * What WPML knows about each item, read from its own table in one query:
	 * the item's language, and the translation group it shares with the same
	 * item in the other languages.
	 *
	 * @param string $type WPML element type: post_page for pages, tax_nav_menu for menus.
	 * @param int[]  $ids  Post IDs, or term taxonomy IDs for menus.
	 * @return array[] { language: string, group: int } by ID; empty without WPML. Items WPML does not know are left out.
	 */
	private static function translations_of( $type, array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids || ! self::multilingual() ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT element_id, language_code, trid FROM {$wpdb->prefix}icl_translations WHERE element_type = %s AND element_id IN (" . implode( ',', $ids ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WPML's table; the IDs are integers.
				$type
			)
		);

		$known = array();
		foreach ( (array) $rows as $row ) {
			if ( '' !== (string) $row->language_code ) {
				$known[ (int) $row->element_id ] = array(
					'language' => (string) $row->language_code,
					'group'    => (int) $row->trid,
				);
			}
		}
		return $known;
	}

	/**
	 * A post object carrying only what get_permalink() needs, and no content,
	 * so WordPress works out the address without loading the real post.
	 *
	 * @param object $row Row with ID, post_title, post_name and post_parent;
	 *                    a published page unless it says otherwise.
	 * @return WP_Post
	 */
	private static function bare_post( $row ) {
		$fields = array(
			'ID'          => (int) $row->ID,
			'post_type'   => isset( $row->post_type ) ? (string) $row->post_type : 'page',
			'post_status' => isset( $row->post_status ) ? (string) $row->post_status : 'publish',
			'post_title'  => (string) $row->post_title,
			'post_name'   => (string) $row->post_name,
			'post_parent' => (int) $row->post_parent,
			'filter'      => 'raw', // Already "sanitized": get_post() hands the object back untouched.
		);

		// Date and author appear in the addresses of posts, not pages.
		foreach ( array( 'post_date', 'post_date_gmt', 'post_author' ) as $field ) {
			if ( isset( $row->$field ) ) {
				$fields[ $field ] = (string) $row->$field;
			}
		}

		return new WP_Post( (object) $fields );
	}

	/**
	 * Which builder a page was made with.
	 *
	 * A builder that keeps its layout outside the page content (GoodLayers,
	 * SiteOrigin, Oxygen, Thrive) is recognised by its own meta value, and
	 * only when that value holds a layout: these builders save an empty one on
	 * pages written in the normal editor. The page-builder plugins come before
	 * the theme builders, and the editors last, so a page that carries traces
	 * of more than one is named after the one most likely to be drawing it.
	 *
	 * @param array $meta    The start of each of the page's builder meta values.
	 * @param array $content Which builders' markup the page content holds:
	 *                       wpbakery, blocks, avada, kingcomposer, bold-builder.
	 * @return string elementor, wpbakery, divi, beaver-builder, avada, oxygen,
	 *                thrive, siteorigin, kingcomposer, bold-builder,
	 *                goodlayers, blocks or classic.
	 */
	private static function builder( array $meta, array $content ) {
		if ( isset( $meta['_elementor_edit_mode'] ) && 'builder' === $meta['_elementor_edit_mode'] ) {
			return 'elementor';
		}
		if ( ! empty( $content['wpbakery'] ) ) {
			return 'wpbakery';
		}
		if ( isset( $meta['_et_pb_use_builder'] ) && 'on' === $meta['_et_pb_use_builder'] ) {
			return 'divi';
		}
		if ( ! empty( $meta['_fl_builder_enabled'] ) ) {
			return 'beaver-builder';
		}
		if ( ! empty( $content['avada'] ) || ( isset( $meta['fusion_builder_status'] ) && 'active' === $meta['fusion_builder_status'] ) ) {
			return 'avada';
		}
		// Oxygen: a root element with children, as its own
		// oxygen_json_has_elements() tests it, or shortcodes from before 4.0.
		foreach ( array( 'ct_builder_json', '_ct_builder_json' ) as $key ) {
			if ( isset( $meta[ $key ] ) && false !== strpos( $meta[ $key ], '"children":[{' ) ) {
				return 'oxygen';
			}
		}
		if ( self::has_layout( $meta, 'ct_builder_shortcodes' ) || self::has_layout( $meta, '_ct_builder_shortcodes' ) ) {
			return 'oxygen';
		}
		if ( ! empty( $meta['tcb_editor_enabled'] ) ) {
			return 'thrive';
		}
		if ( self::has_layout( $meta, 'panels_data', array( 'a:0:{}' ) ) && false === strpos( $meta['panels_data'], '"widgets";a:0:{}' ) ) {
			return 'siteorigin';
		}
		if ( ! empty( $content['kingcomposer'] ) ) {
			return 'kingcomposer';
		}
		if ( ! empty( $content['bold-builder'] ) ) {
			return 'bold-builder';
		}
		if ( self::has_layout( $meta, 'page-option-item-xml', array( '<item-tag></item-tag>' ) ) || self::has_layout( $meta, 'gdlr-core-page-builder', array( 'a:0:{}' ) ) ) {
			return 'goodlayers';
		}
		return ! empty( $content['blocks'] ) ? 'blocks' : 'classic';
	}

	/**
	 * Whether a builder's layout meta value holds anything.
	 *
	 * @param array    $meta  The start of each of the page's builder meta values.
	 * @param string   $key   Meta key.
	 * @param string[] $empty Values the builder saves for a page without a layout.
	 * @return bool
	 */
	private static function has_layout( array $meta, $key, array $empty = array() ) {
		if ( ! isset( $meta[ $key ] ) ) {
			return false;
		}
		$value = trim( $meta[ $key ] );
		return '' !== $value && ! in_array( $value, $empty, true );
	}
}
