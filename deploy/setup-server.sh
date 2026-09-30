#!/usr/bin/env bash
# One-time setup of an Ubuntu 24.04 server for the WiFi Portal.
#
#   sudo git clone https://github.com/uisi-sysnet/WiFiPortal.git /var/www/wifiportal
#   sudo chown -R "$USER" /var/www/wifiportal
#   sudo bash /var/www/wifiportal/deploy/setup-server.sh
#
# Safe to run again: existing .env, database and admin users are left alone.
set -euo pipefail
umask 002                        # keep new files group-writable for www-data

APP_DIR=${APP_DIR:-/var/www/wifiportal}
PHP=${PHP:-8.3}
DB_NAME=${DB_NAME:-wifiportal}
DB_USER=${DB_USER:-wifiportal}
OWNER=${SUDO_USER:-$(stat -c %U "$APP_DIR")}   # who runs git pull / deploy.sh

[[ $EUID -eq 0 ]] || { echo "Run with sudo." >&2; exit 1; }
[[ -f $APP_DIR/artisan ]] || { echo "Clone the repository to $APP_DIR first." >&2; exit 1; }

echo "==> Packages"
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    nginx postgresql git unzip curl ffmpeg snmp composer \
    "php$PHP-fpm" "php$PHP-cli" "php$PHP-pgsql" "php$PHP-mbstring" "php$PHP-xml" \
    "php$PHP-curl" "php$PHP-zip" "php$PHP-bcmath" "php$PHP-intl" "php$PHP-gd" "php$PHP-snmp"

echo "==> PostgreSQL database"
cd /tmp
DB_PASS=""
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1; then
    DB_PASS=$(openssl rand -hex 24)
    sudo -u postgres psql -c "CREATE ROLE $DB_USER LOGIN PASSWORD '$DB_PASS'"
fi
sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1 \
    || sudo -u postgres createdb -O "$DB_USER" "$DB_NAME"

echo "==> .env"
cd "$APP_DIR"
if [[ ! -f .env ]]; then
    cp .env.production.example .env
    sed -i "s/^DB_DATABASE=.*/DB_DATABASE=$DB_NAME/; s/^DB_USERNAME=.*/DB_USERNAME=$DB_USER/" .env
    if [[ -n $DB_PASS ]]; then
        sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$DB_PASS/" .env
    else
        echo "!! Role $DB_USER already existed: put its password in DB_PASSWORD in .env yourself."
    fi
fi

echo "==> File ownership ($OWNER owns the code, www-data writes storage/)"
chown -R "$OWNER:www-data" "$APP_DIR"
chmod 640 .env
chmod -R ug+rwX storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod g+s {} +

echo "==> Composer"
sudo -u "$OWNER" composer install --no-dev --optimize-autoloader --no-interaction

grep -q '^APP_KEY=base64:' .env || sudo -u www-data php artisan key:generate --force
sudo -u www-data php artisan migrate --force
[[ -L public/storage ]] || sudo -u www-data php artisan storage:link
sudo -u www-data php artisan optimize

echo "==> PHP-FPM"
install -m 644 deploy/php/99-wifiportal.ini "/etc/php/$PHP/fpm/conf.d/99-wifiportal.ini"
systemctl restart "php$PHP-fpm"

echo "==> nginx"
sed "s#/var/www/wifiportal#$APP_DIR#g; s#php8.3-fpm#php$PHP-fpm#g" deploy/nginx/wifiportal.conf \
    > /etc/nginx/sites-available/wifiportal
ln -sf /etc/nginx/sites-available/wifiportal /etc/nginx/sites-enabled/wifiportal
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

echo "==> Queue workers and scheduler"
sed "s#/var/www/wifiportal#$APP_DIR#g" deploy/systemd/wifiportal-queue@.service \
    > /etc/systemd/system/wifiportal-queue@.service
systemctl daemon-reload
systemctl enable --now wifiportal-queue@1 wifiportal-queue@2 wifiportal-queue@3
sed "s#/var/www/wifiportal#$APP_DIR#g" deploy/cron/wifiportal > /etc/cron.d/wifiportal
chmod 644 /etc/cron.d/wifiportal

cat <<EOF

Done. Next:
  1. Edit $APP_DIR/.env: APP_URL, PORTAL_URL (this server's address as phones and routers see it), RADIUS_*.
     Then: cd $APP_DIR && sudo -u www-data php artisan optimize
  2. Create an admin:  cd $APP_DIR && sudo -u www-data php artisan wifi:make-admin you@example.com --name="Network Admin"
  3. Open http://<server-ip>/login
EOF
