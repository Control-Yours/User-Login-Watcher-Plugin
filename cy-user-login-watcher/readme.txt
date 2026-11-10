=== Control Yours Connector ===
Contributors: controlyours
Requires at least: 6.0
Tested up to: 7.1.3
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects a client site to the Control Yours Admin Dashboard: shows when each user last logged in (and was last active in the admin) so you can tell whether a client has been in the site before you make changes, and sends a site inventory when the dashboard asks for one.

== Description ==

Internal Control Yours plugin, called CY User Login Watcher until version 1.5.0. The folder, the main file and every setting keep the `cy-user-login-watcher` / `cy_ulw_` names, so the rename changes nothing on a site but the name shown. Replaces the "last login" column that our previous security plugin provided.

* Records the timestamp of each successful login (`wp_login`).
* Records a throttled "last active" timestamp whenever a logged-in user loads an admin screen, admin-ajax request or REST request. Heartbeat pings from idle tabs are ignored, so a tab left open does not count as activity. This matters because WordPress keeps users logged in for up to 14 days with "Remember Me", so the login timestamp alone can under-report activity.
* Adds a sortable **Last Login** column to Users > All Users (and Network Admin > Users on multisite). Hover for the exact date/time. Users who have never logged in since activation are labelled and sort to the bottom rather than disappearing.
* Adds a **Recent Logins** widget to the Dashboard listing the ten most recent logins, plus a count of users who have not logged in since tracking began.
* Column and widget are shown to administrators, plus any roles ticked under Settings > CY Connector. Other client roles never see them.
* Tracks people who work in the site. Only recognised roles are tracked by default: Administrator, Editor, Author, Contributor, WooCommerce Shop Manager and the Control Yours Client role. Subscribers, customers and any role a plugin added (members, agents, dealers, portal clients) are off until ticked under Settings > CY Connector on that site, so stores and membership sites stay light and a plugin's customer role never floods the list. Filter `cy_ulw_tracked_roles` adjusts the set in code.
* Updates itself from GitHub Releases. New releases are installed by the twice-daily WordPress update check without anyone clicking Update; they also appear on the normal Updates screen. Define `CY_ULW_AUTO_UPDATE` as false in wp-config.php to keep a site on manual updates.
* Reports to the Control Yours Admin Dashboard (admin.controlyours.com): each login and each throttled "last active" update is sent as it happens (non-blocking, so a slow or unreachable dashboard never delays a login by more than three seconds and never stops it being recorded), and a full snapshot of tracked users goes out once a day from WP-cron so the dashboard can show who has never logged in and drop users that were deleted. The site identifies itself with a random key it generated on its own and stays "pending" on the dashboard until someone approves it there; no shared secret is stored in this plugin. Untick the box under Settings > CY Connector to stop reporting from one site, define `CY_ULW_DASHBOARD_REPORTING` as false in wp-config.php to switch it off outright, or define `CY_ULW_DASHBOARD_URL` to point a site at a different dashboard. Staging, development and local copies send nothing: a site whose address is on `wpenginepowered.com` or `wpengine.com`, ends in `.local`, `.test` or `.localhost`, is `localhost` or a loopback address, or whose environment type (`WP_ENVIRONMENT_TYPE`) is `local` or `development` is treated as a copy of a production site and never announces itself to the dashboard, so pushing production to staging or pulling it into Local adds nothing to the Pending list. Define `CY_ULW_DASHBOARD_REPORTING` as true in wp-config.php on a copy you do want to watch.
* Switches off the theme and plugin file editors in wp-admin (Appearance > Theme File Editor, Plugins > Plugin File Editor) for everyone, administrators included, the same way core does when `DISALLOW_FILE_EDIT` is true. WP Engine's wp-config.php sets that constant to false, so without this any administrator can edit the site's PHP from the browser, and one stolen admin login becomes a site takeover. On by default; untick "File editor" under Settings > CY Connector while someone needs the editor. `DISALLOW_FILE_EDIT` set to true in wp-config.php keeps the editors off regardless. On multisite the editors live in Network Admin, which follows the main site's setting.
* Sends a site inventory to the Admin Dashboard, only when asked: the dashboard requests one in its answer to the daily snapshot, or an administrator presses "Send site inventory now" under Settings > CY Connector. It lists what the site is made of (theme, plugins, published pages and the builder each was made with, menus, content types, a media total) so the team can plan the site's move to SiteForge. See "Site inventory" below for exactly what is and is not sent. It is built and sent from WP-cron, never during a login or a page view, and follows the same on/off switch and staging rules as login reporting.
* One small settings screen (Settings > CY Connector): roles tracked, roles that can see login data, the file editor switch, the dashboard reporting switch with its status, and the site inventory button with its last result. No front-end output. No database tables. One daily cron event (the dashboard snapshot) and a one-off event while an inventory is waiting to run. Two small autoloaded options.

