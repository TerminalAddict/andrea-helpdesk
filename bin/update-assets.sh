#!/usr/bin/env bash
# Check npm registry for latest versions of vendored frontend libraries,
# update Makefile version variables, and re-download changed assets.
# Major version bumps are flagged but NOT auto-applied (they may break things).
set -euo pipefail

MAKEFILE="$(dirname "$0")/../Makefile"

# Fetch latest version string from npm registry
npm_latest() {
    curl -sf "https://registry.npmjs.org/$1/latest" \
        | python3 -c "import json,sys; print(json.load(sys.stdin)['version'])"
}

major() { echo "$1" | cut -d. -f1; }

# Read current versions from Makefile
current_bootstrap=$(grep '^BOOTSTRAP_VERSION ' "$MAKEFILE"       | awk -F'= ' '{print $2}' | tr -d ' ')
current_icons=$(grep '^BOOTSTRAP_ICONS_VERSION ' "$MAKEFILE"     | awk -F'= ' '{print $2}' | tr -d ' ')
current_jquery=$(grep '^JQUERY_VERSION ' "$MAKEFILE"             | awk -F'= ' '{print $2}' | tr -d ' ')

echo "Current versions:"
echo "  Bootstrap:       $current_bootstrap"
echo "  Bootstrap Icons: $current_icons"
echo "  jQuery:          $current_jquery"
echo ""
echo "Checking npm registry..."

latest_bootstrap=$(npm_latest "bootstrap")
latest_icons=$(npm_latest "bootstrap-icons")
latest_jquery=$(npm_latest "jquery")

echo "Latest versions:"
echo "  Bootstrap:       $latest_bootstrap"
echo "  Bootstrap Icons: $latest_icons"
echo "  jQuery:          $latest_jquery"
echo ""

updated=0
warned=0

bump() {
    local label="$1" current="$2" latest="$3" key="$4"
    if [ "$current" = "$latest" ]; then
        echo "  $label: up to date ($current)"
    elif [ "$(major "$current")" != "$(major "$latest")" ]; then
        echo "  $label: $current → $latest  *** MAJOR VERSION — skipped, review manually ***"
        warned=1
    else
        echo "  $label: $current → $latest"
        sed -i "s/^${key} *=.*/${key} = ${latest}/" "$MAKEFILE"
        updated=1
    fi
}

bump "Bootstrap      " "$current_bootstrap" "$latest_bootstrap" "BOOTSTRAP_VERSION"
bump "Bootstrap Icons" "$current_icons"     "$latest_icons"     "BOOTSTRAP_ICONS_VERSION"
bump "jQuery         " "$current_jquery"    "$latest_jquery"    "JQUERY_VERSION"

echo ""

if [ "$updated" -eq 1 ]; then
    echo "Downloading updated assets..."
    make -C "$(dirname "$0")/.." fetch-assets
    echo ""
    echo "Done. Commit and deploy when ready:"
    echo "  git add Makefile public_html/assets/vendor/"
    echo "  git commit -m 'Upgrade frontend libraries'"
    echo "  make deploy-production"
else
    echo "All libraries are up to date (no minor/patch updates available)."
fi

if [ "$warned" -eq 1 ]; then
    echo ""
    echo "NOTE: Major version upgrades were skipped above."
    echo "To upgrade manually: bump the version in Makefile, run make fetch-assets, test, then deploy."
fi
