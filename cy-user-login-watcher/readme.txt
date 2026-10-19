=== CY User Login Watcher ===
Contributors: controlyours
Requires at least: 6.0
Tested up to: 7.1.3
Requires PHP: 7.4
Stable tag: 1.2.0
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
* Reports to the Control Yours Admin Dashboard (admin.controlyours.com): each login and each throttled "last active" update is sent as it happens (non-blocking, so a slow or unreachable dashboard never delays a login by more than three seconds and never stops it being recorded), and a full snapshot of tracked users goes out once a day from WP-cron so the dashboard can show who has never logged in and drop users that were deleted. The site identifies itself with a random key it generated on its own and stays "pending" on the dashboard until someone approves it there; no shared secret is stored in this plugin. Untick the box under Settings > Login Watcher to stop reporting from one site, define `CY_ULW_DASHBOARD_REPORTING` as false in wp-config.php to switch it off outright, or define `CY_ULW_DASHBOARD_URL` to point a site at a different dashboard. Staging and development copies send nothing: a site whose address is on `wpenginepowered.com` or `wpengine.com` is treated as a copy of a production site and never announces itself to the dashboard, so pushing production to staging adds nothing to the Pending list. Define `CY_ULW_DASHBOARD_REPORTING` as true in wp-config.php on a copy you do want to watch.
* One small settings screen (Settings > Login Watcher): roles not tracked, roles that can see login data, and the dashboard reporting switch with its status. No front-end output. No database tables. One daily cron event (the dashboard snapshot). Two small autoloaded options.

== Performance ==

* Front-end visitors: only the main plugin file is included. It registers hooks and does nothing else: zero queries, zero HTTP requests, no scripts or styles.
* Logged-in admin requests: one meta read served from the user meta cache WordPress already loaded for capability checks (no query), and one small write at most every 15 minutes per user.
* Users screen and Dashboard: the column reads from the meta cache the list table already primed (no extra queries per row). The widget runs a handful of indexed queries and only for administrators viewing the Dashboard.
* Update checks: piggyback on WordPress core's own schedule (cron twice daily, admin at most hourly), fetch one ~1 KB JSON file and cache it for 6 hours. Never on the front end.
* Dashboard reporting: one small non-blocking HTTP request per login and per "last active" write (so at most one every 15 minutes per user), plus one blocking request a day from cron carrying every tracked user. Logins are recorded before the request is made and regardless of its outcome.

== Data stored ==

* User meta `_cy_ulw_last_login` — Unix timestamp (UTC) of the last login.
* User meta `_cy_ulw_last_active` — Unix timestamp (UTC) of the last recorded admin/REST activity, written at most once every 15 minutes per user.
* Option `cy_ulw_installed` (not autoloaded) — Unix timestamp of activation, used to qualify "No login recorded".
* Option `cy_ulw_settings` (autoloaded, a few bytes) — the settings from Settings > Login Watcher. Filters below take precedence over it.
* Option `cy_ulw_reporter` (autoloaded, under 200 bytes) — the site's random dashboard key, when a report and a snapshot were last sent, the last error, and whether reporting is switched off.
* Cron event `cy_ulw_daily_report` (daily) — sends the user snapshot to the dashboard.
* Site transient `cy_ulw_update_manifest` — cached update manifest.

Deleting the plugin from the Plugins screen removes all of the above. Deactivating removes the cron event and leaves the data in place.

== Sent to the dashboard ==

Each report is a JSON `POST` to `https://admin.controlyours.com/api/logins/intake` with the site key in an `X-CY-Site-Key` header. It carries the site's home URL, name, WP Engine install name (when the `PWP_NAME` constant exists), plugin, WordPress and PHP versions, and for each user included: ID, username, display name, email, roles, last login and last active timestamps. Login and activity reports carry the one user; the daily snapshot carries every tracked user. Nothing else (no passwords, no content, no visitors) is sent.

== Filters ==

