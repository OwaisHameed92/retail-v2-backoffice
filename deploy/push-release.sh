#!/usr/bin/env bash
# Build locally and ship a release to a server that has no Node and no git deploy key (the shared VPS that also runs
# the old retail-portal). Usage from the repo root, on a clean, committed tree:
#   SERVER=root@187.124.113.13 HEALTH_URL=https://retail-v2-portal.sspos.co.uk/up bash deploy/push-release.sh
# Same steps as deploy.sh on the server: new release folder, shared .env + storage, composer --no-dev, migrate,
# optimize, atomic switch, PHP-FPM reload, queue restart, health check with automatic rollback, keep 5 releases.
set -euo pipefail

SERVER="${SERVER:?set SERVER=user@host}"
APP_ROOT="${APP_ROOT:-/var/www/retail-v2}"
APP_USER="${APP_USER:-retail}"
HEALTH_URL="${HEALTH_URL:?set HEALTH_URL}"

if [ -n "$(git status --porcelain)" ]; then
    echo "Commit or stash your changes first: only committed code is shipped." >&2
    exit 1
fi

REL=$(date -u +%Y%m%d%H%M%S)
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

npm run build >/dev/null
git archive HEAD | tar -x -C "$TMP"
cp -R public/build "$TMP/public/build"
COPYFILE_DISABLE=1 tar --no-xattrs -czf "$TMP.tgz" -C "$TMP" . 2>/dev/null || COPYFILE_DISABLE=1 tar -czf "$TMP.tgz" -C "$TMP" .
scp -q "$TMP.tgz" "$SERVER:/tmp/retail-v2-$REL.tgz"
rm -f "$TMP.tgz"

ssh "$SERVER" "REL=$REL APP_ROOT=$APP_ROOT APP_USER=$APP_USER HEALTH_URL=$HEALTH_URL bash -s" <<'REMOTE'
set -euo pipefail
R="$APP_ROOT/releases/$REL"
PREV=$(readlink -f "$APP_ROOT/current" || true)
mkdir -p "$R"
tar -xzf "/tmp/retail-v2-$REL.tgz" -C "$R" 2>/dev/null
rm -f "/tmp/retail-v2-$REL.tgz"
rm -rf "$R/storage"
ln -s "$APP_ROOT/shared/storage" "$R/storage"
ln -sfn "$APP_ROOT/shared/.env" "$R/.env"
chown -R "$APP_USER:$APP_USER" "$R"
chmod 755 "$R"   # mktemp -d made the packed root 0700: Nginx (www-data) must traverse it
cd "$R"
sudo -u "$APP_USER" -H composer install --no-dev --optimize-autoloader --no-interaction --no-progress -q
sudo -u "$APP_USER" php artisan migrate --force
sudo -u "$APP_USER" php artisan optimize >/dev/null
ln -sfn "$R" "$APP_ROOT/current.new" && mv -Tf "$APP_ROOT/current.new" "$APP_ROOT/current"
systemctl reload php8.4-fpm
sudo -u "$APP_USER" php artisan queue:restart >/dev/null
sleep 2
if ! curl -fsS -o /dev/null "$HEALTH_URL"; then
    echo "Health check failed: rolling back to $PREV" >&2
    if [ -n "$PREV" ]; then ln -sfn "$PREV" "$APP_ROOT/current.new" && mv -Tf "$APP_ROOT/current.new" "$APP_ROOT/current"; systemctl reload php8.4-fpm; fi
    exit 1
fi
ls -1dt "$APP_ROOT"/releases/* | tail -n +6 | xargs -r rm -rf
echo "Live: $REL"
REMOTE
