#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
root="${MC_ROUTER_SOURCE:-$(cd "$(dirname "$0")/.." && pwd)}"
find "$root/plugin" "$root/tests" "$root/scripts" -name '*.php' ! -name '*.blade.php' -print0 | xargs -0 -n1 php -l
python3 "$root/scripts/check_docs.py"