* `cy_ulw_track_activity` (bool, default `true`) — disable "last active" tracking entirely; only logins are recorded.
* `cy_ulw_activity_throttle` (int seconds, default `900`; receives `$user_id`) — minimum time between "last active" writes.
* `cy_ulw_track_user` (bool, default `true`; receives `$user_id`) — return `false` to skip tracking for a specific user (e.g. an agency account).
* `cy_ulw_view_capability` (string, default `manage_options`; `manage_network_users` in the network admin) — who can see the column and widget.
* `cy_ulw_widget_count` (int, default `10`) — how many users the Dashboard widget lists.
* `cy_ulw_reporting_enabled` (bool, default `true` unless unticked on the settings screen) — return `false` to stop reporting to the dashboard. The `CY_ULW_DASHBOARD_REPORTING` constant and the staging check win over this filter.
* `cy_ulw_is_staging_site` (bool, default `true` when the home URL host ends in `.wpenginepowered.com` or `.wpengine.com`) — whether this site counts as a staging or development copy and therefore sends nothing to the dashboard. Return `false` on a production site the rule catches by mistake, or `true` on a copy it misses.

Action `cy_ulw_login_recorded( $user_id, $timestamp, $user )` fires after a login is stored.

Action `cy_ulw_activity_recorded( $user_id, $timestamp )` fires after a "last active" timestamp is stored (at most once per throttle window per user).

Constant `CY_ULW_UPDATE_MANIFEST` (define in wp-config.php) — point a site at a different update manifest URL, e.g. a staging branch.

Constant `CY_ULW_AUTO_UPDATE` (define in wp-config.php as `false`) — keep a site on manual updates instead of installing new releases automatically.

Constant `CY_ULW_DASHBOARD_REPORTING` (define in wp-config.php as `false` or `true`) — `false` sends nothing to the Admin Dashboard from this site; `true` reports from a staging or development copy that would otherwise be skipped. The settings checkbox is shown disabled when the constant is `false` and on a skipped copy.

Constant `CY_ULW_DASHBOARD_URL` (define in wp-config.php) — send reports to a different intake URL, e.g. a local build of the dashboard.

== Updates ==

The plugin header declares `Update URI: https://github.com/Control-Yours/User-Login-Watcher-Plugin`, so WordPress never asks wordpress.org about it. Instead it reads `update.json` from the repository's `main` branch and installs the zip attached to the matching GitHub Release. The plugin opts itself into WordPress background updates (filter `auto_update_plugin`), so a new release is installed by the next scheduled update check rather than waiting for a click; core's own switches (`AUTOMATIC_UPDATER_DISABLED`, `DISALLOW_FILE_MODS`, the `automatic_updater_disabled` filter) still disable it. Releases are produced with `bin/release.sh` in the repository; see its README.

== Installation ==

1. Upload the `cy-user-login-watcher` folder to `/wp-content/plugins/` or install the zip from Plugins > Add New > Upload.
2. Activate. Tracking starts immediately; existing users show "No login recorded" until they next sign in.

== Changelog ==

= 1.2.1 =
* Staging and development copies no longer report to the Admin Dashboard. A site whose home URL is on `wpenginepowered.com` or `wpengine.com` sends no events and no snapshot and drops its daily cron event, so pushing production to staging no longer adds a pending site to the dashboard. The settings checkbox is shown disabled there with a note. Define `CY_ULW_DASHBOARD_REPORTING` as true in wp-config.php to report from such a copy anyway, or use the new `cy_ulw_is_staging_site` filter to correct the detection on a site.

= 1.2.0 =
* Reports to the Control Yours Admin Dashboard: logins and "last active" updates are sent as they happen, plus a daily snapshot of every tracked user. Each site generates its own key and waits as "pending" on the dashboard until approved there. New "Dashboard reporting" section under Settings > Login Watcher with an on/off checkbox and status (last sent, last snapshot, last error, site key prefix). New constants `CY_ULW_DASHBOARD_REPORTING` and `CY_ULW_DASHBOARD_URL`, filter `cy_ulw_reporting_enabled`, action `cy_ulw_activity_recorded`, option `cy_ulw_reporter` and daily cron event `cy_ulw_daily_report`.

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
