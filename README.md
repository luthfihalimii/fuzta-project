# Pizza Fruzta

Digital ordering and branch operations for two Pizza Fruzta branches: POS, kitchen display, catalog, inventory ledger, reports, and audit. One Laravel application serves the Inertia/Vue staff UI; the same domain actions are intended to back the future `/api/v1` and mobile clients.

This repository is the first-release MVP. Payment is recorded in the app (cash or static QRIS); there is no payment gateway integration, delivery, loyalty, or customer mobile app yet.

## Requirements

- PHP 8.3+ with `pdo_pgsql`, `mbstring`, `xml`, `curl`, and `zip`
- Composer 2
- PostgreSQL 16+
- Node.js 22+ and npm
- Optional: `pg_dump`, `pg_restore`, and the AWS CLI for backups

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the database and point `DB_*` in `.env` at it:

```bash
createdb pizza_fruzta
```

Then install the frontend, migrate and seed, and run the app:

```bash
npm install
php artisan migrate --seed
npm run build

# terminal 1
php artisan serve

# terminal 2 (hot reload)
npm run dev
```

Open `http://127.0.0.1:8000`.

The seeder creates two branches, an owner, and one cashier and one kitchen account per branch. Outside production it also loads a small sample catalog and ingredients for acceptance testing. All seeded passwords are `password`; change them before any pilot.

| Role          | Email                          |
| ------------- | ------------------------------ |
| Owner         | `owner@pizzafruzta.test`       |
| Cashier PF-01 | `kasir.pf-01@pizzafruzta.test` |
| Kitchen PF-01 | `dapur.pf-01@pizzafruzta.test` |
| Cashier PF-02 | `kasir.pf-02@pizzafruzta.test` |
| Kitchen PF-02 | `dapur.pf-02@pizzafruzta.test` |

If production seeding is used, it creates the units and branches only; it does not create demo users or the sample catalog. After the first production migration, create the first owner once:

```bash
php artisan app:create-owner owner@your-domain.example
```

The command prompts for the password and refuses to run when an active owner already exists. Add the remaining staff through the owner UI.

## Tests and checks

```bash
php artisan test
npm run test:js
npm run typecheck
npm run lint
./vendor/bin/pint --test
```

The PHP suite uses the `pizza_fruzta_test` database (create it once). Do not run `npm run build` at the same time as `php artisan test`; Vite can rewrite the manifest and break Inertia rendering during the run.

`composer ci:check` runs formatting, lint, typecheck, JS tests, the production build, Pint, and the PHP suite. Static analysis is available with `composer analyse`, but PHPStan is not a CI gate yet.

## Project layout

```
app/Http/                  Auth, admin, and shared HTTP concerns
app/Modules/               Domain modules (catalog, ordering, payments, inventory, ...)
resources/js/pages/        Inertia/Vue pages
resources/js/components/   Shared Vue components
routes/                    Web, API, and console routes
tests/Feature/             PHP feature tests
tests/js/                  Vitest unit tests
docs/operations/           Deployment, backups, printing, and pilot checklists
```

## Operations

- [Deployment](docs/operations/deployment.md)
- [Backup and restore](docs/operations/backup-restore.md)
- [Receipt printing](docs/operations/printing.md)
- [Pilot acceptance checklist](docs/operations/pilot-checklist.md)

Production reminders: `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, `SESSION_SECURE_COOKIE=true`, a real `APP_KEY`, database backups, and queue/scheduler services. See the deployment checklist.

## Known open items

- Thermal printer model, connection, and paper width must be confirmed per branch before printing is considered ready.
- Brand yellow/orange hex values are provisional.
- The non-production sample catalog, prices, ingredients, and thresholds are placeholders. Replace them with business-approved data before production; production seeding skips demo data.
