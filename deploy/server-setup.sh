#!/usr/bin/env bash
# One-time server setup for the retail-v2 backoffice on Ubuntu 24.04 LTS (see docs/deploy.md, "1. Server").
# Run as root on a fresh server:  sudo DOMAIN=portal.example.co.uk bash deploy/server-setup.sh
#
# It installs PHP 8.4 (+ extensions), Nginx, MySQL 8, Node 22, Composer, Supervisor, Certbot and (optionally) Redis,
# creates the `retail` app user and /var/www/retail-v2/{releases,shared}, and copies the config files from deploy/.
# It does NOT create the database password, the .env, or the TLS certificate: those are manual steps in the runbook.
set -euo pipefail

DOMAIN="${DOMAIN:?set DOMAIN=portal.example.co.uk}"
APP_USER="${APP_USER:-retail}"
APP_ROOT="${APP_ROOT:-/var/www/retail-v2}"
INSTALL_REDIS="${INSTALL_REDIS:-no}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

export DEBIAN_FRONTEND=noninteractive

echo "==> Packages"
apt-get update
apt-get install -y software-properties-common ca-certificates curl gnupg unzip git acl ufw
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-sqlite3 php8.4-bcmath php8.4-intl php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-gd php8.4-opcache php8.4-readline \
  nginx mysql-server supervisor certbot python3-certbot-nginx logrotate
# sodium is built into PHP 8.4 on Ubuntu (php -m | grep sodium).

if [ "$INSTALL_REDIS" = "yes" ]; then
  apt-get install -y redis-server php8.4-redis
  systemctl enable --now redis-server
fi

echo "==> Node 22 (builds the Vite assets during deploy)"
if ! command -v node >/dev/null || ! node -v | grep -q '^v22'; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y nodejs
fi

echo "==> Composer"
if ! command -v composer >/dev/null; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm /tmp/composer-setup.php
fi

echo "==> App user and folders"
id "$APP_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "" "$APP_USER"
usermod -aG "$APP_USER" www-data   # nginx reads public/ through the group
mkdir -p "$APP_ROOT"/{releases,shared/storage} /var/backups/retail-v2 /var/log/retail-v2
mkdir -p "$APP_ROOT"/shared/storage/{app/private,app/public,framework/cache/data,framework/sessions,framework/views,logs}
chown -R "$APP_USER:$APP_USER" "$APP_ROOT" /var/backups/retail-v2 /var/log/retail-v2
chmod 750 "$APP_ROOT" /var/backups/retail-v2

echo "==> PHP-FPM pool (runs as $APP_USER)"
sed "s/__APP_USER__/$APP_USER/g" "$HERE/php-fpm-pool.conf" > /etc/php/8.4/fpm/pool.d/retail-v2.conf
cp "$HERE/php-production.ini" /etc/php/8.4/fpm/conf.d/99-retail-v2.ini
cp "$HERE/php-production.ini" /etc/php/8.4/cli/conf.d/99-retail-v2.ini
systemctl restart php8.4-fpm

echo "==> Nginx"
sed -e "s/__DOMAIN__/$DOMAIN/g" -e "s#__APP_ROOT__#$APP_ROOT#g" "$HERE/nginx/retail-v2.conf" > /etc/nginx/sites-available/retail-v2.conf
ln -sfn /etc/nginx/sites-available/retail-v2.conf /etc/nginx/sites-enabled/retail-v2.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "==> Supervisor (queue workers), cron (scheduler, backups), logrotate"
sed -e "s/__APP_USER__/$APP_USER/g" -e "s#__APP_ROOT__#$APP_ROOT#g" "$HERE/supervisor/retail-v2-worker.conf" > /etc/supervisor/conf.d/retail-v2-worker.conf
sed -e "s/__APP_USER__/$APP_USER/g" -e "s#__APP_ROOT__#$APP_ROOT#g" "$HERE/cron/retail-v2" > /etc/cron.d/retail-v2
chmod 644 /etc/cron.d/retail-v2
sed -e "s/__APP_USER__/$APP_USER/g" -e "s#__APP_ROOT__#$APP_ROOT#g" "$HERE/logrotate/retail-v2" > /etc/logrotate.d/retail-v2
install -m 750 -o "$APP_USER" -g "$APP_USER" "$HERE/backup-db.sh" /usr/local/bin/retail-v2-backup-db
supervisorctl reread || true   # workers start after the first deploy (no current/ yet)

echo "==> sudo rule: the app user may reload PHP-FPM and control its own workers (deploy.sh, rollback.sh)"
cat > /etc/sudoers.d/retail-v2 <<SUDO
$APP_USER ALL=(root) NOPASSWD: /bin/systemctl reload php8.4-fpm, /usr/bin/supervisorctl start retail-v2-worker\:*, /usr/bin/supervisorctl stop retail-v2-worker\:*, /usr/bin/supervisorctl status
SUDO
chmod 440 /etc/sudoers.d/retail-v2
visudo -cf /etc/sudoers.d/retail-v2

echo "==> Firewall (SSH, HTTP, HTTPS only; MySQL stays on localhost)"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

echo
echo "Done. Next (docs/deploy.md): mysql_secure_installation, create the database and user,"
echo "write $APP_ROOT/shared/.env from .env.production.example, certbot --nginx -d $DOMAIN, first deploy."
