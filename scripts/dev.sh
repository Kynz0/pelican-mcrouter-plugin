#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
root="${MC_ROUTER_WORKSPACE:-$PWD}"
cd "$root/.dev/panel"
[[ -f .env && -d vendor ]] || { echo 'Run nix develop -c bash scripts/setup-panel.sh first.'; exit 1; }
# This is always the disposable checkout, never /var/www/pelican.
# Panel forces asset origins from APP_URL; don't bake the initial 8080 into previews.
export APP_URL="${MC_ROUTER_PREVIEW_URL:-${PASEO_SERVICE_DEV_URL:-http://127.0.0.1:${PASEO_PORT:-8080}}}"
exec php -S "127.0.0.1:${PASEO_PORT:-8080}" -t "$root/.dev/panel/public" "$root/scripts/panel-router.php"
