#!/usr/bin/env bash
#
# Database + upload-tree backup for the SANAD Teacher platform (Laravel
# backend, backend/composer.json: "sanad/backend-v2").
#
# NOT installed anywhere by default. This is the artifact docs/DEPLOYMENT.md
# §8 describes running — wire it into cron/systemd yourself (see
# scripts/sanad-backup.cron in this same directory for the line to add).
#
# Usage:
#   BACKUP_DIR=/var/backups/sanad ./scripts/backup.sh
#
# Reads DB connection details from the project's own .env via `php artisan
# tinker` would be overkill; instead this script expects the same
# credentials file `mysqldump`/`mysql` already use for unattended runs:
# ~/.my.cnf (chmod 600, [client] section with user/password/host). Do not
# put credentials on the command line — they would be visible in `ps` and
# the shell history of every user on the box.
#
# Both halves MUST be restored together — a content_items row referencing an
# upload missing from disk is a broken content item. See docs/DEPLOYMENT.md
# §8 for the restore procedure.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/sanad}"
DB_NAME="${DB_NAME:-sanad_platform_v2_production}"
MYSQL_DEFAULTS_FILE="${MYSQL_DEFAULTS_FILE:-$HOME/.my.cnf}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
STAMP="$(date +%F-%H%M)"

mkdir -p "$BACKUP_DIR"

if [ ! -f "$MYSQL_DEFAULTS_FILE" ]; then
    echo "backup.sh: $MYSQL_DEFAULTS_FILE not found — create it with [client] user/password/host before running this script." >&2
    exit 1
fi

echo "==> Dumping database '$DB_NAME'..."
mysqldump --defaults-extra-file="$MYSQL_DEFAULTS_FILE" \
    --single-transaction --quick --routines --triggers --events \
    --default-character-set=utf8mb4 \
    "$DB_NAME" | gzip > "$BACKUP_DIR/db-$STAMP.sql.gz"
echo "    -> $BACKUP_DIR/db-$STAMP.sql.gz"

echo "==> Archiving storage/uploads..."
tar -czf "$BACKUP_DIR/uploads-$STAMP.tar.gz" \
    -C "$BACKEND_DIR/storage" uploads
echo "    -> $BACKUP_DIR/uploads-$STAMP.tar.gz"

# Prune backups older than RETENTION_DAYS. This is retention, not the only
# copy of anything — a serious deployment also ships these off-host
# (rsync/S3/whatever), which this script deliberately does not decide for you.
echo "==> Pruning backups older than ${RETENTION_DAYS} days..."
find "$BACKUP_DIR" -maxdepth 1 -name 'db-*.sql.gz' -mtime "+${RETENTION_DAYS}" -delete
find "$BACKUP_DIR" -maxdepth 1 -name 'uploads-*.tar.gz' -mtime "+${RETENTION_DAYS}" -delete

echo "==> Done. $(du -sh "$BACKUP_DIR" 2>/dev/null | cut -f1) total in $BACKUP_DIR"
echo
echo "Untested restores are not backups — periodically restore into a scratch"
echo "database and run 'php artisan migrate --database=<scratch>' to confirm"
echo "the dump is usable. See docs/DEPLOYMENT.md §8 for the restore commands."
