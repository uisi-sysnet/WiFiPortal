#!/usr/bin/env bash
# Pull the latest code and apply it. Run on the server as the user that owns the code:
#
#   bash /var/www/wifiportal/deploy/deploy.sh
set -euo pipefail
umask 002                        # keep new files group-writable for www-data

APP_DIR=${APP_DIR:-/var/www/wifiportal}
PHP=${PHP:-8.3}
BRANCH=${BRANCH:-main}
artisan() { sudo -u www-data php artisan "$@"; }

cd "$APP_DIR"

artisan down --retry=15
trap 'artisan up' EXIT           # bring the site back even if a step fails

git fetch origin "$BRANCH"
git merge --ff-only "origin/$BRANCH"

composer install --no-dev --optimize-autoloader --no-interaction
artisan migrate --force
[[ -L public/storage ]] || artisan storage:link
artisan optimize                  # caches config, routes, views, events

artisan queue:restart             # workers finish their current job, then reload the new code
sudo systemctl reload "php$PHP-fpm"

echo "Deployed $(git rev-parse --short HEAD)."
