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

Which sites get a release is decided by one file. Client sites read `update.json` from the `main` branch. Test sites read it from the `staging` branch (see "Test sites" below). The tag, the GitHub Release and the version bump on `main` reach nobody by themselves.

1. Edit `cy-user-login-watcher/readme.txt`: add a `= X.Y.Z =` entry under `== Changelog ==` (and bump `Tested up to` if you tested on a newer WordPress). Commit it to `main`.
2. Release to the test sites only:

   ```sh
   bash bin/release.sh X.Y.Z --staging
   ```

   This bumps the version in the plugin header, `CY_ULW_VERSION` and `Stable tag`; lints; builds `dist/cy-user-login-watcher-X.Y.Z.zip`; commits and tags `vX.Y.Z`; pushes; creates the GitHub Release with the zip attached; then puts the new manifest on the `staging` branch. `main`'s `update.json` is not touched, so every other site stays on the version it has.
3. Check the test sites. They pick the release up on their next update check, or at once with Dashboard > Updates > "Check again".
4. Give it to everyone:

   ```sh
   bash bin/release.sh X.Y.Z --promote
   ```

   This publishes the manifest on `main` and nothing else. It refuses if the plugin on `main` has changed since the tag, so what everyone gets is what was tested.

If the test sites show a problem, do not promote. Fix it, add the next version's changelog entry, and run `--staging` with that version: the test sites move on to it, and sites on `main` skip the bad version altogether.

`bash bin/release.sh X.Y.Z --publish` does steps 2 and 4 in one go, for a release that needs no trial. In every mode the manifest is pushed **after** the GitHub Release exists, so no site ever sees a version it cannot download. Without an option the script only builds locally and commits nothing.

The `staging` branch is rebuilt by every `--staging` run (it is `main` plus the new manifest, force-pushed). Never merge it into anything or commit to it by hand.

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

## Test sites

A site becomes a test site when its `wp-config.php` points it at the staging manifest:

```php
define( 'CY_ULW_UPDATE_MANIFEST', 'https://raw.githubusercontent.com/Control-Yours/User-Login-Watcher-Plugin/staging/update.json' );
```

It then follows `--staging` releases instead of `main`. Dashboard > Updates > "Check again" forces a fresh manifest fetch. Keep the line in place on the few sites used for trials; remove it to put a site back on the normal channel.

The `staging` branch does not exist until the first `--staging` release. Until then a site with this line finds no manifest, keeps its current version, and updates nothing.

## Site inventory payload

