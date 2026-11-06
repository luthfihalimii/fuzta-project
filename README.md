# Pizza Fruzta

Digital ordering and branch operations for two Pizza Fruzta branches: POS with cashier shifts, kitchen display, catalog, inventory ledger, reports, and audit. One Laravel application serves the Inertia/Vue staff UI; the same domain actions are intended to back the future `/api/v1` and mobile clients.

This repository is the first-release MVP. The staff UI records payments in the app (cash or static QRIS); the public customer API (`/api/v1`) adds online QRIS payment via DOKU and delivery via Biteship. There is no loyalty feature or customer mobile app yet.

## Requirements

- PHP 8.3+ with `pdo_pgsql`, `mbstring`, `xml`, `curl`, and `zip`
- Composer 2
- PostgreSQL 16+
- Bun 1.4 (bundles its own JavaScript runtime)
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
bun install
php artisan migrate --seed
bun run build

# terminal 1
php artisan serve

# terminal 2 (hot reload)
bun run dev
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
bun run test:js
bun run typecheck
bun run lint
./vendor/bin/pint --test
```

The PHP suite uses the `pizza_fruzta_test` database (create it once). Do not run `bun run build` at the same time as `php artisan test`; Vite can rewrite the manifest and break Inertia rendering during the run.

`composer ci:check` runs formatting, lint, typecheck, JS tests, the production build, Pint, and the PHP suite. Static analysis is available with `composer analyse`, but PHPStan is not a CI gate yet.

## Realtime (Reverb)

Dev membutuhkan dua proses tambahan: `php artisan reverb:start --debug` (WebSocket server di `127.0.0.1:8080`; nilai env `REVERB_*`/`VITE_REVERB_*` sudah dev-stable di `.env.example`) dan `php artisan queue:work` (broadcast dikirim lewat queue karena `QUEUE_CONNECTION=database`; tanpa worker, layar dapur/POS tidak menerima event).
Deploy produksi mengikuti checklist di [docs/superpowers/specs/2026-10-29-realtime-kitchen-pos-design.md](docs/superpowers/specs/2026-10-29-realtime-kitchen-pos-design.md).

## Customer API (`/api/v1`)

API publik untuk customer: katalog, checkout (pickup/delivery), bayar QRIS via DOKU, pengiriman via Biteship. Kontrak lengkap (request/response, aturan `Idempotency-Key`, status) ada di `docs/api.md`.

Ada juga **akun customer** (bagian "Akun customer" di `docs/api.md`): daftar/login dengan nomor HP + password, token bearer 30 hari, riwayat order, dan alamat tersimpan (maks 10, satu default). Checkout tamu tetap jalan tanpa akun; checkout dengan bearer valid otomatis menghubungan order ke akun (kontak diambil dari profil). Password reset/OTP customer **belum tersedia**.

Variabel env yang disyaratkan (lihat `.env.example`):

- DOKU: `DOKU_CLIENT_ID`, `DOKU_SECRET_KEY`, `DOKU_BASE_URL`, `DOKU_NOTIFICATION_PATH`, `DOKU_PAYMENT_DUE_MINUTES`.
- Biteship: `BITESHIP_API_KEY`, `BITESHIP_BASE_URL`, `BITESHIP_COURIERS`, `BITESHIP_SHIPPER_NAME`/`BITESHIP_SHIPPER_PHONE`/`BITESHIP_SHIPPER_EMAIL`, `BITESHIP_WEBHOOK_KEY` (nama custom header), `BITESHIP_WEBHOOK_SECRET` (isi header).
- `CORS_ALLOWED_ORIGINS`: daftar domain (pisahkan koma) yang boleh memanggil API. **Produksi wajib diisi domain frontend yang asli**; default dev hanya `localhost` (bukan `*`).

URL webhook yang daftarkan (HTTPS publik, bukan untuk frontend):

- DOKU notification URL: `https://<domain>/api/v1/webhooks/doku` — path harus sama dengan `DOKU_NOTIFICATION_PATH`.
- Biteship webhook (dashboard → Integrations → Webhook): URL `https://<domain>/api/v1/webhooks/biteship`, dengan satu custom header — **nama header sama persis dengan `BITESHIP_WEBHOOK_KEY` (default `X-Biteship-Signature`) dan isi header sama persis dengan `BITESHIP_WEBHOOK_SECRET`**. Verifikasi di server = `hash_equals(secret, isi header)`; salah → 403.

Tiga proses yang harus jalan di produksi (di samping web server):

