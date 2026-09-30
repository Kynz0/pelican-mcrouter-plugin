#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
cd "$(dirname "$0")/.."
python3 scripts/package.py
php tests/import.php
php tests/panel.php
php tests/creation.php
python3 tests/router_api.py
python3 tests/sync_driver.py
python3 tests/ssh_driver.py
