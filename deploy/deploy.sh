#!/usr/bin/env bash
# Zero-downtime deploy of the retail-v2 backoffice (docs/deploy.md, "3. Deploy"). Run ON THE SERVER as the app user:
#
#   REPO=git@github.com:org/retail-v2-backoffice.git REF=main HEALTH_URL=https://portal.example.co.uk/up \
#     bash deploy/deploy.sh   (from any checkout of this repo)
#
# Layout:  $APP_ROOT/releases/<UTC timestamp>/   one folder per deploy (the last $KEEP are kept)
#          $APP_ROOT/shared/.env                 the production .env (never in git)
#          $APP_ROOT/shared/storage/             logs, uploads, sessions/cache files: survives releases
#          $APP_ROOT/current -> releases/<…>     what Nginx, the workers and cron run
#
# The new release is fully built (composer, Vite, migrations, caches) before `current` moves, so requests never see a
# half-built release. Migrations run while the old release still serves: they must be backward compatible (add, don't
# rename/drop in the same deploy). For a breaking migration set MAINTENANCE=1 (tills get 503 + Retry-After and retry).
# If the health check fails after the switch, the previous release is put back automatically.
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/retail-v2}"
REPO="${REPO:?set REPO to the git URL}"
REF="${REF:-main}"
KEEP="${KEEP:-5}"
HEALTH_URL="${HEALTH_URL:?set HEALTH_URL, e.g. https://portal.example.co.uk/up}"
MAINTENANCE="${MAINTENANCE:-0}"
PHP="${PHP:-/usr/bin/php}"
FPM_SERVICE="${FPM_SERVICE:-php8.4-fpm}"

release="$APP_ROOT/releases/$(date -u +%Y%m%d%H%M%S)"
previous="$(readlink -f "$APP_ROOT/current" 2>/dev/null || true)"

log() { echo "[deploy $(date -u +%T)] $*"; }

[ -f "$APP_ROOT/shared/.env" ] || { echo "Missing $APP_ROOT/shared/.env (see .env.production.example)" >&2; exit 1; }

log "Cloning $REF into $release"
git clone --quiet --depth 1 --branch "$REF" "$REPO" "$release"
cd "$release"
log "Commit $(git rev-parse --short HEAD)"

log "Linking shared .env and storage"
rm -rf storage
ln -s "$APP_ROOT/shared/storage" storage
ln -s "$APP_ROOT/shared/.env" .env

log "Composer (no dev)"
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

log "Vite build"
npm ci --no-audit --no-fund --silent
npm run build
rm -rf node_modules

"$PHP" artisan storage:link --quiet || true

if [ "$MAINTENANCE" = "1" ] && [ -n "$previous" ]; then
  log "Maintenance mode on (tills get 503 + Retry-After)"
  (cd "$previous" && "$PHP" artisan down --retry=60)
fi

log "Migrations"
# --isolated takes a cache lock; with CACHE_STORE=database its table only exists after the first deploy.
if [ -n "$previous" ]; then
  "$PHP" artisan migrate --force --isolated
else
  "$PHP" artisan migrate --force
fi

log "Caches (config, routes, views, events)"
"$PHP" artisan optimize

log "Switching current -> $release"
ln -sfn "$release" "$APP_ROOT/current.next"
mv -Tf "$APP_ROOT/current.next" "$APP_ROOT/current"

# opcache.validate_timestamps=0: reload FPM so it serves the new release (graceful, in-flight requests finish).
sudo /bin/systemctl reload "$FPM_SERVICE"
"$PHP" artisan queue:restart
[ "$MAINTENANCE" = "1" ] && "$PHP" artisan up

log "Health check $HEALTH_URL"
ok=0
for _ in 1 2 3 4 5; do
  if curl -fsS --max-time 10 -o /dev/null "$HEALTH_URL"; then ok=1; break; fi
  sleep 3
done

if [ "$ok" != "1" ]; then
  log "Health check FAILED"
  if [ -n "$previous" ]; then
    log "Rolling back to $previous"
    RELEASE="$(basename "$previous")" APP_ROOT="$APP_ROOT" bash "$release/deploy/rollback.sh"
  fi
  exit 1
fi

log "Pruning old releases (keeping $KEEP)"
ls -1d "$APP_ROOT"/releases/*/ | sort | head -n -"$KEEP" | while read -r old; do
  [ "$(readlink -f "$old")" = "$(readlink -f "$APP_ROOT/current")" ] || rm -rf "$old"
done

log "Deployed $(basename "$release")"
