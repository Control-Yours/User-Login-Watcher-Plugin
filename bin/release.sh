#!/usr/bin/env bash
#
# Cut a release of Control Yours Connector (folder and slug stay cy-user-login-watcher).
#
#   bash bin/release.sh <version>            bump, build zip, regenerate update.json (no git)
#   bash bin/release.sh <version> --staging  release to the test sites only: commit, tag, push,
#                                       create the GitHub Release with the zip, and put the
#                                       manifest on the "staging" branch. main's update.json
#                                       is not touched, so no other site sees the version.
#   bash bin/release.sh <version> --promote  give everyone a version already released with
#                                       --staging: publish its manifest on main
#   bash bin/release.sh <version> --publish  release to every site in one go (--staging and
#                                       --promote together, without the staging branch)
#
# Before running: add a "= <version> =" entry to cy-user-login-watcher/readme.txt
# under "== Changelog ==". The script refuses to release without one.
#
# Which sites get a release is decided by one file: client sites read update.json
# from the main branch, test sites (CY_ULW_UPDATE_MANIFEST in their wp-config.php)
# read it from the staging branch. The tag, the GitHub Release and the version
# bump on main reach nobody by themselves.
#
# Order of operations matters: the GitHub Release (with the zip) is created
# BEFORE any update.json is pushed, so no site ever sees a version whose
# package does not exist yet.
#
# A version that turns out wrong on the test sites is never promoted: fix it,
# release the next version with --staging (the test sites move on to it), and
# promote that one. Sites on main then skip the bad version altogether.
#
# Requirements: bash, git, zip, perl (all ship with macOS); gh (GitHub CLI) for --publish.

set -euo pipefail

SLUG="cy-user-login-watcher"
REPO="Control-Yours/User-Login-Watcher-Plugin"
BRANCH="main"
STAGING_BRANCH="staging"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="$ROOT/$SLUG"
MAIN="$PLUGIN_DIR/$SLUG.php"
README="$PLUGIN_DIR/readme.txt"
MANIFEST="$ROOT/update.json"
DIST="$ROOT/dist"

VERSION="${1:-}"
MODE="${2:-}"
ALREADY_RELEASED=0

die() { printf 'error: %s\n' "$*" >&2; exit 1; }

# --- checks -----------------------------------------------------------------

[ -n "$VERSION" ] || die "usage: bash bin/release.sh <version> [--staging | --promote | --publish]"
case "$MODE" in
  ""|--staging|--promote|--publish) ;;
  *) die "unknown option '$MODE' (expected --staging, --promote or --publish)" ;;
esac
printf '%s' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+(\.[0-9]+)?$' || die "version must look like 1.2.3 (got '$VERSION')"
[ -f "$MAIN" ] || die "plugin main file not found at $MAIN"
[ -f "$README" ] || die "readme.txt not found at $README"
command -v zip >/dev/null || die "zip is required"
command -v perl >/dev/null || die "perl is required"

grep -Eq "^= ${VERSION//./\\.} =\$" "$README" || die "readme.txt has no changelog entry '= $VERSION ='. Add it under == Changelog == first."

if [ -n "$MODE" ]; then
  command -v git >/dev/null || die "git is required for $MODE"
  command -v gh >/dev/null || die "GitHub CLI (gh) is required for $MODE: https://cli.github.com"
  git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "$ROOT is not a git repository"
  gh auth status >/dev/null 2>&1 || die "gh is not logged in (run: gh auth login)"
  CURRENT_BRANCH="$(git -C "$ROOT" rev-parse --abbrev-ref HEAD)"
  [ "$CURRENT_BRANCH" = "$BRANCH" ] || die "switch to the '$BRANCH' branch first (on '$CURRENT_BRANCH')"

  # A version that already has its tag is one of two things: released to the
  # test sites and waiting to be promoted, or a --staging run that stopped
  # half way. Either way nothing is rebuilt: it must be exactly what was tagged.
  if git -C "$ROOT" rev-parse "v$VERSION" >/dev/null 2>&1; then
    [ "$MODE" != "--publish" ] || die "tag v$VERSION already exists; to give it to everyone use: bash bin/release.sh $VERSION --promote"
    git -C "$ROOT" fetch -q origin "$BRANCH" --tags
    gh release view "v$VERSION" --repo "$REPO" >/dev/null 2>&1 || die "tag v$VERSION exists but its GitHub Release does not; create the release by hand or use a new version"
    grep -q "define( 'CY_ULW_VERSION', '$VERSION' );" "$MAIN" || die "the plugin on '$BRANCH' is not at $VERSION; only the latest released version can be promoted"
    git -C "$ROOT" diff --quiet "v$VERSION" HEAD -- "$SLUG" || die "the plugin on '$BRANCH' has changed since v$VERSION was tagged; release a new version with --staging instead"
    [ "$(git -C "$ROOT" rev-parse HEAD)" = "$(git -C "$ROOT" rev-parse "origin/$BRANCH")" ] || die "local '$BRANCH' differs from origin/$BRANCH; pull or push first"
    ALREADY_RELEASED=1
  elif [ "$MODE" = "--promote" ]; then
    die "tag v$VERSION does not exist; release it first with: bash bin/release.sh $VERSION --staging"
  fi
