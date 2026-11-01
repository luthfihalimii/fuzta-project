# Akun Customer (Login, Riwayat Order, Alamat) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Customer bisa daftar/login (HP + password), melihat profil, riwayat order, dan menyimpan alamat lewat `/api/v1`; checkout guest tetap jalan dan otomatis terhubung ke akun bila login.

**Architecture:** Modul baru `Customers` (tabel terpisah dari staff), token opaque di-hash di DB (tanpa dependency baru), middleware `customer.auth` (mode `optional` untuk checkout). Order mendapat `customer_id` nullable; `placed_by_id` tetap null untuk jalur customer.

**Tech Stack:** Laravel 13, PostgreSQL, PHPUnit, `Http`/`Hash`/`Str` bawaan.

**Spec:** `docs/superpowers/specs/2026-11-01-customer-accounts-design.md` — baca dulu.

## Global Constraints

- Tanpa dependency composer/npm baru (tanpa Sanctum/Passport).
- Jangan pernah mengisi `audit_logs.actor_id`/`order_status_events.actor_id` dengan id customer; jangan memasang `Customer` sebagai user resolver.
- Token: mentah hanya di respons register/login; DB hanya SHA-256; lookup `WHERE token_hash = ?`.
- Semua query alamat/riwayat di-scope dari customer token; ID dari request tidak dipercaya.
- Log tanpa nomor HP/alamat/token; pesan error Bahasa Indonesia.
- Uang integer IDR (tidak berubah di tugas ini).
- Jangan sentuh file dirty user (`.gitignore`, `AGENTS.md`, `bun.lock`, `package.json`, `e2e/`, `graphify-out/`, `playwright.config.ts`), `.entire/`, `.opencode/`.
- `docs/` gitignored → `git add -f` untuk doc; format doc dengan `bun run check -- --fix <file>`.
- Gate akhir: `CI=true composer ci:check` (cek exit code; tanpa pipe).

## Review Focus

1. Token tidak bisa dipakai lintas customer; 401 untuk hilang/invalid/kedaluwarsa/nonaktif.
2. Alamat: maksimal 10 dan tepat satu default walau request bersamaan (row lock + partial unique index).
3. Checkout: bearer valid → kontak dari profil (body diabaikan), `customer_id` terisi, order customer tetap kedaluwarsa; replay lintas customer → 409; guest tidak berubah.
4. Tidak ada PII (HP/alamat/token) di log, respons publik, atau payload POS/kitchen.
5. Regresi: CORS PATCH/DELETE, rate limit, suite guest + POS/kitchen/laporan.

---

### Task 1: Skema, model, middleware, limiter, CORS

**Files:**

- Create: `database/migrations/2026_11_01_000005_create_customers_table.php`, `..._000006_create_customer_tokens_table.php`, `..._000007_create_customer_addresses_table.php`, `..._000008_add_customer_id_to_orders_table.php`
- Create: `app/Modules/Customers/Models/Customer.php`, `CustomerToken.php`, `CustomerAddress.php`
- Create: `app/Modules/Customers/Support/PhoneNumber.php` (normalisasi), `app/Modules/Customers/Support/CustomerPayload.php`
- Create: `app/Http/Middleware/AuthenticateCustomer.php`
- Create: `database/factories/CustomerFactory.php`, `CustomerAddressFactory.php`
- Modify: `app/Modules/Ordering/Models/Order.php` (fillable `customer_id` + relasi `customer()`), `bootstrap/app.php` (alias `customer.auth`), `app/Providers/AppServiceProvider.php` (limiter `customer-auth`, `customer-me`), `config/cors.php` (`PATCH`, `DELETE`)
- Test: `tests/Feature/CustomerFoundationTest.php`

**Interfaces:**

- `Customer` fillable: `name`, `phone`, `password`, `is_active`, `last_login_at`; hidden `password`; cast `password => hashed`, `is_active => bool`, `last_login_at => datetime`.
- `CustomerToken` fillable: `customer_id`, `token_hash`, `expires_at`, `last_used_at`; cast datetime.
- `CustomerAddress` fillable: `customer_id`, `label`, `recipient_name`, `phone`, `address`, `area_id`, `notes`, `is_default`; cast bool.
- `PhoneNumber::normalize(string $phone): string` — buang spasi/`-`/`(`, `+62`/`62` → `0`, hasil `08...`; tidak memvalidasi.
- `AuthenticateCustomer` — `handle(Request $request, Closure $next, string $mode = 'required')`: tanpa header → `optional` lanjut sebagai guest (tanpa customer), `required` → 401; header ada tapi token salah/kedaluwarsa/customer nonaktif → 401; valid → `$request->attributes->set('customer', $customer)` + update `last_used_at` bila > 1 jam.
- Limiter `customer-auth` 5/menit per IP; `customer-me` 60/menit per customer id (fallback IP).