== Performance ==

* Front-end visitors: only the main plugin file is included. It registers hooks and does nothing else: zero queries, zero HTTP requests, no scripts or styles.
* Logged-in admin requests: one meta read served from the user meta cache WordPress already loaded for capability checks (no query), and one small write at most every 15 minutes per user. The file editor switch adds one `map_meta_cap` filter that compares the capability name and returns at once unless it is one of the three editing capabilities.
* Users screen and Dashboard: the column reads from the meta cache the list table already primed (no extra queries per row). The widget runs a handful of indexed queries and only for administrators viewing the Dashboard.
* Update checks: piggyback on WordPress core's own schedule (cron twice daily, admin at most hourly), fetch one ~1 KB JSON file and cache it for 6 hours. Never on the front end.
* Dashboard reporting: one small non-blocking HTTP request per login and per "last active" write (so at most one every 15 minutes per user), plus one blocking request a day from cron carrying every tracked user. Logins are recorded before the request is made and regardless of its outcome.
* Site inventory: nothing at all until one is asked for; its code is not even loaded outside the cron run and the settings screen. A run reads pages 200 at a time without loading their content, sends a handful of requests from cron, and pauses and continues in a later cron run if it has used 20 seconds or 70% of the PHP memory limit.

== Data stored ==

* User meta `_cy_ulw_last_login` — Unix timestamp (UTC) of the last login.
* User meta `_cy_ulw_last_active` — Unix timestamp (UTC) of the last recorded admin/REST activity, written at most once every 15 minutes per user.
* Option `cy_ulw_installed` (not autoloaded) — Unix timestamp of activation, used to qualify "No login recorded".
* Option `cy_ulw_settings` (autoloaded, a few bytes) — the settings from Settings > CY Connector. Filters below take precedence over it.
* Option `cy_ulw_reporter` (autoloaded, under 200 bytes) — the site's random dashboard key, when a report and a snapshot were last sent, the last error, and whether reporting is switched off.
* Option `cy_ulw_inventory` (not autoloaded, created the first time an inventory is asked for) — the state of the last site inventory: when and how it was asked for, how far it got, and the result shown on the settings screen. It holds no inventory data.
* Cron event `cy_ulw_daily_report` (daily) — sends the user snapshot to the dashboard.
* Cron event `cy_ulw_inventory_run` (one-off, present only while an inventory is waiting to run or to continue) — builds and sends the site inventory.
* Site transient `cy_ulw_update_manifest` — cached update manifest.

Deleting the plugin from the Plugins screen removes all of the above. Deactivating removes the cron events and leaves the data in place.

== Sent to the dashboard ==

Each report is a JSON `POST` to `https://admin.controlyours.com/api/logins/intake` with the site key in an `X-CY-Site-Key` header. It carries the site's home URL, name, WP Engine install name (when the `PWP_NAME` constant exists), plugin, WordPress and PHP versions, and for each user included: ID, username, display name, email, roles, last login and last active timestamps. Login and activity reports carry the one user; the daily snapshot carries every tracked user. Nothing else (no passwords, no content, no visitors) is sent.

== Site inventory ==

Nothing is sent unless an inventory was asked for. There are two ways to ask: the dashboard includes `"requests": ["inventory"]` in its JSON answer to an accepted daily snapshot, or an administrator presses "Send site inventory now" under Settings > CY Connector. Either one only schedules the one-off `cy_ulw_inventory_run` cron event; the inventory is built and sent there. An empty answer, an answer that is not JSON, or a request this version does not know changes nothing.

Sent (as `inventory_version` 1):

