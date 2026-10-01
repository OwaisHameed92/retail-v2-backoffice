#!/usr/bin/env bash
# Nightly backup of the retail-v2 MySQL database and uploaded files (installed as /usr/local/bin/retail-v2-backup-db,
# run by /etc/cron.d/retail-v2 as the app user). See docs/deploy.md, "Backups".
#
# Credentials come from an option file (never the command line, so they are not in `ps`):
#   ~/.retail-v2-backup.cnf (chmod 600):
#     [client]
#     user=retail_backup
#     password=...
#     host=127.0.0.1
#
# Settings (environment or defaults):
#   DB_NAME          database to dump                      (retail_v2)
#   BACKUP_DIR       where dumps are written               (/var/backups/retail-v2)
#   RETENTION_DAYS   delete local dumps older than this    (14)
#   STORAGE_DIR      uploaded files to archive alongside   (/var/www/retail-v2/shared/storage/app)
#   OFFSITE_CMD      optional command run with the dump path as $1, e.g. an rclone/s3 copy. Strongly recommended:
#                    a backup on the same disk as the database does not survive losing the server.
set -euo pipefail

DB_NAME="${DB_NAME:-retail_v2}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/retail-v2}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
STORAGE_DIR="${STORAGE_DIR:-/var/www/retail-v2/shared/storage/app}"
CNF="${CNF:-$HOME/.retail-v2-backup.cnf}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

umask 077
mkdir -p "$BACKUP_DIR"

dump="$BACKUP_DIR/${DB_NAME}-${STAMP}.sql.gz"
tmp="$dump.partial"

# --single-transaction: consistent InnoDB snapshot without locking the tills out.
# --no-tablespaces: the backup user needs no PROCESS privilege.
mysqldump --defaults-extra-file="$CNF" \
  --single-transaction --quick --routines --triggers --events --no-tablespaces \
  --default-character-set=utf8mb4 "$DB_NAME" | gzip -6 > "$tmp"

# A dump that did not finish has no trailer: refuse to keep it.
if ! gzip -cd "$tmp" | tail -n 1 | grep -q 'Dump completed'; then
  echo "$(date -u +%FT%TZ) backup FAILED: $dump is incomplete" >&2
  rm -f "$tmp"
  exit 1
fi
mv "$tmp" "$dump"

files=""
if [ -d "$STORAGE_DIR" ]; then
  files="$BACKUP_DIR/storage-${STAMP}.tar.gz"
  tar -czf "$files" -C "$(dirname "$STORAGE_DIR")" "$(basename "$STORAGE_DIR")"
fi

if [ -n "${OFFSITE_CMD:-}" ]; then
  $OFFSITE_CMD "$dump"
  [ -n "$files" ] && $OFFSITE_CMD "$files"
fi

find "$BACKUP_DIR" -maxdepth 1 -type f \( -name "${DB_NAME}-*.sql.gz" -o -name 'storage-*.tar.gz' \) \
  -mtime +"$RETENTION_DAYS" -delete

echo "$(date -u +%FT%TZ) backup ok: $dump ($(du -h "$dump" | cut -f1))"
