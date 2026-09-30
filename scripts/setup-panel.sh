#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
cd "$(dirname "$0")/.."
root="$PWD"
panel="$root/.dev/panel"
mkdir -p .dev
if [[ ! -d "$panel/.git" ]]; then
  git clone --depth 1 --branch v1.0.0-beta38 https://github.com/pelican-dev/panel.git "$panel"
fi
[[ "$(git -C "$panel" rev-parse HEAD)" == e84a4afd466e32f2b376774365185fe0342b73e6 ]] || { echo 'Unexpected Panel revision'; exit 1; }
cd "$panel"
composer install --no-interaction --prefer-dist --no-scripts
# Disposable local settings only. Never copy a production .env or database.
if [[ ! -f .env ]]; then
  umask 077
  printf '%s\n' 'APP_ENV=local' 'APP_KEY=' 'APP_DEBUG=false' 'APP_URL=http://127.0.0.1:8080' 'DB_CONNECTION=sqlite' 'DB_DATABASE=database.sqlite' 'CACHE_STORE=file' 'SESSION_DRIVER=file' 'QUEUE_CONNECTION=sync' 'MAIL_MAILER=log' > .env
  php artisan key:generate --force >/dev/null
fi
php artisan migrate --force
if [[ ! -d plugins/mc-router ]]; then
  php artisan p:plugin:make --name='MC Router' --author=kynzo --category=plugin --panels=admin --panelVersion=1.0.0-beta38 --description='Admin Minecraft routing integration' --url='' --updateUrl='' --no-interaction
fi
# Copy only the plugin, never alter Panel core. Generated skeleton is replaced by repo source.
rm -rf "$panel/plugins/mc-router"
mkdir -p plugins/mc-router
cp -R "$root/plugin/mc-router/." plugins/mc-router/
php artisan p:plugin:install mc-router --no-interaction
php artisan filament:assets
printf '\nDisposable Panel prepared. Create a local admin with: cd .dev/panel && php artisan p:user:make\n'