fi

# --- bump version -----------------------------------------------------------

perl -pi -e "s/^( \\* Version:\\s+)[0-9.]+/\${1}$VERSION/" "$MAIN"
perl -pi -e "s/(define\\( 'CY_ULW_VERSION', ')[0-9.]+(' \\);)/\${1}$VERSION\${2}/" "$MAIN"
perl -pi -e "s/^(Stable tag:\\s+)[0-9.]+/\${1}$VERSION/" "$README"

grep -q "Version:           $VERSION" "$MAIN" || die "failed to bump header version in $MAIN"
grep -q "define( 'CY_ULW_VERSION', '$VERSION' );" "$MAIN" || die "failed to bump CY_ULW_VERSION in $MAIN"
grep -q "Stable tag: $VERSION" "$README" || die "failed to bump Stable tag in $README"

php_ok=1
if command -v php >/dev/null; then
  for f in "$MAIN" "$PLUGIN_DIR"/includes/*.php "$PLUGIN_DIR/uninstall.php"; do
    php -l "$f" >/dev/null || php_ok=0
  done
  [ "$php_ok" = 1 ] || die "PHP syntax check failed"
fi

# --- build zip --------------------------------------------------------------

mkdir -p "$DIST"
ZIP="$DIST/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$ROOT" && zip -r -X -q "$ZIP" "$SLUG" -x "*.DS_Store" -x "__MACOSX/*" -x "*/.git/*" )
[ -s "$ZIP" ] || die "zip was not created"

# --- regenerate update.json -------------------------------------------------

PACKAGE_URL="https://github.com/$REPO/releases/download/v$VERSION/$SLUG-$VERSION.zip"
TODAY="$(date -u +%Y-%m-%d)"

perl - "$README" "$MANIFEST" "$VERSION" "$PACKAGE_URL" "$REPO" "$TODAY" <<'PERL'
use strict; use warnings;
use JSON::PP;

my ($readme_path, $manifest_path, $version, $package, $repo, $today) = @ARGV;

open my $fh, '<', $readme_path or die "cannot read $readme_path: $!";
my @lines = <$fh>; close $fh; chomp @lines;

my %header;
for (@lines) {
  if (/^(Requires at least|Tested up to|Requires PHP):\s*(.+?)\s*$/) { $header{$1} = $2; }
}

# Split readme into sections keyed by "== Title ==".
my (%sections, $current);
my $short_desc = '';
for my $line (@lines) {
  if ($line =~ /^== (.+?) ==\s*$/) { $current = $1; $sections{$current} = []; next; }
  if (!defined $current) {
    # Before the first "== Section ==": skip the "=== Name ===" title and
    # "Key: value" header lines; whatever text remains is the short description.
    next if $line =~ /^=== /;
    next if $line =~ /^[A-Za-z][A-Za-z ]*:\s/;
    $short_desc .= "$line " if $line =~ /\S/;
    next;
  }
  push @{ $sections{$current} }, $line;
}
$short_desc =~ s/\s+$//;

sub inline {
  my $t = shift;
  $t =~ s/&/&amp;/g; $t =~ s/</&lt;/g; $t =~ s/>/&gt;/g;
  $t =~ s/`([^`]+)`/<code>$1<\/code>/g;
  $t =~ s/\*\*([^*]+)\*\*/<strong>$1<\/strong>/g;
  return $t;
}

sub to_html {
  my @src = @_;
  my ($html, $in_list) = ('', 0);
  for my $l (@src) {
    if ($l =~ /^= (.+?) =\s*$/) {
      $html .= "</ul>\n" if $in_list; $in_list = 0;
      $html .= "<h4>" . inline($1) . "</h4>\n"; next;
    }
    if ($l =~ /^\* (.+)$/) {
      $html .= "<ul>\n" unless $in_list; $in_list = 1;
      $html .= "<li>" . inline($1) . "</li>\n"; next;
    }
    $html .= "</ul>\n" if $in_list; $in_list = 0;
    next unless $l =~ /\S/;
    $html .= "<p>" . inline($l) . "</p>\n";
  }
  $html .= "</ul>\n" if $in_list;
  $html =~ s/\n$//;
  return $html;
}

my $description = ($short_desc ne '' ? "<p>" . inline($short_desc) . "</p>\n" : '') . to_html(@{ $sections{'Description'} || [] });
my $changelog   = to_html(@{ $sections{'Changelog'} || [] });

# Keep any manually-maintained fields from the existing manifest.
my $existing = {};
if (-f $manifest_path) {
  open my $mf, '<', $manifest_path or die $!;
  local $/; my $raw = <$mf>; close $mf;
  eval { $existing = JSON::PP->new->decode($raw) } or $existing = {};
}

