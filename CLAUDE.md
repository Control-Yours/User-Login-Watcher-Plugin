# Control Yours Connector (User-Login-Watcher-Plugin)

Read `README.md` first. It explains why the old name stays in the code and how releases reach
client sites.

## Releases reach every client site

This plugin updates itself on client sites from this repo. This repo is also public.

- Never run `bin/release.sh`, tag, publish a GitHub Release or change `update.json` unless the
  person you are working for asks for that release by version. Open a draft pull request and
  stop there.
- A release goes to the test sites first (`--staging`), and is promoted only on a person's
  say-so. Never use `--publish`.
- No secrets, keys, client names or client data in code, comments, commits or pull requests.

## Migration plan (WordPress to SiteForge)

The plan is `docs/WP-MIGRATION-PLAN.md` on the `dev` branch of SiteForge-ClientEditor. The
Status table at its top says where each work package (WP) stands, and "Tracking this work"
lists each WP's issue.

- A pull request that builds or changes part of the plan puts `Plan: WP<n>` on its own line in
  the description (for example `Plan: WP5`), says what it leaves undone for that WP, and links
  the WP's tracking issue.
- The Status table is kept current by a scheduled check each Monday, which reads those lines in
  merged pull requests and proposes the update. Don't edit the plan from this repo.