1. `php artisan queue:work` — job shipment Biteship + broadcast realtime.
2. `php artisan reverb:start` — WebSocket (lihat Realtime).
3. Scheduler — cron `* * * * * php artisan schedule:run` (atau `schedule:work`); menjalankan `orders:expire-unpaid` tiap 10 menit yang membatalkan order online unpaid lewat jatuh tempo bayar, dan `customers:prune-tokens` harian 03:00 Asia/Jakarta yang menghapus token customer kedaluwarsa.

Prasyarat ops sebelum pilot:

- Akun DOKU (mulai dari sandbox) + Client-Id/Secret, notification URL sudah dipasang (lihat di atas).
- Akun Biteship dengan API key **dan saldo** (Biteship menagih per pengiriman; ongkir sudah dibayar customer ke kita lewat DOKU — kita mengambalikan ke Biteship belakangan).
- Berat produk: `weight_grams` diisi di admin katalog (fallback 500 g saat quote/shipment — kurangi kejanggalan ongkir).
- Area Biteship: `delivery_area_id` diisi per cabang yang melayani delivery (admin branches), plus area tujuan customer diambil dari pencarian `/delivery/areas`.
- Catatan service instan (Gojek/Grab Express): aplikasi ini hanya mengirim area id Biteship (bukan koordinat asal/tujuan). Verifikasi saat pilot apakah kurir instan tetap bisa di-quote untuk wilayah cabang; bila Biteship menolaknya untuk wilayah tertentu, opsi instan tidak muncul di quote/checkout dan delivery tetap bisa pakai kurir standar (JNE/JNT/dsb.).
- Pembatalan/refund order online manual: kasir/owner batalkan order + refund manual via dashboard DOKU (modul refund otomatis bukan MVP). Bila notifikasi `SUCCESS` datang setelah pembatalan lokal, sistem mencatat event `late_paid`, audit `payment.late_payment`, dan log critical tanpa menghidupkan order — gunakan catatan itu untuk refund manual.

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

## Cashier shifts

Every new POS order and payment must belong to an open cashier shift.

- Shift windows are `09:00–15:00` and `15:00–21:00` Asia/Jakarta, configured in `config/shifts.php`.
- A cashier may open one shift per branch per window. Owners may hold one open shift per branch.
- The opening float comes from the branch `Float kas` field on `/admin/branches`; the cashier only confirms the physical count.
- Owner-only cash in/out is recorded on the shift detail page. Cash out cannot exceed cash available.
- A shift cannot close while it still has unpaid orders. Close records counted cash, deposit, variance, and a mandatory note when the variance is not zero.
- Shifts past the window end plus 15 minutes are auto-closed by `php artisan shifts:auto-close`. The scheduler entry in `routes/console.php` runs it every five minutes, so `php artisan schedule:work` (or cron `schedule:run`) must be running.
- Auto-closed shifts have no counted cash. The owner or the shift's cashier reconciles them from the POS or `/admin/shifts`.
- Closed shifts are immutable; voiding a payment from a closed shift is blocked until the refund module exists.
- Owner reports live at `/admin/shifts`: per-shift list and a daily summary per branch and cashier.

## Purchasing & suppliers

Suppliers live at `/admin/suppliers` (owner; edit/deactivate only, never deleted). Stock receipts against a supplier are recorded from `/admin/purchases`, the POS header, or the kitchen header: per line pick ingredient + supplier unit (kg, pack, …) + quantity + unit price. The server converts to the ingredient's base unit and appends `Receipt` stock movements. Purchase documents are immutable (corrections go through owner stock adjustments); each submission is idempotent and audited.

## Product photos

Product photos are uploaded from the admin catalog (product form, "Foto produk"). The server re-encodes each photo to JPEG (max width 1200px) and stores it on the disk named by `IMAGES_DISK` (default `public`; keep the default in dev/test).

Production R2 checklist (owner):

1. Create a Cloudflare R2 bucket as **public** (publicly browseable).
2. Create an R2 API token with object read/write access; the token's Access Key ID goes to `R2_ACCESS_KEY_ID`, its secret to `R2_SECRET_ACCESS_KEY`.
3. Set `R2_ENDPOINT` to `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` (Account ID from the Cloudflare dashboard) and `R2_BUCKET` to the bucket name.
4. Set `R2_PUBLIC_BASE_URL` to the bucket's public domain (`https://pub-xxx.r2.dev` — include the `https://` scheme).
5. Set `IMAGES_DISK=r2`, then `php artisan optimize:clear && php artisan optimize`.

HEIC photos (raw iPhone captures) are not supported — send them via WhatsApp/Telegram first so they arrive as JPG.

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
- Customer password reset/OTP belum ada (butuh kanal SMS/email — keputusan terbuka); deaktifasi akun sementara lewat `is_active`.
