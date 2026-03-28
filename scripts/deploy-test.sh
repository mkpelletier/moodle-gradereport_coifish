#!/usr/bin/env bash
#
# Deploy the gradetracker plugin to the local MAMP Moodle instance
# and either purge caches or run the upgrade script depending on
# whether the version number has changed.
#
# Usage:  ./scripts/deploy-test.sh
#

set -euo pipefail

# ── Paths ──
PLUGIN_SRC="$(cd "$(dirname "$0")/.." && pwd)"
MOODLE_ROOT="/Applications/MAMP/htdocs/moodle"
PLUGIN_DEST="${MOODLE_ROOT}/grade/report/coifish"
PHP="/Applications/MAMP/bin/php/php8.3.30/bin/php"

# ── Colours ──
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No colour.

echo -e "${CYAN}=== Grade Tracker Deploy & Test ===${NC}"

# Sanity checks.
if [ ! -f "${PHP}" ]; then
    echo "ERROR: MAMP PHP not found at ${PHP}" >&2
    exit 1
fi
if [ ! -d "${MOODLE_ROOT}" ]; then
    echo "ERROR: Moodle root not found at ${MOODLE_ROOT}" >&2
    exit 1
fi

# ── Detect version change ──
OLD_VERSION=""
if [ -f "${PLUGIN_DEST}/version.php" ]; then
    OLD_VERSION=$("${PHP}" -r "
        define('MOODLE_INTERNAL', true);
        define('MATURITY_ALPHA', 50);
        define('MATURITY_BETA', 100);
        define('MATURITY_RC', 150);
        define('MATURITY_STABLE', 200);
        \$plugin = new stdClass();
        require '${PLUGIN_DEST}/version.php';
        echo \$plugin->version;
    ")
fi

NEW_VERSION=$("${PHP}" -r "
    define('MOODLE_INTERNAL', true);
    define('MATURITY_ALPHA', 50);
    define('MATURITY_BETA', 100);
    define('MATURITY_RC', 150);
    define('MATURITY_STABLE', 200);
    \$plugin = new stdClass();
    require '${PLUGIN_SRC}/version.php';
    echo \$plugin->version;
")

echo -e "  Source:      ${PLUGIN_SRC}"
echo -e "  Destination: ${PLUGIN_DEST}"
echo -e "  Old version: ${OLD_VERSION:-'(fresh install)'}"
echo -e "  New version: ${NEW_VERSION}"

# ── Sync files ──
echo -e "\n${YELLOW}Syncing plugin files...${NC}"
rsync -a --delete \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='scripts' \
    --exclude='node_modules' \
    --exclude='.DS_Store' \
    "${PLUGIN_SRC}/" "${PLUGIN_DEST}/"
echo -e "${GREEN}Files synced.${NC}"

# ── Rebuild AMD (JavaScript) if source files changed ──
# Compare build timestamps — if any src/*.js is newer than the newest build file, rebuild.
NEWEST_SRC=$(find "${PLUGIN_DEST}/amd/src" -name '*.js' -newer "${PLUGIN_DEST}/amd/build/progress.min.js" 2>/dev/null | head -1)
if [ -n "${NEWEST_SRC}" ] || [ ! -f "${PLUGIN_DEST}/amd/build/progress.min.js" ]; then
    echo -e "\n${YELLOW}JS source changed — rebuilding AMD modules...${NC}"
    (cd "${MOODLE_ROOT}" && npx grunt amd --root=grade/report/coifish)
    # Copy the freshly built files back to the source repo so they stay in sync.
    cp "${PLUGIN_DEST}/amd/build/"*.js "${PLUGIN_SRC}/amd/build/"
    echo -e "${GREEN}AMD build complete.${NC}"
else
    echo -e "\n${GREEN}AMD build files are up to date.${NC}"
fi

# ── Upgrade or purge caches ──
if [ "${OLD_VERSION}" != "${NEW_VERSION}" ]; then
    echo -e "\n${YELLOW}Version changed — running Moodle upgrade...${NC}"
    "${PHP}" "${MOODLE_ROOT}/admin/cli/upgrade.php" --non-interactive
    echo -e "${GREEN}Upgrade complete.${NC}"
else
    echo -e "\n${YELLOW}Version unchanged — purging caches...${NC}"
    "${PHP}" "${MOODLE_ROOT}/admin/cli/purge_caches.php"
    echo -e "${GREEN}Caches purged.${NC}"
fi

echo -e "\n${GREEN}=== Deploy complete ===${NC}"
echo -e "Open: ${CYAN}http://localhost:8888/moodle/grade/report/coifish/index.php${NC}"
