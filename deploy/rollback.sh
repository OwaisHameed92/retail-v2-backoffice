#!/usr/bin/env bash
# Put a previous release back live (docs/deploy.md, "Rollback"). Run on the server as the app user.
#
#   bash deploy/rollback.sh                       # the release before the current one
#   RELEASE=20261001083000 bash deploy/rollback.sh  # a named release folder
#
# Code only: migrations are NOT reversed. Deploys only ship backward-compatible migrations, so the previous code runs on
# the newer schema. If a migration itself must be undone, see docs/deploy.md first (restore beats migrate:rollback).
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/retail-v2}"
PHP="${PHP:-/usr/bin/php}"
FPM_SERVICE="${FPM_SERVICE:-php8.4-fpm}"
current="$(readlink -f "$APP_ROOT/current")"

if [ -n "${RELEASE:-}" ]; then
  target="$APP_ROOT/releases/$RELEASE"
else
  target="$(ls -1d "$APP_ROOT"/releases/*/ | sed 's#/$##' | sort | grep -B1 -x "$current" | head -n 1)"
fi

if [ -z "$target" ] || [ ! -d "$target" ] || [ "$target" = "$current" ]; then
  echo "No earlier release to roll back to (current: $current)" >&2
  ls -1 "$APP_ROOT/releases" >&2
  exit 1
fi

echo "[rollback] $(basename "$current") -> $(basename "$target")"
ln -sfn "$target" "$APP_ROOT/current.next"
mv -Tf "$APP_ROOT/current.next" "$APP_ROOT/current"

cd "$target"
"$PHP" artisan optimize
sudo /bin/systemctl reload "$FPM_SERVICE"
"$PHP" artisan queue:restart
echo "[rollback] live: $(basename "$target")"
