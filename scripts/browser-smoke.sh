#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p .dev/browser
cp tests/browser-harness/package.json tests/browser-harness/package-lock.json .dev/browser/
npm ci --prefix .dev/browser --no-audit --no-fund
php tests/browser-account.php
trap 'php tests/browser-account.php --cleanup' EXIT
node tests/browser.mjs
