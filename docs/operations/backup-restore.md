# Backup and restore

The PostgreSQL database is the source of truth for orders, payments, stock movements, and audit logs. Back it up nightly and test a restore before the pilot.

## What runs

`scripts/backup-database.sh`:

1. Fails immediately if `DB_DATABASE` or `DB_USERNAME` is missing.
2. Writes a custom-format `pg_dump` to `BACKUP_DIR` with permissions restricted by `umask 077`.
3. Validates the archive with `pg_restore --list`.
4. Optionally uploads it to an S3-compatible bucket.
5. Deletes local dumps older than `BACKUP_RETENTION_DAYS`.

When `BACKUP_ENABLED=true`, Laravel runs the `backup:database` command daily at `BACKUP_TIME` in `Asia/Jakarta` through the scheduler (`routes/console.php`). The command reads the database and backup settings from cached config and passes them to the script, so `.env` is not required at scheduler runtime. Run `php artisan config:cache` after changing these values. The scheduler must be running as described in [deployment.md](deployment.md).

```dotenv
BACKUP_ENABLED=true
BACKUP_TIME=02:00
BACKUP_DIR=/var/backups/pizza-fruzta
BACKUP_RETENTION_DAYS=14
BACKUP_S3_BUCKET=
BACKUP_S3_PREFIX=pizza-fruzta
```

The backup directory must be writable by the user running the scheduler (`www-data`). Keep it on a different disk or volume than PostgreSQL if possible.

## Manual run

```bash
cd /var/www/pizza-fruzta
php artisan backup:database
```

The command reads the same config values as the scheduler. To run the shell script directly, export `DB_*` and `BACKUP_*` first:

```bash
cd /var/www/pizza-fruzta
set -a
. ./.env
set +a
./scripts/backup-database.sh
```

Use the same `pg_dump` major version as the server (for example `postgresql-client-18` with PostgreSQL 18). A newer client can dump an older server; an older client against a newer server is not supported.

If the script fails, no newer dump is uploaded. Check the scheduler log, cron log, and backup directory:

```bash
tail -n 50 storage/logs/scheduler.log
journalctl -u cron --since today
ls -lh /var/backups/pizza-fruzta
```

Do not add `|| true` to the schedule or script. A silent backup failure is worse than a noisy one.

## S3-compatible storage

Set `BACKUP_S3_BUCKET` and provide credentials through the AWS CLI environment, an instance role, or the server secret store. The bucket policy should allow only the backup identity to put objects under `${BACKUP_S3_PREFIX}/`.

Recommended bucket settings:

- Server-side encryption (SSE-S3 or SSE-KMS).
- Versioning enabled.
- Lifecycle rule to expire non-current versions and old backups after the agreed retention window.
- Block public access.
- A restore test from a downloaded object, not only from the local disk.

The script uploads with `aws s3 cp`; install the AWS CLI and make sure `aws` is on the scheduler user's `PATH`.

## Restore test

Always restore into a throwaway database first:

```bash
export PGHOST=127.0.0.1
export PGPORT=5432
export PGUSER=pizza_fruzta
export PGPASSWORD='...'

dropdb --if-exists pizza_fruzta_restore_test
createdb pizza_fruzta_restore_test
pg_restore --no-owner --no-privileges \
  --dbname=pizza_fruzta_restore_test \
  /var/backups/pizza-fruzta/pizza-fruzta-YYYYMMDDTHHMMSSZ.dump
```

Then compare a few records with production:

```bash
psql --dbname=pizza_fruzta_restore_test -c \
  "select count(*) from orders; select count(*) from payments; select count(*) from stock_movements;"
psql --dbname=pizza_fruzta_restore_test -c \
  "select order_number, total, status from orders order by id desc limit 5;"
```

Open the restored data with a read-only copy of the app only if needed. Drop the test database when finished:

```bash
dropdb pizza_fruzta_restore_test
```

Record the date, backup file, row counts, and result in the pilot checklist. A backup is not considered valid until a restore has been verified.

## Production restore

Only after approval and after taking a fresh backup of the current database:

1. Put the app in maintenance mode and stop the queue worker.
2. Create a new database owned by the application user.
3. Restore the chosen dump into the new database.
4. Point `.env` at the new database, or rename databases while no connections exist.
5. Run `php artisan migrate --force` only if the dump predates required migrations.
6. Clear and rebuild caches, restart the queue worker, and bring the app back up.
7. Verify login, one order, the stock ledger, and the audit view.

Do not drop or overwrite the production database without a verified backup and explicit approval. Do not use `migrate:fresh` as a restore method.
