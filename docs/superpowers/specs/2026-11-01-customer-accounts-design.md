# Desain: Akun Customer (Login, Riwayat Order, Alamat Tersimpan)

- **Tanggal:** 1 November 2026
- **Status:** Disetujui untuk dieksekusi (owner: "skip tanya-tanya, langsung kerjakan yang masuk akal")
- **Merujuk:** PRD P1 "Customers can sign in to view saved details and order history", "Customer order history and reorder"; spec `2026-11-01-customer-api-design.md` (customer API guest)

## Niat & mengapa

Customer API sekarang hanya guest: order tamu tidak bisa dilihat lagi, alamat diketik ulang setiap checkout, dan aplikasi Flutter tidak punya identitas customer. Ini melengkapi P1: daftar/login, riwayat order, alamat tersimpan. Checkout tamu **tetap jalan** — login opsional.

## Keputusan builder (setelah builder check)

1. **Tabel `customers` terpisah dari `users` (staff).** Staff auth, role, policy, audit `actor_id`, dan siaran Reverb tidak disentuh. Modul baru `app/Modules/Customers/`. Jangan pernah memasang `Customer` sebagai user resolver / mengisi `actor_id` dengan id customer.
2. **Token API opaque, di-hash SHA-256 di DB** (tanpa Sanctum/Passport). Lookup = `WHERE token_hash = sha256(token)` (indeks unik, bukan `hash_equals` — token 320-bit, timing attack tidak praktis). Token mentah 40 karakter `Str::random(40)`, dikembalikan sekali, berlaku **30 hari**, logout menghapus baris.
3. **Login = nomor HP + password.** Nomor dinormalisasi ke `08...` sebelum validasi (terima `08`, `62`, `+62`, spasi/tanda hubung). Tanpa OTP/SMS; reset password mandiri tetap non-goal (butuh kanal SMS/email — keputusan owner).
4. **Riwayat hanya order yang dibuat setelah login** (`orders.customer_id`). Order tamu lama tidak diklaim otomatis (nomor HP belum terverifikasi).
5. **Kontak checkout: profil otoritatif.** Bila bearer valid, `customer_name`/`customer_phone` pada body diabaikan dan diisi dari profil (mencegah salah kirim / pesanan atas nama orang lain). Order tetap `placed_by_id = null` (jalur customer), sehingga `orders:expire-unpaid` tetap membatalkannya.
6. **Checkout replay lintas customer ditolak:** `Idempotency-Key` yang sudah dipakai order milik customer lain → 409 `idempotency_key`. Order lama tidak pernah diubah.
7. **Password policy:** `Password::min(8)` eksplisit (bukan `Password::default()` produksi yang bisa memanggil layanan eksternal dan menaikkan syarat jadi 12+ untuk customer).
8. **Menonaktifkan customer** sementara hanya lewat operasi manual DB (`is_active = false`); `is_active` langsung memblokir login dan semua endpoint `me/*`. Admin UI customer = di luar lingkup.
9. **CORS** ditambah `PATCH`, `DELETE` (endpoint alamat/profil) dan tes CORS lama diperbarui.
10. **Audit:** tidak ada audit log untuk CRUD alamat/profil customer (tabel audit bertipe aktor staff). Login tercatat di `customers.last_login_at`.

## Data

Migrasi baru (lanjut nomor):

- `2026_11_01_000005_create_customers_table.php`: `id`, `name` string(255), `phone` string(20) unique, `password` string(255), `is_active` bool default true, `last_login_at` timestamp nullable, timestamps.
- `2026_11_01_000006_create_customer_tokens_table.php`: `id`, `customer_id` FK cascade + index, `token_hash` char(64) unique, `expires_at` index, `last_used_at` nullable, timestamps.
- `2026_11_01_000007_create_customer_addresses_table.php`: `id`, `customer_id` FK cascade, `label` string(40), `recipient_name`, `phone` string(20), `address` text, `area_id` string(64), `notes` text nullable, `is_default` bool default false, timestamps + **partial unique index** `CREATE UNIQUE INDEX customer_addresses_one_default_per_customer ON customer_addresses (customer_id) WHERE is_default` (via `DB::statement`, pola check-constraint repo).
- `2026_11_01_000008_add_customer_id_to_orders_table.php`: `customer_id` FK nullable `restrictOnDelete`, index `(customer_id, created_at)`.

Model `Customer` (`#[Fillable]` hanya `name`, `phone`, `password`, `is_active`, `last_login_at`; `password` hidden + cast `hashed`), `CustomerToken`, `CustomerAddress`. Relasi `Customer::addresses()/orders()/tokens()`, `Order::customer()`.

