# Deployment

Target: a single Ubuntu 24.04 VPS running Nginx, PHP-FPM, PostgreSQL, and one Laravel release. Scale out later; do not split the app into services for the MVP.

Replace `pizza.example.com` and `/var/www/pizza-fruzta` with the real domain and path. Commands assume the `deploy` user owns the release and `www-data` runs PHP.

## 1. Server packages

```bash
sudo apt update
sudo apt install -y nginx postgresql php8.3-fpm php8.3-cli php8.3-pgsql \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
  unzip git curl
```

Install Composer 2 and Node.js 22+ on the build host. Node is not needed at runtime once assets are built.

Create the database and a least-privilege application user:

```sql
CREATE USER pizza_fruzta WITH PASSWORD 'use-a-secret-manager';
CREATE DATABASE pizza_fruzta OWNER pizza_fruzta;
```

Keep PostgreSQL listening on localhost only. Allow only SSH, HTTP, and HTTPS through the firewall:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

## 2. Release layout and permissions

```bash
sudo mkdir -p /var/www/pizza-fruzta
sudo chown deploy:www-data /var/www/pizza-fruzta
```

After the first checkout and every release:

```bash
cd /var/www/pizza-fruzta
sudo chown -R deploy:www-data .
sudo find . -type d -exec chmod 775 {} \;
sudo find . -type f -exec chmod 664 {} \;
sudo chmod 600 .env
sudo chmod -R ug+rwX storage bootstrap/cache
```

Only `storage/` and `bootstrap/cache/` need to be writable by `www-data`. `scripts/backup-database.sh` must remain executable (`chmod +x`).

## 3. Environment

Start from `.env.example` and set at least:

```dotenv
APP_NAME="Pizza Fruzta"
APP_ENV=production
APP_KEY=                      # php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://pizza.example.com
APP_TIMEZONE=UTC

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pizza_fruzta
DB_USERNAME=pizza_fruzta
DB_PASSWORD=...

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

CACHE_STORE=database
QUEUE_CONNECTION=database

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

BACKUP_ENABLED=true
BACKUP_TIME=02:00
BACKUP_DIR=/var/backups/pizza-fruzta
BACKUP_RETENTION_DAYS=14
BACKUP_S3_BUCKET=              # optional, see backup-restore.md
```

`APP_TIMEZONE=UTC` is intentional: store UTC and display `Asia/Jakarta` through the app formatters. The backup schedule explicitly uses `Asia/Jakarta`.

Never commit `.env`. Keep `APP_KEY`, database credentials, and S3 keys in the server secret store, not in the repository.

## 4. Nginx and HTTPS

`/etc/nginx/sites-available/pizza-fruzta`:

```nginx
server {
    listen 80;
    server_name pizza.example.com;
    root /var/www/pizza-fruzta/public;
    index index.php;
    client_max_body_size 10m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable it, then issue the certificate:

```bash
sudo ln -s /etc/nginx/sites-available/pizza-fruzta /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d pizza.example.com
```

Certbot redirects HTTP to HTTPS and installs a renewal timer. Confirm with `sudo certbot renew --dry-run`. The app sends `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, and `Referrer-Policy: same-origin`; keep TLS termination and certificate renewal outside the app.

## 5. Build and migrate

Run from the release directory, in this order:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

`php artisan optimize` caches config, routes, views, and events. Run `php artisan optimize:clear` before changing `.env`, then re-run `optimize`.

On the first production deploy, seed the base units and pilot branches once:

```bash
php artisan db:seed --force
```

In production this creates units and branches only. It does not create demo users, the sample catalog, or known default passwords.

Then create the first owner once:

```bash
php artisan app:create-owner owner@your-domain.example
```

The command prompts for a strong password and refuses to run if an active owner already exists. Add the remaining staff through the owner UI.

Take a database backup before every migrate. Do not use `migrate:fresh` against a shared or production database.

## 6. Queue worker

The app uses the database queue for background work. Install `/etc/systemd/system/pizza-fruzta-queue.service`:

```ini
[Unit]
Description=Pizza Fruzta queue worker
After=network.target postgresql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/pizza-fruzta
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --timeout=90
ExecReload=/usr/bin/php artisan queue:restart
KillSignal=SIGTERM
TimeoutStopSec=90

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now pizza-fruzta-queue
```

Restart the worker on every deploy (`sudo systemctl reload pizza-fruzta-queue` or `php artisan queue:restart`). A deployed worker keeps old code in memory until it is restarted.

## 7. Scheduler

Add one crontab entry for `www-data`:

```cron
* * * * * cd /var/www/pizza-fruzta && /usr/bin/flock -n /tmp/pizza-fruzta-schedule.lock /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

This runs the nightly backup when `BACKUP_ENABLED=true` and is the place for any future scheduled jobs. Test it with `php artisan schedule:test` or `php artisan schedule:list`.

## 8. Release checklist

1. Take a database backup and confirm it is readable.
2. Put the app in maintenance mode if the release changes schema: `php artisan down --retry=60`.
3. Deploy the new code and keep the previous release available for rollback.
4. `composer install --no-dev --prefer-dist --optimize-autoloader`.
5. `npm ci && npm run build` (or copy the CI-built `public/build`).
6. `php artisan migrate --force`.
7. `php artisan optimize`.
8. `php artisan optimize:clear` first if `.env` changed, then `php artisan optimize` again.
9. Restart the queue worker and scheduler (scheduler reads code each minute).
10. `php artisan up`.
11. Check `/up`, sign in as each role, and place one test order on each branch.
12. Confirm the receipt prints on the branch printer; if it does not, record it in the pilot checklist.

## Rollback

Revert the code to the previous release, restore the matching `.env`, run `php artisan optimize:clear && php artisan optimize`, and restart the queue. If the migration is not backward compatible, restore the pre-deploy database backup instead of guessing a down migration. Never drop the production database to recover.
