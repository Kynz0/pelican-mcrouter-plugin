#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p .dev
if [[ ! -d .dev/mc-router/.git ]]; then
  git clone --depth 1 --branch v1.47.1 https://github.com/itzg/mc-router.git .dev/mc-router
fi
[[ "$(git -C .dev/mc-router rev-parse HEAD)" == 175aced3d182734bcefac2eead9fae34d83795d2 ]] || { echo 'Unexpected router revision'; exit 1; }
cd .dev/mc-router
go build -o ../mc-router-bin ./cmd/mc-router