Alamat maksimal **10 per customer** dan tepat **satu default**: create/update/delete berjalan dalam transaksi dengan `Customer::lockForUpdate()`; set default = matikan semua lalu set satu; `PATCH is_default=false` pada alamat default → 422 (harus tunjuk default lain); hapus default → alamat terlama jadi default.

## Endpoint (`/api/v1`)

| Method       | Path                         | Auth                     | Keterangan                                                                                                                  |
| ------------ | ---------------------------- | ------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| POST         | `/auth/register`             | publik, `customer-auth`  | `{name, phone, password}` → 201 `{customer, token, expires_at}`; HP terdaftar → 422                                         |
| POST         | `/auth/login`                | publik, `customer-auth`  | `{phone, password}` → 200 `{customer, token, expires_at}`; gagal → 422 pesan generik                                        |
| POST         | `/auth/logout`               | bearer                   | hapus token yang dipakai → 204                                                                                              |
| GET          | `/me`                        | bearer                   | `{customer:{id,name,phone,created_at,addresses_count}}`                                                                     |
| PATCH        | `/me`                        | bearer                   | `{name}` saja                                                                                                               |
| GET          | `/me/orders`                 | bearer                   | 10/halaman; ringkasan `{order_number, token, created_at, channel, status, payment_status, delivery_status, total}` + `meta` |
| GET/POST     | `/me/addresses`              | bearer                   | daftar (default dulu) / tambah (pertama otomatis default, >10 → 422)                                                        |
| PATCH/DELETE | `/me/addresses/{address}`    | bearer                   | `->whereNumber()`; milik customer lain → 404                                                                                |
| POST         | `/checkout/quote`, `/orders` | `customer.auth:optional` | guest tetap; bearer valid → `customer_id` + kontak dari profil; bearer invalid → 401                                        |

Limiter baru: `customer-auth` 5/menit per IP; `customer-me` 60/menit per customer (fallback IP). `customer-public`/`customer-checkout` tetap.

401 JSON `{"message":"Silakan masuk terlebih dahulu."}` untuk token hilang/tidak valid/kedaluwarsa/customer nonaktif.

## Keamanan

- Password `Hash::make`, minimal 8, tidak pernah dikembalikan/di-log.
- Token hanya disimpan sebagai hash; `last_used_at` ditulis maksimal sekali per jam (hindari write amplification).
- Login gagal: pesan sama untuk HP tak dikenal dan password salah; tetap jalankan `Hash::check` terhadap dummy hash saat HP tidak ada (samakan timing).
- Semua query riwayat/alamat di-scope dari customer pemilik token; tidak ada `customer_id` dari request.
- Log tanpa nomor HP, alamat, token.
- `customers:prune-tokens` harian 03:00 Asia/Jakarta: hapus token kedaluwarsa saja.

## Non-goal

OTP/SMS, reset password mandiri, verifikasi HP, klaim order tamu lama, ubah nomor HP, alamat berkoordinat/pin map, poin/loyalty, reorder otomatis (client membangun cart dari riwayat via `token`), admin UI customer, UI web/Flutter.

## Tes

- **Register/login:** sukses (password ter-hash, token valid, `last_login_at` terisi); HP duplikat → 422; password < 8 → 422; normalisasi `+62`/`62`/spasi → tersimpan `08...`; password salah → 422 pesan sama dengan HP tak dikenal; akun nonaktif → 422; rate limit 5/menit → 429.
- **Token:** tanpa header → 401; token ngawur → 401; kedaluwarsa → 401; setelah logout → 401; customer nonaktif → 401.
- **Profil:** GET/PATCH; payload `phone`/`is_active`/`role` diabaikan.
- **Riwayat:** hanya order milik customer, tamu tidak muncul, urut terbaru, 10/halaman, `meta` benar.
- **Alamat:** CRUD; maksimal 10; default pertama otomatis; pindah default (tetap satu); `is_default=false` pada default → 422; hapus default → pengganti; alamat/token customer lain → 404.
- **Checkout:** tanpa bearer guest (regresi); bearer valid → `customer_id` + nama/HP dari profil walau body berbeda; bearer invalid → 401 di `/orders` dan `/checkout/quote`; replay key sama customer sama → 200; replay key sama customer lain → 409; order customer tetap dibatalkan `orders:expire-unpaid`.
- **Prune:** hanya token kedaluwarsa terhapus.
- **Regresi:** suite customer API guest, POS, kitchen, laporan tetap hijau; `CatalogApiTest` CORS diperbarui.

## Dokumen

`docs/api.md` bagian "Akun customer" (endpoint, contoh JSON, aturan token, error 401/409, rate limit) + perbaikan klaim "semua rute publik"; `README.md` catatan `customers:prune-tokens`; `PRD.md` menandai P1 akun/riwayat selesai (reset password tetap terbuka).