For the dashboard side (Control-Yours/Admin-Dashboard issue #123). The plugin sends an inventory only when asked: the dashboard answers an accepted daily snapshot (`POST /api/logins/intake`, 200 or 202) with a JSON object containing `"requests": ["inventory"]`, an administrator presses the button under Settings > CY Connector, or (since 1.7.1) staff ask from WP-CLI on the server. Other keys in that answer, and other values in `requests`, are ignored. The site keeps no record of having sent one: as long as the answer contains the request, the site sends a fresh inventory after each daily snapshot, so the dashboard should stop asking once it has a complete one.

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
- `requested_by` is `"dashboard"`, `"button"` or, since 1.7.1, `"cli"`: asked for from WP-CLI on the server, by a person or a script working there (`CY_ULW_Inventory::request( 'cli' )` after `cy_ulw_load_inventory()`). The plugin only records `cli` inside a WP-CLI run. Show a value you do not know as it is, or as no source.
- `data` holds one or more sections. Treat sections as independent and merge by key: list sections (`pages`, `menus`, and since 1.7.0 `posts`, `redirects` and `forms`) can be spread over several chunks and are concatenated in chunk order. Each is always sent at least once, as an empty list on a site with none. Today chunk 1 carries the single-value sections, then `menus`, then `pages` in batches of up to 200, then `posts`, `redirects` (followed by `redirect_sources`) and `forms`, but do not rely on which chunk a section arrives in. A site on a plugin older than 1.7.0 sends none of the newer sections: tell "none" from "not sent" by `site.plugin_version` in the envelope.
- Since 1.7.0 the last chunk's `totals` also has `posts`, `redirects` and `forms` (how many of each were sent), and its `data` is no longer always empty: when part of the inventory could not be read it carries `"notes": ["Forms from wpforms could not be read: ..."]`, plain sentences for a person to read. The rest of the inventory is complete in that case.

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
      "builder": "elementor",
      "seo": { "title": "Our team %%sep%% %%sitename%%", "description": "Meet the people behind Example.", "focus_keyword": "team" }
    }
  ],
  "posts": [
    {
      "id": 912, "type": "post", "title": "Spring news", "slug": "spring-news", "parent": 0,
      "url": "https://example.com/2026/04/spring-news/",
      "date_gmt": "2026-04-02T15:00:00Z", "modified_gmt": "2026-04-03T09:12:44Z",
      "language": null, "seo": null
    }
  ],
  "redirects": [
    { "source": "/old-page/", "match": "exact", "target": "/new-page/", "code": 301, "enabled": true, "origin": "redirection" },
    { "source": "^/news/(.*)$", "match": "regex", "target": "/blog/$1", "code": 301, "enabled": true, "origin": "redirection" }
  ],
  "redirect_sources": [
    { "origin": "redirection", "listed": 2, "skipped": 0, "complete": true }
  ],
  "forms": [
    {
      "plugin": "gravityforms", "id": "1", "title": "Contact", "active": true,
      "fields": [
        { "label": "Name", "type": "name", "required": true },
        { "label": "Email", "type": "email", "required": true }
      ],
      "notifications": [
        { "name": "Admin Notification", "active": true, "to": ["office@example.com"] },
        { "name": "User Notification", "active": false, "to": ["{field:2}"] }
      ],
      "embedded_on": [ { "id": 13, "type": "page" } ]
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
- `pages` are published pages only, in ascending `id` order. `parent` is a page `id`, 0 at the top level. `template` is the template file or `default`. `builder` is one of `elementor`, `wpbakery`, `divi`, `beaver-builder`, `avada`, `oxygen`, `thrive`, `siteorigin`, `kingcomposer`, `bold-builder`, `goodlayers`, `blocks`, `classic` (the seven from `avada` to `goodlayers` since 1.5.1; before that such pages were reported as `classic`). More names can follow, so show an unknown one as it is.
- Languages (since 1.5.2). On a WPML site `site.languages` is `{"default": "en", "active": ["en", "es"]}`, and every page and menu has `language`, its WPML language code. `pages` then holds each language's version of a page as its own entry, with the address it really has in that language (`/es/acerca-de/`), and `is_front_page` / `is_posts_page` are true for that language's front page and posts page. A menu's `locations` include the locations of the menu it is a translation of. On a site with one language `site.languages` and every `language` are `null`. Before 1.5.2 a WPML site's translated menus arrived as copies of the default-language ones, and translated pages with the default-language page's address.
- SEO fields (since 1.7.0). `site.seo_plugin` is `yoast`, `rank-math`, `seopress` or `null`: the first of those that is active, and the only one whose fields are read (fields left behind by a plugin that was switched off are not sent, since the site no longer shows them). Every page and post has `seo`: `null` when none of the three fields is filled in, otherwise `title`, `description` and `focus_keyword`, each a string or `null`. They are sent as the SEO plugin stores them, so a title can hold that plugin's placeholders (`%%title%% %%sep%% %%sitename%%` in Yoast, `%sep% %sitename%` in Rank Math); a page with no title of its own uses the plugin's site-wide template, which is a setting and is not sent. `focus_keyword` can be several, separated by commas. Each value is cut at 600 characters.
- `posts` (since 1.7.0): published items of every public content type whose items have their own address on the site (`publicly_queryable`), except pages and media: blog posts and custom post types. A type listed in `post_types` that is public but has no addresses is not listed here. For each type the newest 500 at most (highest ID first); compare with that type's `published` in `post_types` to see whether a type was cut off. `type` is the post type. `parent` is 0 except for a hierarchical type. `url`, `date_gmt` and `modified_gmt` can be `null`. `language` and `seo` are as for pages. No content, excerpt, author or term is sent. Types a builder registers as public (`elementor_library` and the like) are listed too: filter by `type`.
- `redirects` (since 1.7.0): rules from the Redirection plugin, Yoast SEO Premium, Rank Math and SEOPress, each read only while its plugin is active, 5,000 at most in all. `source` is the address or pattern as that plugin stores it (Redirection with a leading slash, Yoast SEO Premium without). `match` is `exact`, `regex`, `contains`, `start` or `end`. `target` is `null` for a rule that only answers with a status (410). `enabled` is false for a rule that is switched off, for a Redirection rule in a group that is switched off, and for every Rank Math rule while Rank Math's Redirections module is off. `origin` is `redirection`, `yoast-premium`, `rank-math` or `seopress`. Addresses are the site's own as stored, so on a copy they can point at another host.
- `redirect_sources` (since 1.7.0): one entry per redirect source in use on the site, in the order read: how many rules were `listed`, how many were `skipped` (Redirection rules that depend on login state, referrer or browser, or that answer with an error or pass the request through; a rule whose data could not be read), and `complete`, false when the 5,000 cap cut the source off or it could not be read (then `notes` says why). An empty list means the site has no redirect plugin this version reads. Redirects made in `.htaccess`, by the host or by another plugin are not seen.
- `forms` (since 1.7.0): forms from Gravity Forms, Formidable, WPForms, Contact Form 7 and Elementor Pro / PRO Elements, each read only while its plugin is active, 200 per plugin at most. `plugin` is `gravityforms`, `formidable`, `wpforms`, `contact-form-7` or `elementor`. `id` is the form's ID in that plugin, always a string; for Elementor it is the form widget's ID, which is unique within its page only. `active` is false for a Gravity Forms form that is switched off or a Formidable draft. `fields` lists each field's `label`, the plugin's own `type` name and `required`, 200 at most; layout fields (HTML, section, page break) are included. Contact Form 7 fields have no label, so `label` is the field's name (`your-email`). For Elementor `fields` is `null` when the form still has Elementor's default Name, Email and Message fields, which Elementor does not store. `notifications` are the emails the form sends: `name`, `active`, and `to`, a list of recipients. Only the To line is read: anyone who gets the email by CC or BCC is not listed. The site's admin address is filled in where the form uses a placeholder for it; any other placeholder is left as written (`[your-email]`, or `{field:2}` for a Gravity Forms notification sent to the address typed into field 2), so an entry without an `@` is not a fixed address. A Gravity Forms notification that picks its recipient by an answer lists every address it can go to. `embedded_on` names the published posts (`id` and post `type`) whose content, or whose Elementor layout, holds the form's shortcode or block, 100 at most; a form placed by a sidebar widget, a theme file or a pop-up plugin shows an empty list. Form entries are never read.
- Later versions can add sections under new keys, so ignore keys you do not know. `inventory_version` goes up only when an existing field changes meaning.

To try it without the real dashboard, define `CY_ULW_DASHBOARD_URL` in wp-config.php as `http://<host>:<port>/api/logins/intake`; the inventory then goes to `/api/sites/inventory` on that host. The constant must end in `/api/logins/intake`, or no inventory is sent. On a staging or local copy also define `CY_ULW_DASHBOARD_REPORTING` as `true`, or nothing is sent.
