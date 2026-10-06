#!/usr/bin/env bash
# Copy one backup file to Backblaze B2 (called by retail-v2-backup-db as OFFSITE_CMD, as the app user).
# Remote "b2" lives in ~retail/.config/rclone/rclone.conf (chmod 600), written by the owner, never in git or chat.
set -euo pipefail
BUCKET="${OFFSITE_BUCKET:-sspos-retail-v2-backup}"
case "$(basename "$1")" in storage-*) dir=storage ;; *) dir=database ;; esac
rclone copy --no-traverse "$1" "b2:$BUCKET/$dir/"
echo "$(date -u +%FT%TZ) offsite ok: $dir/$(basename "$1")"
