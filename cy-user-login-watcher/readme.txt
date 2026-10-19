=== CY User Login Watcher ===
Contributors: controlyours
Requires at least: 6.0
Tested up to: 7.1.3
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows when each user last logged in (and was last active in the admin) so you can tell whether a client has been in the site before you make changes.

== Description ==

Internal Control Yours plugin. Replaces the "last login" column that our previous security plugin provided.

* Records the timestamp of each successful login (`wp_login`).
* Records a throttled "last active" timestamp whenever a logged-in user loads an admin screen, admin-ajax request or REST request. Heartbeat pings from idle tabs are ignored, so a tab left open does not count as activity. This matters because WordPress keeps users logged in for up to 14 days with "Remember Me", so the login timestamp alone can under-report activity.
* Adds a sortable **Last Login** column to Users > All Users (and Network Admin > Users on multisite). Hover for the exact date/time. Users who have never logged in since activation are labelled and sort to the bottom rather than disappearing.
* Adds a **Recent Logins** widget to the Dashboard listing the ten most recent logins, plus a count of users who have not logged in since tracking began.
* Column and widget are shown to administrators, plus any roles ticked under Settings > Login Watcher. Other client roles never see them.
* Tracks people who work in the site. Users whose only roles are Subscriber or WooCommerce Customer are not tracked by default (filter `cy_ulw_excluded_roles`), so stores and membership sites stay light.
* Updates itself from GitHub Releases. New releases are installed by the twice-daily WordPress update check without anyone clicking Update; they also appear on the normal Updates screen. Define `CY_ULW_AUTO_UPDATE` as false in wp-config.php to keep a site on manual updates.
* One small settings screen (Settings > Login Watcher): roles not tracked and roles that can see login data. No front-end output. No database tables. No cron events. One small autoloaded option.

== Performance ==

* Front-end visitors: only the main plugin file is included. It registers hooks and does nothing else: zero queries, zero HTTP requests, no scripts or styles.
* Logged-in admin requests: one meta read served from the user meta cache WordPress already loaded for capability checks (no query), and one small write at most every 15 minutes per user.
* Users screen and Dashboard: the column reads from the meta cache the list table already primed (no extra queries per row). The widget runs a handful of indexed queries and only for administrators viewing the Dashboard.
* Update checks: piggyback on WordPress core's own schedule (cron twice daily, admin at most hourly), fetch one ~1 KB JSON file and cache it for 6 hours. Never on the front end.

== Data stored ==

* User meta `_cy_ulw_last_login` — Unix timestamp (UTC) of the last login.
* User meta `_cy_ulw_last_active` — Unix timestamp (UTC) of the last recorded admin/REST activity, written at most once every 15 minutes per user.
* Option `cy_ulw_installed` (not autoloaded) — Unix timestamp of activation, used to qualify "No login recorded".
* Option `cy_ulw_settings` (autoloaded, a few bytes) — the settings from Settings > Login Watcher. Filters below take precedence over it.
* Site transient `cy_ulw_update_manifest` — cached update manifest.

Deleting the plugin from the Plugins screen removes all of the above. Deactivating leaves the data in place.

== Filters ==

* `cy_ulw_track_activity` (bool, default `true`) — disable "last active" tracking entirely; only logins are recorded.
* `cy_ulw_activity_throttle` (int seconds, default `900`; receives `$user_id`) — minimum time between "last active" writes.
* `cy_ulw_track_user` (bool, default `true`; receives `$user_id`) — return `false` to skip tracking for a specific user (e.g. an agency account).
* `cy_ulw_view_capability` (string, default `manage_options`; `manage_network_users` in the network admin) — who can see the column and widget.
* `cy_ulw_widget_count` (int, default `10`) — how many users the Dashboard widget lists.

Action `cy_ulw_login_recorded( $user_id, $timestamp, $user )` fires after a login is stored.

Constant `CY_ULW_UPDATE_MANIFEST` (define in wp-config.php) — point a site at a different update manifest URL, e.g. a staging branch.

Constant `CY_ULW_AUTO_UPDATE` (define in wp-config.php as `false`) — keep a site on manual updates instead of installing new releases automatically.

== Updates ==

The plugin header declares `Update URI: https://github.com/Control-Yours/User-Login-Watcher-Plugin`, so WordPress never asks wordpress.org about it. Instead it reads `update.json` from the repository's `main` branch and installs the zip attached to the matching GitHub Release. The plugin opts itself into WordPress background updates (filter `auto_update_plugin`), so a new release is installed by the next scheduled update check rather than waiting for a click; core's own switches (`AUTOMATIC_UPDATER_DISABLED`, `DISALLOW_FILE_MODS`, the `automatic_updater_disabled` filter) still disable it. Releases are produced with `bin/release.sh` in the repository; see its README.

== Installation ==

1. Upload the `cy-user-login-watcher` folder to `/wp-content/plugins/` or install the zip from Plugins > Add New > Upload.
2. Activate. Tracking starts immediately; existing users show "No login recorded" until they next sign in.

== Changelog ==

= 1.1.2 =
* New releases now install themselves through WordPress background updates instead of waiting on the Plugins screen for someone to click Update. Define `CY_ULW_AUTO_UPDATE` as false in wp-config.php to keep a site on manual updates.

= 1.1.1 =
* Removed the activity update interval field from the settings page; the 15-minute default stays and the `cy_ulw_activity_throttle` filter still adjusts it.

= 1.1.0 =
* New Settings > Login Watcher screen: tick which roles are not tracked (default Subscriber and Customer), which roles may see the Last Login column and Recent Logins widget (administrators always can), and how often "last active" refreshes. A Settings link appears on the Plugins row. The code filters still work and override the screen.

= 1.0.3 =
* Only users who work in the site are tracked: anyone whose only roles are Subscriber or WooCommerce Customer is skipped, shows "Not tracked" in the Users column, and is left out of the Dashboard widget count. Filter `cy_ulw_excluded_roles` adjusts the list per site.

= 1.0.2 =
* Record logins made through One-Time Login links, which bypass the standard WordPress login hook.

= 1.0.1 =
* Repository moved to the Control-Yours GitHub organization; updates now come from Control-Yours/User-Login-Watcher-Plugin.
* Declare testing against WordPress 7.1.3.

= 1.0.0 =
* Initial release.
