# User Login Watcher Plugin

Source and release channel for **CY User Login Watcher**, a Control Yours WordPress plugin that shows when each user last logged in / was last active in the admin. Plugin documentation (features, data stored, filters) is in [`cy-user-login-watcher/readme.txt`](cy-user-login-watcher/readme.txt).

## Layout

```
cy-user-login-watcher/   the plugin (this folder is what gets zipped and installed)
update.json              update manifest read by client sites
bin/release.sh           release script
dist/                    built zips (not committed; attached to GitHub Releases)
```

## How client sites get updates

The plugin header declares `Update URI: https://github.com/rob460/User-Login-Watcher-Plugin`. WordPress therefore skips wordpress.org for this plugin and, on its normal update schedule, calls the plugin's own updater, which:

1. reads `update.json` from the `main` branch (`raw.githubusercontent.com`, cached 6 h per site),
2. compares `version` to the installed version,
3. if newer, hands WordPress the `download_url` — the zip attached to the GitHub Release — and WordPress installs it like any other update (Plugins screen, Dashboard > Updates, auto-updates, WP-CLI).

The updater only accepts a `download_url` under `https://github.com/rob460/User-Login-Watcher-Plugin/releases/download/`. Anyone with push access to this repo can push code to every client site, so keep the repo's collaborator list tight and enable 2FA.

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
gh repo create rob460/User-Login-Watcher-Plugin --public --source=. --push
bash bin/release.sh 1.0.0 --publish
```

If the repo already exists, replace the `gh repo create` line with `git remote add origin git@github.com:rob460/User-Login-Watcher-Plugin.git && git push -u origin main`.

## Testing an update on one site

Point a single site at a different manifest (e.g. a `staging` branch) by adding to its `wp-config.php`:

```php
define( 'CY_ULW_UPDATE_MANIFEST', 'https://raw.githubusercontent.com/rob460/User-Login-Watcher-Plugin/staging/update.json' );
```

Then Dashboard > Updates > "Check again" forces a fresh manifest fetch.