my $out = {
  name            => $existing->{name} || 'Control Yours Connector',
  slug            => 'cy-user-login-watcher',
  version         => $version,
  requires        => $header{'Requires at least'} || '',
  tested          => $header{'Tested up to'} || '',
  requires_php    => $header{'Requires PHP'} || '',
  last_updated    => $today,
  homepage        => "https://github.com/$repo",
  download_url    => $package,
  author          => $existing->{author} || 'Control Yours',
  author_homepage => $existing->{author_homepage} || 'https://controlyours.com',
  upgrade_notice  => '',
  sections        => { description => $description, changelog => $changelog },
};

my $json = JSON::PP->new->pretty->canonical->indent_length(4);
open my $out_fh, '>', $manifest_path or die "cannot write $manifest_path: $!";
print {$out_fh} $json->encode($out);
close $out_fh;
PERL

# Release notes = this version's changelog entry, as plain text.
NOTES="$(awk -v v="= $VERSION =" '$0==v{f=1;next} /^= /{f=0} f' "$README" | sed '/^[[:space:]]*$/d')"

printf '\nBuilt %s\n' "$ZIP"
printf 'Updated %s -> version %s, package %s\n' "$MANIFEST" "$VERSION" "$PACKAGE_URL"

# --- publish ----------------------------------------------------------------

if [ -z "$MODE" ]; then
  cat <<EOF

Nothing has been committed. To release to the test sites first:

  bash bin/release.sh $VERSION --staging

and, once they look right, to everyone:

  bash bin/release.sh $VERSION --promote

Or to every site in one go:

  bash bin/release.sh $VERSION --publish
EOF
  exit 0
fi

cd "$ROOT"

# Set the new manifest aside and put back the one that is live, so the version
# bump is committed without it and main never carries a manifest by accident.
NEW_MANIFEST="$(mktemp)"
cp "$MANIFEST" "$NEW_MANIFEST"
git checkout -q -- update.json

if [ "$ALREADY_RELEASED" = 0 ]; then
  # 1. Commit the version bump (everything except the manifest) and tag it.
  #    Neither reaches a site: sites only act on update.json.
  git add "$SLUG"
  if git diff --cached --quiet; then
    printf 'Plugin files already at v%s; nothing to commit for the bump.\n' "$VERSION"
  else
    git commit -q -m "Release v$VERSION"
  fi
  git tag "v$VERSION"
  git push -q origin "$BRANCH" --tags

  # 2. Create the GitHub Release with the zip attached. Sites download this.
  gh release create "v$VERSION" "$ZIP" --repo "$REPO" --title "v$VERSION" --notes "${NOTES:-Release v$VERSION}"
fi

if [ "$MODE" = "--staging" ]; then
  # 3. Advertise the new version to the test sites only: the staging branch
  #    becomes main as it is now plus the new manifest. It is rebuilt on every
  #    staging release, so it is force-pushed and never merged anywhere.
  STAGING_TREE="$(mktemp -d)"
  git worktree add -q --detach "$STAGING_TREE" HEAD
  cp "$NEW_MANIFEST" "$STAGING_TREE/update.json"
  rm -f "$NEW_MANIFEST"
  git -C "$STAGING_TREE" add update.json
  git -C "$STAGING_TREE" commit -q -m "Staging manifest for v$VERSION"
  git -C "$STAGING_TREE" push -q --force origin "HEAD:refs/heads/$STAGING_BRANCH"
  git worktree remove --force "$STAGING_TREE"

  printf '\nReleased v%s to the test sites only.\n' "$VERSION"
  printf 'Staging manifest: https://raw.githubusercontent.com/%s/%s/update.json\n' "$REPO" "$STAGING_BRANCH"
  printf 'Release:          https://github.com/%s/releases/tag/v%s\n' "$REPO" "$VERSION"
  printf '\nA site is a test site when its wp-config.php has:\n\n'
  printf "  define( 'CY_ULW_UPDATE_MANIFEST', 'https://raw.githubusercontent.com/%s/%s/update.json' );\n\n" "$REPO" "$STAGING_BRANCH"
  printf 'Test sites pick it up on their next update check (cron twice daily, or Dashboard > Updates > "Check again").\n'
  printf 'Every other site stays where it is. When the test sites look right:\n\n  bash bin/release.sh %s --promote\n' "$VERSION"
  exit 0
fi

cp "$NEW_MANIFEST" "$MANIFEST"
rm -f "$NEW_MANIFEST"

# 3. Only now advertise the new version to every client site.
git add update.json
if git diff --cached --quiet; then
  printf 'update.json unchanged; manifest already published.\n'
else
  git commit -q -m "Publish v$VERSION manifest"
  git push -q origin "$BRANCH"
fi

printf '\nPublished v%s to every site.\n' "$VERSION"
printf 'Manifest: https://raw.githubusercontent.com/%s/%s/update.json\n' "$REPO" "$BRANCH"
printf 'Release:  https://github.com/%s/releases/tag/v%s\n' "$REPO" "$VERSION"
printf 'Client sites pick it up on their next update check (cron twice daily, or Dashboard > Updates > "Check again").\n'
