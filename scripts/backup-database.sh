#!/usr/bin/env bash
set -euo pipefail

: "${DB_DATABASE:?DB_DATABASE must be set}"
: "${DB_USERNAME:?DB_USERNAME must be set}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/pizza-fruzta}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
BACKUP_S3_BUCKET="${BACKUP_S3_BUCKET:-}"
BACKUP_S3_PREFIX="${BACKUP_S3_PREFIX:-pizza-fruzta}"

if [[ -n "${DB_PASSWORD:-}" ]]; then
    export PGPASSWORD="$DB_PASSWORD"
fi

umask 077
mkdir -p "$BACKUP_DIR"

exec 9>/tmp/pizza-fruzta-backup.lock
flock -n 9 || {
    echo "another backup is already running" >&2
    exit 1
}

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
filename="pizza-fruzta-${timestamp}.dump"
target="${BACKUP_DIR}/${filename}"
temporary="${target}.tmp"

cleanup() {
    rm -f "$temporary"
}
trap cleanup EXIT

PGHOST="$DB_HOST" PGPORT="$DB_PORT" PGUSER="$DB_USERNAME" \
    pg_dump --format=custom --no-owner --no-privileges --file="$temporary" "$DB_DATABASE"

mv "$temporary" "$target"
pg_restore --list "$target" >/dev/null

if [[ -n "$BACKUP_S3_BUCKET" ]]; then
    command -v aws >/dev/null || {
        echo "aws CLI is required when BACKUP_S3_BUCKET is set" >&2
        exit 1
    }

    aws s3 cp "$target" "s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX}/${filename}"
fi

find "$BACKUP_DIR" -type f -name 'pizza-fruzta-*.dump' -mtime +"$BACKUP_RETENTION_DAYS" -print -delete

echo "backup written: $target"