- [ ] **Step 1: Tes gagal** `tests/Feature/CustomerFoundationTest.php`:
    - migrasi: `Customer::factory()->create(['phone' => '081200000001'])` → phone unique (duplikat melempar QueryException), `password` tersimpan ter-hash (`Hash::check`), `is_active` default true.
    - `CustomerAddress` default unik per customer: insert dua `is_default = true` untuk customer sama → QueryException; beda customer → boleh.
    - `Order::factory()->create(['customer_id' => $customer->id])` → relasi `customer` mengembalikan model; `Order::factory()->create()` → `customer_id` null.
    - `PhoneNumber::normalize`: `'+62 812-3456-7890'`, `'6281234567890'`, `'081234567890'` → semua `'081234567890'`.
    - Middleware: route uji `GET /api/v1/_probe` (didaftarkan di `routes/api.php` hanya saat `app()->environment('testing')`) dengan `customer.auth:optional` → tanpa header 200 `{mode:'guest'}`, header ngawur 401, token valid 200 `{mode:'customer'}`.
    - CORS preflight `PATCH /api/v1/me` → `Access-Control-Allow-Methods` memuat `PATCH` dan `DELETE`.
- [ ] **Step 2: Jalankan, pastikan gagal** — `php artisan test --filter=CustomerFoundationTest`.
- [ ] **Step 3: Implementasi** sesuai Interfaces + migrasi (partial unique index lewat `DB::statement`, pola `stock_balances`).
- [ ] **Step 4: Jalankan, pastikan lulus** — filter di atas + `--filter=CatalogApiTest` (CORS diperbarui) + `./vendor/bin/pint --test` file terkait.
- [ ] **Step 5: Commit** — `git add database app bootstrap config tests/Feature/CustomerFoundationTest.php` → `feat(customers): skema akun, token, alamat, middleware`

---

### Task 2: Register, login, logout, token, prune

**Files:**

- Create: `app/Modules/Customers/Actions/RegisterCustomer.php`, `LoginCustomer.php`, `IssueCustomerToken.php`, `RevokeCustomerToken.php`, `PruneExpiredCustomerTokens.php`
- Create: `app/Http/Requests/RegisterCustomerRequest.php`, `LoginCustomerRequest.php`
- Create: `app/Modules/Customers/Http/Controllers/Api/AuthController.php`
- Create: `app/Console/Commands/PruneCustomerTokens.php`
- Modify: `routes/api.php`, `routes/console.php`
- Test: `tests/Feature/CustomerAuthTest.php`, `tests/Feature/CustomerTokenPruneTest.php`

**Interfaces:**

- `RegisterCustomer::__invoke(string $name, string $phone, string $password): Customer` (normalisasi HP, unique → ValidationException `phone`, `Password::min(8)` di request).
- `IssueCustomerToken::__invoke(Customer $customer): array{token: string, expires_at: CarbonImmutable}` — 30 hari; simpan hash.
- `LoginCustomer::__invoke(string $phone, string $password): array{customer, token, expires_at}` — pesan generik `'Nomor HP atau password salah.'`, `Hash::check` dummy bila HP tidak ada, nonaktif → `'Akun tidak aktif.'`, update `last_login_at`.
- `AuthController` register 201 / login 200 → `{customer: CustomerPayload, token, expires_at}`; logout 204 (hapus token aktif).
- `CustomerPayload::__invoke(Customer $customer, bool $withCount = false): array` → `id`, `name`, `phone`, `created_at`, dan `addresses_count` bila `$withCount`.
- `customers:prune-tokens` → hapus `expires_at < now()`; jadwal `dailyAt('03:00')->timezone('Asia/Jakarta')`.

- [ ] **Step 1: Tes gagal** — register sukses (token 40 char, `Hash::check` benar, `last_login_at` terisi, `phone` ternormalisasi), HP duplikat 422, password < 8 422, HP format salah 422; login sukses/ salah / nonaktif; 429 setelah 5 percobaan; logout → token 401; token kedaluwarsa 401; prune hanya menghapus kedaluwarsa.
- [ ] **Step 2: Jalankan, pastikan gagal**.
- [ ] **Step 3: Implementasi** + limiter `customer-auth` pada register/login.
- [ ] **Step 4: Jalankan, pastikan lulus**.
- [ ] **Step 5: Commit** → `feat(customers): daftar, login, logout, token & prune`

---

### Task 3: Profil + alamat

**Files:**

- Create: `app/Modules/Customers/Actions/CreateCustomerAddress.php`, `UpdateCustomerAddress.php`, `DeleteCustomerAddress.php`
- Create: `app/Modules/Customers/Support/CustomerAddressPayload.php`
- Create: `app/Modules/Customers/Http/Controllers/Api/MeController.php`, `AddressController.php`
- Create: `app/Http/Requests/UpdateCustomerProfileRequest.php`, `StoreCustomerAddressRequest.php`, `UpdateCustomerAddressRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/CustomerProfileTest.php`, `tests/Feature/CustomerAddressTest.php`

**Interfaces:**