* Site: name, tagline, home URL, language, permalink structure, logo and site icon URLs, WordPress and PHP versions, whether it is a multisite.
* Theme: the active theme and its parent, with versions.
* Plugins: every installed plugin's folder, name and version and whether it is active, plus the must-use plugins.
* Content types: each public post type with its number of published items and whether it is built in.
* Pages: for each published page its ID, title, slug, parent, menu order, template, URL, last modified date, whether it is the front page or the posts page, and the builder it was made with (Elementor, WPBakery, Divi, Beaver Builder, blocks or classic).
* Menus: each menu, the theme locations it is assigned to, and its items (label, target, parent).
* Media: the number of items and the total size of the original files. No file is uploaded or listed.
* Flags worked out from the active plugins: WooCommerce, a learning plugin, a forum, a multilingual plugin, form payments, an events plugin, and whether the site has a blog (six or more published posts).

Never sent: users, form entries, orders, customers, subscribers, passwords, keys, the value of any setting other than the site details listed above, and the content of any page or post. Page content is checked for builder markup inside the database query and is not read by the plugin.

The inventory is a series of JSON `POST` requests to `https://admin.controlyours.com/api/sites/inventory`, each under 1 MB, with the same `X-CY-Site-Key` header as login reports. `CY_ULW_DASHBOARD_URL` moves it together with the login reports: the inventory goes to the same address with `/api/logins/intake` replaced by `/api/sites/inventory`. The switches that stop login reporting (the settings checkbox, `CY_ULW_DASHBOARD_REPORTING`, the staging and local copy rules, the `cy_ulw_reporting_enabled` filter) stop the inventory too. If the dashboard refuses a part or cannot be reached, the inventory stops, the reason is shown under Settings > CY Connector, and login reporting carries on as before. The payload is documented in the repository's README.

== Filters ==

* `cy_ulw_track_activity` (bool, default `true`) — disable "last active" tracking entirely; only logins are recorded.
* `cy_ulw_activity_throttle` (int seconds, default `900`; receives `$user_id`) — minimum time between "last active" writes.
* `cy_ulw_track_user` (bool, default `true`; receives `$user_id`) — return `false` to skip tracking for a specific user (e.g. an agency account).
* `cy_ulw_view_capability` (string, default `manage_options`; `manage_network_users` in the network admin) — who can see the column and widget.
* `cy_ulw_widget_count` (int, default `10`) — how many users the Dashboard widget lists.
* `cy_ulw_disable_file_editor` (bool, default: the "File editor" setting, on unless unticked) — return `false` to leave the theme and plugin file editors available. `DISALLOW_FILE_EDIT` set to true in wp-config.php still switches them off.
* `cy_ulw_reporting_enabled` (bool, default `true` unless unticked on the settings screen) — return `false` to stop reporting to the dashboard. The `CY_ULW_DASHBOARD_REPORTING` constant and the staging check win over this filter.
* `cy_ulw_inventory_time_budget` (int seconds, default `20`) — how long one cron run may spend on a site inventory before it pauses and continues in the next run.
* `cy_ulw_is_staging_site` (bool, default `true` when the home URL host ends in `.wpenginepowered.com`, `.wpengine.com`, `.local`, `.test` or `.localhost`, is `localhost` or a loopback address, or the environment type is `local` or `development`) — whether this site counts as a staging, development or local copy and therefore sends nothing to the dashboard. Return `false` on a production site the rule catches by mistake, or `true` on a copy it misses.

Action `cy_ulw_login_recorded( $user_id, $timestamp, $user )` fires after a login is stored.

Action `cy_ulw_activity_recorded( $user_id, $timestamp )` fires after a "last active" timestamp is stored (at most once per throttle window per user).

Constant `CY_ULW_UPDATE_MANIFEST` (define in wp-config.php) — point a site at a different update manifest URL, e.g. a staging branch.

Constant `CY_ULW_AUTO_UPDATE` (define in wp-config.php as `false`) — keep a site on manual updates instead of installing new releases automatically.

Constant `CY_ULW_DASHBOARD_REPORTING` (define in wp-config.php as `false` or `true`) — `false` sends nothing to the Admin Dashboard from this site; `true` reports from a staging, development or local copy that would otherwise be skipped. The settings checkbox is shown disabled when the constant is `false` and on a skipped copy.

Constant `CY_ULW_DASHBOARD_URL` (define in wp-config.php) — send reports to a different intake URL, e.g. a local build of the dashboard. Give the full intake URL, ending in `/api/logins/intake`; the site inventory then goes to `/api/sites/inventory` on the same dashboard.

== Updates ==

