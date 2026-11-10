# User Login Watcher Plugin

Source and release channel for **Control Yours Connector**, the Control Yours WordPress plugin that connects a client site to the Admin Dashboard: it shows when each user last logged in / was last active in the admin, switches off the file editors, reports logins, and sends a site inventory on request. Plugin documentation (features, data stored, filters) is in [`cy-user-login-watcher/readme.txt`](cy-user-login-watcher/readme.txt).

> **The old name stays in the code on purpose.** The plugin was called CY User Login Watcher until 1.5.0. This repository, the `cy-user-login-watcher` folder and main file, the slug, the `cy_ulw_` options and `CY_ULW_` constants, the text domain, the `Update URI` and the `User-Agent` all keep the old name. WordPress identifies a plugin by its folder and file: renaming them would deactivate the plugin on every client site or leave two copies behind, and the self-updater could not move a site across. Only the name people see has changed. Do not "tidy up" the rest.

## Layout

```
cy-user-login-watcher/   the plugin (this folder is what gets zipped and installed)
update.json              update manifest read by client sites
bin/release.sh           release script
dist/                    built zips (not committed; attached to GitHub Releases)
```

## How client sites get updates

The plugin header declares `Update URI: https://github.com/Control-Yours/User-Login-Watcher-Plugin`. WordPress therefore skips wordpress.org for this plugin and, on its normal update schedule, calls the plugin's own updater, which:

1. reads `update.json` from the `main` branch (`raw.githubusercontent.com`, cached 6 h per site),
2. compares `version` to the installed version,
3. if newer, hands WordPress the `download_url` — the zip attached to the GitHub Release — and WordPress installs it like any other update (Plugins screen, Dashboard > Updates, auto-updates, WP-CLI).

The updater only accepts a `download_url` under `https://github.com/Control-Yours/User-Login-Watcher-Plugin/releases/download/`. Anyone with push access to this repo can push code to every client site, so keep the repo's collaborator list tight and enable 2FA.

## Cutting a release

1. Edit `cy-user-login-watcher/readme.txt`: add a `= X.Y.Z =` entry under `== Changelog ==` (and bump `Tested up to` if you tested on a newer WordPress).
2. Run

   ```sh
   bash bin/release.sh X.Y.Z --publish
   ```

   This bumps the version in the plugin header, `CY_ULW_VERSION` and `Stable tag`; lints; builds `dist/cy-user-login-watcher-X.Y.Z.zip`; commits and tags `vX.Y.Z`; pushes; creates the GitHub Release with the zip attached; then regenerates and pushes `update.json`. The manifest is pushed **after** the release exists so no site ever sees a version it cannot download.

   Without `--publish` it does everything locally and prints the git/gh commands instead.