- `GET /me` → `{customer: CustomerPayload(+addresses_count)}`; `PATCH /me` hanya `name`.
- `GET /me/addresses` → `{addresses: [...]}` urut `is_default desc, created_at asc`; `POST` 201; `PATCH` 200; `DELETE` 204.
- Address payload: `id`, `label`, `recipient_name`, `phone`, `address`, `area_id`, `notes`, `is_default`.
- Semua operasi alamat: transaksi + `Customer::lockForUpdate()`; >10 → 422 `'addresses'`; `is_default=false` pada default → 422 `'is_default'`; hapus default → alamat terlama jadi default.
- Route `->whereNumber('address')`, akses lewat `$customer->addresses()->findOrFail($id)` (404 lintas customer).

- [ ] **Step 1: Tes gagal** — profil GET/PATCH (payload `phone`/`is_active` diabaikan); alamat CRUD lengkap, default pertama, pindah default tetap satu, batas 10, hapus default → pengganti, lintas customer 404, 11 alamat 422.
- [ ] **Step 2–4:** red → implementasi → green (`--filter=CustomerProfileTest`, `--filter=CustomerAddressTest`).
- [ ] **Step 5: Commit** → `feat(customers): profil & alamat tersimpan`

---

### Task 4: Riwayat order + integrasi checkout login

**Files:**

- Create: `app/Modules/Ordering/Support/CustomerOrderSummaryPayload.php`
- Create: `app/Modules/Customers/Http/Controllers/Api/OrderHistoryController.php`
- Modify: `app/Modules/Ordering/Data/CustomerOrderData.php` (`?int $customerId = null` di akhir), `app/Modules/Ordering/Actions/CreateCustomerOrder.php` (tulis `customer_id` + guard replay lintas customer), `app/Modules/Ordering/Http/Controllers/Api/CheckoutController.php` (ambil customer dari attribute, kirim `customerId`, 409 replay), `app/Http/Requests/StoreCustomerOrderRequest.php` (hapus `required` untuk `customer_name`/`customer_phone`? **tidak** — middleware yang mengisi; tetap `required`), `routes/api.php`
- Test: `tests/Feature/CustomerOrderHistoryTest.php`, `tests/Feature/CustomerAccountCheckoutTest.php`

**Interfaces:**

- `GET /me/orders` → `{orders: [{order_number, token, created_at, channel, status, payment_status, delivery_status, total}], meta: {current_page, last_page, per_page, total}}`, 10/halaman, `created_at desc`.
- `customer.auth:optional` dipasang pada `POST /orders` dan `POST /checkout/quote`; bila customer ada, middleware meng-`merge` `customer_name`/`customer_phone` dari profil (menimpa body) sehingga validasi `required` tetap jalan.
- `CreateCustomerOrder` menerima `customerId`; jika order existing ditemukan dengan `customer_id` berbeda dari `customerId` request → `ValidationException` `'idempotency_key' => 'Kunci idempotensi sudah dipakai.'` (409 render).

- [ ] **Step 1: Tes gagal** — riwayat hanya milik customer (tamu tidak muncul), urut + pagination + `meta`; checkout bearer valid → `customer_id` terisi dan body `customer_name`/`customer_phone` diabaikan (profil menang); tanpa bearer tetap guest; bearer invalid 401 di `/orders` dan `/checkout/quote`; replay customer sama 200 order sama; replay key sama customer lain 409; order customer tetap dibatalkan `orders:expire-unpaid`.
- [ ] **Step 2–4:** red → implementasi → green (`--filter=Customer`, `--filter=Order`, `--filter=DokuPayment`, `--filter=CustomerCheckoutConcurrencyTest`).
- [ ] **Step 5: Commit** → `feat(customers): riwayat order & checkout terhubung akun`

---

### Task 5: Dokumen + gate

**Files:**

- Modify: `docs/api.md` (bagian "Akun customer": endpoint, token, 401/409, rate limit; perbaiki klaim "semua rute publik"), `README.md` (scheduler `customers:prune-tokens`), `PRD.md` (tandai P1 akun/riwayat selesai; reset password tetap terbuka)
- Test: tidak ada tes baru.

- [ ] **Step 1: Tulis dokumen** sesuai implementasi nyata (baca route + payload).
- [ ] **Step 2: Format** — `bun run check -- --fix docs/api.md README.md PRD.md`.
- [ ] **Step 3: Gate** — `php artisan test` penuh, `bun run check`, `bun run types:check`, `bun run test:js`, `CI=true composer ci:check` (exit 0).
- [ ] **Step 4: Commit** — `git add -f docs/api.md && git add README.md PRD.md` → `docs: kontrak akun customer`

---

## Self-review

- **Cakupan spec:** data (T1), auth/token/prune (T2), profil+alamat (T3), riwayat+checkout (T4), dokumen (T5). CORS/prefill/replay blocker dari builder check masuk T1/T4.
- **Konsistensi:** `CustomerPayload` T1 dipakai T2/T3; `customer.auth` T1 dipakai T2–T4; `customer_id` T1 dipakai T4; `CustomerOrderSummaryPayload` T4 hanya.
- **Risiko:** middleware meng-`merge` kontak profil mengubah request sebelum validasi (pastikan tidak menimpa field lain); replay 409 harus tidak mengubah order lama; partial unique index di `down()` harus di-drop eksplisit.