The plugin header declares `Update URI: https://github.com/Control-Yours/User-Login-Watcher-Plugin`, so WordPress never asks wordpress.org about it. Instead it reads `update.json` from the repository's `main` branch and installs the zip attached to the matching GitHub Release. The plugin opts itself into WordPress background updates (filter `auto_update_plugin`), so a new release is installed by the next scheduled update check rather than waiting for a click; core's own switches (`AUTOMATIC_UPDATER_DISABLED`, `DISALLOW_FILE_MODS`, the `automatic_updater_disabled` filter) still disable it. Releases are produced with `bin/release.sh` in the repository; see its README.

== Installation ==

1. Upload the `cy-user-login-watcher` folder to `/wp-content/plugins/` or install the zip from Plugins > Add New > Upload.
2. Activate. Tracking starts immediately; existing users show "No login recorded" until they next sign in.

== Changelog ==

= 1.5.0 =
* The plugin is now called Control Yours Connector, and its settings screen is Settings > CY Connector (it was Settings > Login Watcher). Only the name shown changes: the folder, the main file, the settings, the dashboard key and the update channel are the same, so an updated site stays active and keeps everything it had.
* New: site inventory. When the Admin Dashboard asks for one in its answer to the daily snapshot (`"requests": ["inventory"]`), or an administrator presses the new "Send site inventory now" button under Settings > CY Connector, the site sends a one-time list of what it is made of: site details, theme, installed plugins, public content types, published pages with the builder each was made with, menus, a media count and size, and flags for WooCommerce, learning, forum, multilingual, form payment and events plugins and a blog. No users, form entries, orders, customers, setting values or page content. Nothing is sent unless asked for. It runs from WP-cron in parts under 1 MB to `/api/sites/inventory` on the dashboard, obeys the same on/off switch and staging rules as login reporting, and shows its last result on the settings screen. Until the dashboard accepts inventories, a run ends with a message saying so and nothing else is affected. New option `cy_ulw_inventory` (not autoloaded), one-off cron event `cy_ulw_inventory_run`, filter `cy_ulw_inventory_time_budget`.
* The daily snapshot now reads the dashboard's answer for requests. Anything other than a JSON object with a `requests` list is ignored.

= 1.4.0 =
* Switches off the theme and plugin file editors in wp-admin by default. WP Engine's wp-config.php defines `DISALLOW_FILE_EDIT` as false, which left Appearance > Theme File Editor and Plugins > Plugin File Editor open to every administrator on almost every site; the plugin now denies the three editing capabilities (`edit_themes`, `edit_plugins`, `edit_files`) exactly as core does when that constant is true, so the menus disappear and the editors' save action is refused. New "Security" section under Settings > Login Watcher with a "File editor" checkbox to allow the editors again on one site, new filter `cy_ulw_disable_file_editor`, new setting key `disable_file_editor` (true by default, also for sites updating from earlier versions). Nothing changes on sites where wp-config.php already sets `DISALLOW_FILE_EDIT` to true.

= 1.3.1 =
* Local copies no longer report to the Admin Dashboard. Besides WP Engine staging and development addresses, a site now counts as a copy and sends nothing when its home URL ends in `.local` (Local by WP Engine), `.test` or `.localhost`, is `localhost` or a loopback address, or its environment type (`WP_ENVIRONMENT_TYPE`) is `local` or `development`. A pulled copy keeps production's site key, so before this it could report under production's identity whenever WP-Cron ran on it. Environment type `staging` is not treated as a copy: WP Engine does not set it, and a live site carrying it by mistake would go silent. `CY_ULW_DASHBOARD_REPORTING` set to true still forces reporting on, and the `cy_ulw_is_staging_site` filter still corrects the detection.

= 1.3.0 =
* Tracking is now opt-in per role instead of opt-out. Only recognised roles are tracked by default (Administrator, Editor, Author, Contributor, WooCommerce Shop Manager, Client); every other role, including ones added by plugins such as customers, members, agents, dealers or portal clients, is untracked until ticked under Settings > Login Watcher, where the setting is now "Roles tracked" and plugin-added roles are marked. Administrators are always tracked. The next daily snapshot drops users who no longer qualify from the Admin Dashboard. A site that had unticked Subscriber or Customer under the old "Roles not tracked" setting needs them ticked again here. New filter `cy_ulw_tracked_roles`; `cy_ulw_excluded_roles` still removes roles from the tracked set.

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