Requirements: macOS/Linux with `git`, `zip`, `perl` (all preinstalled on macOS) and the [GitHub CLI](https://cli.github.com) logged in (`gh auth login`).

## First-time setup

```sh
cd ~/Documents/"User Watcher Plugin"
git init -b main
git add .
git commit -m "Initial commit"
gh repo create Control-Yours/User-Login-Watcher-Plugin --public --source=. --push
bash bin/release.sh 1.0.0 --publish
```

If the repo already exists, replace the `gh repo create` line with `git remote add origin git@github.com:Control-Yours/User-Login-Watcher-Plugin.git && git push -u origin main`.

## Testing an update on one site

Point a single site at a different manifest (e.g. a `staging` branch) by adding to its `wp-config.php`:

```php
define( 'CY_ULW_UPDATE_MANIFEST', 'https://raw.githubusercontent.com/Control-Yours/User-Login-Watcher-Plugin/staging/update.json' );
```

Then Dashboard > Updates > "Check again" forces a fresh manifest fetch.

## Site inventory payload

For the dashboard side (Control-Yours/Admin-Dashboard issue #123). The plugin sends an inventory only when asked: the dashboard answers an accepted daily snapshot (`POST /api/logins/intake`, 200 or 202) with a JSON object containing `"requests": ["inventory"]`, or an administrator presses the button under Settings > CY Connector. Other keys in that answer, and other values in `requests`, are ignored. The site keeps no record of having sent one: as long as the answer contains the request, the site sends a fresh inventory after each daily snapshot, so the dashboard should stop asking once it has a complete one.

**Request.** One inventory is a series of `POST <dashboard>/api/sites/inventory` requests, sent one after another from WP-cron:

- Headers: `Content-Type: application/json`, `X-CY-Site-Key: <the site's key>` (the same key as login reports), `User-Agent: CY-User-Login-Watcher/<version>; <home URL>`.
- Each body is under 1 MB (the plugin keeps to 900,000 bytes).
- **Answer with any 2xx** to accept a chunk; the body is not read. Any other status, a redirect included, makes the site stop: it sends no further chunks, shows the status on its settings screen and does not retry by itself. A site that has never been approved on the dashboard sends inventories too if asked, so reject or park those as you do logins.

**Envelope**, the same in every chunk:

```json
{
  "type": "inventory",
  "inventory_version": 1,
  "inventory_id": "21b5b404-2c37-469d-b928-c0396a23cc5b",
  "chunk": 1,
  "complete": false,
  "requested_by": "dashboard",
  "sent_at": 1794333947,
  "site": { "home_url": "https://example.com", "plugin_version": "1.5.0" },
  "data": { }
}
```

- `inventory_id` is a UUID shared by all chunks of one inventory. A new request gives a new ID.
- `chunk` counts from 1 with no gaps, in the order sent. Chunks of one inventory can be minutes apart: a large site pauses and continues in a later cron run.
- `complete` is `true` only on the last chunk, which has an empty `data` and adds `"totals": { "chunks": 9, "pages": 1009 }` (`chunks` includes the final one). An inventory whose final chunk never arrives is incomplete and should be discarded; the site does not resume it.
- `requested_by` is `"dashboard"` or `"button"`.
- `data` holds one or more sections. Treat sections as independent and merge by key: list sections (`pages`, `menus`) can be spread over several chunks and are concatenated in chunk order. Both are always sent at least once, as an empty list on a site with none. Today chunk 1 carries the single-value sections, then `menus`, then `pages` in batches of up to 200, but do not rely on which chunk a section arrives in.

**Sections in `data`:**

```json
{
  "site": {
    "name": "Example", "tagline": "Just another site", "home_url": "https://example.com",
    "language": "en_US", "permalink_structure": "/%postname%/",
    "logo_url": "https://example.com/wp-content/uploads/logo.png", "icon_url": null,
    "wp_version": "7.1.3", "php_version": "8.3.6", "multisite": false
  },
  "theme": {
    "name": "Example Child", "slug": "example-child", "version": "1.2.0",
    "parent": { "name": "Example", "slug": "example", "version": "3.0.1" }
  },
  "plugins": [
    { "slug": "woocommerce", "file": "woocommerce/woocommerce.php", "name": "WooCommerce", "version": "9.9.9", "active": true }
  ],
  "mu_plugins": [
    { "slug": "mu-plugin", "file": "mu-plugin.php", "name": "WP Engine System", "version": "6.4.0" }
  ],
  "post_types": [
    { "name": "page", "label": "Pages", "published": 42, "builtin": true },
    { "name": "project", "label": "Projects", "published": 17, "builtin": false }
  ],
  "media": { "count": 1280, "bytes": 734003200, "files_measured": 1275, "bytes_complete": true },
  "flags": {
    "woocommerce": true, "learning": false, "forum": false, "multilingual": false,
    "form_payments": false, "events": false, "blog": true, "blog_posts": 31,
    "matched": { "woocommerce": ["woocommerce"] }
  },
  "menus": [
    {
      "id": 2, "name": "Main Menu", "slug": "main-menu", "locations": ["primary"],
      "items": [
        { "id": 21, "label": "About", "url": "https://example.com/about/", "type": "post_type", "object": "page", "object_id": 5, "parent": 0 },
        { "id": 23, "label": "Shop", "url": "https://shop.example.com/", "type": "custom", "object": "custom", "object_id": 23, "parent": 0 }
      ]
    }
  ],
  "pages": [
    {
      "id": 6, "title": "Team", "slug": "team", "parent": 5, "menu_order": 1,
      "template": "templates/full-width.php", "url": "https://example.com/about/team/",
      "modified_gmt": "2026-11-10T18:04:37Z", "is_front_page": false, "is_posts_page": false,
      "builder": "elementor"
    }
  ]
}
```

Field notes:

- `site.logo_url`, `site.icon_url`, `theme.parent`, `pages[].url` and `pages[].modified_gmt` can be `null`. Titles, names and labels are plain text (tags removed, entities decoded), so escape them on output.
- `plugins[].slug` is the plugin's folder, or the file name without `.php` for a single-file plugin. `active` is true when the plugin is active on the site or across the network. `mu_plugins` has no `active`: must-use plugins always run.
- `post_types` lists public types except attachments. `published` is the count with status "publish".
- `media.count` is every item in the media library. `media.bytes` adds up each item's main file (for a very large image, the scaled copy WordPress serves as full size), not the smaller image sizes WordPress generates. `files_measured` is how many items a size was found for; `bytes_complete` is `false` when a very large library was cut off after 8 seconds, in which case `bytes` is a lower bound.
- `flags.*` are true when a known plugin of that kind is active; `matched` names the plugin folders that set each flag (an empty object when none). `blog` is true from six published posts; `blog_posts` is the count.
- `menus[].items[]`: `parent` is the `id` of another item in the same menu, 0 at the top level. `type` is `post_type`, `taxonomy`, `post_type_archive` or `custom`; `object` is what it points at (`page`, `category`, ...) and `object_id` that page's or term's ID (for a custom link, the item's own ID). `url` can be `null`. Items whose page or term no longer exists are left out, as WordPress leaves them out of the menu.
- `pages` are published pages only, in ascending `id` order. `parent` is a page `id`, 0 at the top level. `template` is the template file or `default`. `builder` is one of `elementor`, `wpbakery`, `divi`, `beaver-builder`, `blocks`, `classic`.
- Later versions will add sections (SEO fields, redirects, forms) under new keys, so ignore keys you do not know. `inventory_version` goes up only when an existing field changes meaning.

To try it without the real dashboard, define `CY_ULW_DASHBOARD_URL` in wp-config.php as `http://<host>:<port>/api/logins/intake`; the inventory then goes to `/api/sites/inventory` on that host. The constant must end in `/api/logins/intake`, or no inventory is sent. On a staging or local copy also define `CY_ULW_DASHBOARD_REPORTING` as `true`, or nothing is sent.
