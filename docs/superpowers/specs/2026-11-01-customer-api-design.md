# Desain: Customer API (Katalog + Checkout + DOKU + Biteship)

- **Tanggal:** 1 November 2026
- **Status:** Menunggu persetujuan owner
- **Merujuk:** PRD §6 (public ordering), §7.4 Ordering, §7.5 Payments (P1 online payment → sekarang P0 atas keputusan owner), §7.10 Reporting; "Open business decisions" PRD (provider pembayaran, delivery radius/ongkir — sudah diputuskan owner 2026-10-31/11-01)

## Niat & mengapa

Website/HP customer belum punya API. Developer frontend butuh kontrak REST `/api/v1` untuk membangun katalog + checkout customer. Owner memutuskan: bayar **online QRIS lewat DOKU** dan **delivery lewat Biteship**, **ongkir dibayar customer**, semuanya sekaligus (bukan bertahap).

## Keputusan owner (2026-10-31 / 11-01)

1. Pembayaran online/QRIS via **DOKU**, dibayar saat checkout (bukan di tempat).
2. Delivery via **Biteship**; checkout langsung sekaligus dengan delivery (tidak ditunda).
3. **Ongkir dibayar customer** (ditambahkan ke total, dari quote Biteship).
4. Checkout tamu (nama + HP + catatan); login customer = P1.
5. Cart di client (Pinia); client kirim sekali saat checkout; **server hitung ulang harga + ongkir + stok** (aturan AGENTS: harga client tidak dipercaya).

## Desain

### Scaffolding & rute

`bootstrap/app.php` belum mendaftarkan `api:` → tambah `api: __DIR__.'/../routes/api.php'`. `routes/api.php` prefix `v1` (otomatis `api/v1`). Rate limiter baru di `AppServiceProvider`: `customer-public` (60/menit per IP) untuk katalog/quote/order-lookup, `customer-checkout` (10/menit per IP) untuk create order + quote ongkir, webhook tanpa throttle (signature = gerbang). CORS: `config/cors.php` belum ada; default framework mengizinkan `api/*` dari origin `*` tanpa credentials — tambah config dengan `CORS_ALLOWED_ORIGINS` untuk produksi.

| Method | Path                            | Keterangan                                                                                                                                                            |
| ------ | ------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/api/v1/branches`              | cabang aktif: id, nama, alamat, telepon, `opens_at`/`closes_at`, `is_open` (server, Asia/Jakarta), dukungan pickup/delivery                                           |
| GET    | `/api/v1/products?branch_id=`   | produk aktif + tersedia di cabang: kategori, harga, varian, modifier group/opsi, URL gambar; stok tidak dijanjikan (konsumsi bahan saat `Preparing`, sama dengan POS) |
| GET    | `/api/v1/delivery/areas?query=` | proxy pencarian area Biteship (agar API key tak bocor ke frontend)                                                                                                    |
| POST   | `/api/v1/checkout/quote`        | `{branch_id, items[], destination_area_id?}` → opsi kurir Biteship (nama layanan, tarif, estimasi) atau pickup (ongkir 0)                                             |
| POST   | `/api/v1/orders`                | buat order tamu + invoice DOKU; header `Idempotency-Key` wajib                                                                                                        |
| GET    | `/api/v1/orders/{token}`        | status order untuk customer (token acak, bukan nomor urut)                                                                                                            |
| POST   | `/api/v1/webhooks/doku`         | notifikasi DOKU (verifikasi signature, idempotent)                                                                                                                    |
| POST   | `/api/v1/webhooks/biteship`     | update status pengiriman (verifikasi token/signature)                                                                                                                 |

### Alur checkout

1. Validasi: `branch_id` aktif + `is_open` (di luar jam buka → 422 "Cabang sedang tutup."), items (1–99 qty), `fulfillment` ∈ `pickup|delivery`.
2. Harga: `PriceOrderItems` (action yang sama dengan POS — satu lapis logika bisnis) → snapshot nama/varian/modifier/harga di `order_items`.
3. Delivery: server **me-quote ulang** Biteship dengan `origin_area_id` cabang + `destination_area_id` customer + total berat (`weight_grams` per produk, fallback 500 g); cocokkan `courier`+`service` pilihan client; fee dari server (client fee diabaikan). Tidak tersedia → 422. Pickup → fee 0.
4. `total = subtotal + delivery_fee` (integer IDR). Order dibuat lewat action baru `CreateCustomerOrder` (bukan `CreateOrder`: jalur POS wajib user + shift kasir). Reuse `PriceOrderItems` + `GenerateOrderNumber`.
5. Order: `placed_by_id = null` (migrasi: kolom jadi nullable), `shift_id = null`, `payment_status = unpaid`, `status = queued`, `channel = delivery|takeaway`, `customer_name/customer_phone/note/delivery_address/delivery_area_id/delivery_fee` + `public_token` (uuid acak), `idempotency_key` (unik per branch, pola yang sudah ada: retry key sama → order lama dikembalikan).
6. Invoice DOKU dibuat (`CreateDokuPayment`): Jokul Checkout non-SNAP — `POST /checkout/v1/payment` dengan header `Client-Id`, `Request-Id`, `Request-Timestamp`, `Signature` HMAC-SHA256 (komponen `Client-Id`, `Request-Id`, `Request-Timestamp`, `Request-Target`, `Digest`), body `order.amount` + `order.invoice_number` + `payment.payment_due_date` (30 menit); ambil `payment.url` dari response → payment row (`method = doku_qris`, `status = pending`, `shift_id = null`, `provider_reference = invoice number`, `payment_url`, `expires_at` = +30 menit) → response `order` + `payment_url` + `token`.
7. Tidak ada broadcast/kitchen sebelum paid.

### Webhook DOKU

- Verifikasi signature (DOKU non-SNAP): susun `Client-Id:{header}\nRequest-Id:{header}\nRequest-Timestamp:{header}\nRequest-Target:/api/v1/webhooks/doku\nDigest:base64(SHA256(raw body))` → HMAC-SHA256 dengan secret key → base64 → bandingkan dengan header `Signature` (`HMACSHA256=` prefix, `hash_equals`). Salah → 403 tanpa efek.
- Idempotent: kunci = invoice number + status transition (DOKU mengulang event). `SUCCESS` → `SettleDokuPayment`: lock order, isi payment `paid` + `paid_at` (hanya jika masih `pending`), `order.payment_status = paid`, status event, broadcast `OrderPaymentChanged` setelah commit (queue), audit `payment.paid` (actor null, source `doku`). Transisi dari `paid` → 200 tanpa efek.
- `FAILED` **diabaikan** khusus Checkout (DOKU: customer boleh ganti channel); hanya `SUCCESS` yang menandai paid. `EXPIRED`/status tak dikenal → payment tetap, order dibiarkan unpaid; scheduler `orders:expire-unpaid` (tiap 10 menit) membatalkan order unpaid yang lewat `expires_at` (status `cancelled`, tanpa reversal stok karena belum dikonsumsi; webhook SUCCESS sesudahnya tidak menghidupkan order).
- Nominal `order.amount` webhook ≠ `order.total` → 422 + log, tidak menandai paid.
- Respons 2xx segera untuk event valid yang sudah diproses (DOKU retry sampai 6x); 500 hanya bila benar-benar gagal.

### Biteship (delivery)

- Config `services.biteship` (`api_key` → header `Authorization: Bearer {key}`; sandbox `biteship_test.*`; base URL) + `branches.delivery_area_id` (field admin; wajib untuk cabang yang melayani delivery).
- Quote: `POST /v1/rates` (origin/destination area id, courier list, items = berat + nilai). Opsi kurir hanya dikembalikan di response API (tidak disimpan).
- Setelah order `paid` dan dapur menandai `ready` → job `CreateBiteshipShipment` (queue) membuat order kurir (`POST /v1/orders`, `courier_company`/`courier_type` pilihan customer, `delivery_type = now`, origin/destination kontak + alamat + area id, `items` berat/nilai, `reference_id = order_number`); simpan `biteship_order_id`, kurir, `tracking_url`, `delivery_status = requested`. Gagal → `delivery_status = failed` + log + tampil di halaman order (retry manual).
- Webhook Biteship: dashboard mengirim custom headers (Signature Key/Secret yang diisi saat membuat webhook) → cocokkan dengan env (`BITESHIP_WEBHOOK_KEY`/`_SECRET`, `hash_equals`), salah → 403. Event `order.status`/`order.waybill_id`/`order.price` → update `delivery_status` + `tracking_url`. Status `picked`/`dropping_off`/`delivered`/`cancelled`/`returned` dipetakan; `delivered` → order `completed` lewat `TransitionOrderStatus` dengan aktor sistem null (tanpa konsumsi bahan ulang — konsumsi hanya di `Preparing`).
- Customer lihat status + tracking di `GET /orders/{token}`.

### Schema (migrasi baru, `2026_11_01_*`)

- `orders`: `placed_by_id` jadi nullable; `public_token` (uuid, unique), `customer_phone`, `delivery_address`, `delivery_area_id`, `delivery_fee`, `biteship_order_id`, `courier_name`, `courier_service`, `delivery_status`, `tracking_url` (semua nullable kecuali token; delivery fields null untuk pickup).
- `payments`: `provider`, `provider_reference`, `payment_url`, `expires_at` (nullable).
- `products`: `weight_grams` (nullable; fallback 500 di quote).
- `branches`: `delivery_area_id` (nullable).
- Enum: `OrderChannel::Delivery = 'delivery'`; `PaymentMethod::DokuQris = 'doku_qris'`; `PaymentStatus::Pending = 'pending'` (tidak mengubah filter `paid` yang ada).

### Penyesuaian internal (bukan customer UI)

- `OrderPayload`: tambah field delivery + `public_token` hanya di respons customer (bukan payload POS/kitchen — jangan bocorkan `customer_phone`/alamat ke dapur).
- Dapur: `scopeVisibleToKitchen` **sudah** memfilter `payment_status = paid` (Order.php:100-101) — order online unpaid otomatis tidak tampil; tidak perlu ubah.
- `MarkOrderPaid` (jalur POS): payment `pending` DOKU yang sudah ada saat ini dikembalikan diam-diam (terlihat "lunas"); tambah guard → 422 "Pesanan menunggu pembayaran online." (cegah dobel bayar).
- `VoidOrder`: tolak void bila payment punya `provider` (online) → 422 "Pembayaran online tidak dapat di-void. Refund manual lewat dashboard DOKU."
- `TransitionOrderStatus`: `User $actor` dilonggarkan jadi `?User` untuk transisi sistem (webhook delivered); `order_status_events.actor_id` sudah nullable.
- `pos/Orders.vue`: baris online menampilkan asal `Online/QRIS` + status delivery (read-only, tombol bayar kasir tidak muncul untuk order online).
- Laporan: `delivery_fee` masuk total; channel `delivery` muncul otomatis di breakdown channel (`DailySalesQuery` pakai `tryFrom` + group by channel).
- `ProductController` admin: input berat (gram). `BranchController` admin: input `delivery_area_id`.
- `docs/api.md` (kontrak lengkap untuk developer frontend) + README env (`DOKU_*`, `BITESHIP_*`, `CORS_ALLOWED_ORIGINS`).

## Keputusan builder (boleh dikoreksi owner)

1. Fulfillment API: **pickup + delivery** (dine-in tetap lewat POS/kasir).
2. DOKU: **Jokul Checkout (hosted page, non-SNAP)** — signature request & notifikasi terdokumentasi resmi (developers.doku.com / jokul.doku.com), response `payment.url` untuk redirect; kalau akun hanya DOKU Checkout payment link, sesuaikan adapter.
3. Hanya **QRIS** via DOKU (VA/e-wallet/cc = P1).
4. Order customer **tidak bisa dibatalkan customer** setelah dibuat; batal = kasir/owner via jalur eksisting (dan refund online manual via dashboard DOKU — modul refund tetap parkir). Void payment online ditolak sistem.
5. Berat fallback 500 g jika produk belum diisi beratnya (log warning ke owner saat admin membuka produk).
6. Order di luar jam buka ditolak; pesan-untuk-besok (scheduled order) = non-goal.
7. `CreateCustomerOrder` memakai ulang `PriceOrderItems` + `GenerateOrderNumber` (snapshot & penomoran identik POS) + pola idempotency `findExisting`/tangkap unique violation `orders_branch_id_idempotency_key_unique` dari `CreateOrder`.
8. Opsi kurir = kurir/ layanan yang aktif di akun Biteship (GoSend/GrabExpress/lainnya) — daftar final = keputusan ops, bukan hardcode.
9. Notifikasi `FAILED` dari DOKU diabaikan (panduan Checkout: customer boleh retry channel lain); hanya `SUCCESS` yang melunasi.

## Non-goal

Login/akun customer, riwayat order, promo/voucher, tips, COD, scheduled order, tax/service charge, multi-cabang dalam satu cart, aplikasi driver/live GPS, refund otomatis (manual via DOKU dashboard + catatan), UI website/mobile (dikerjakan developer frontend), analytics marketing.

## Tes

- **Katalog:** hanya cabang aktif; `is_open` benar sebelum/dalam/sesudah jam (Asia/Jakarta); produk nonaktif/tidak tersedia cabang tidak muncul; modifier & varian lengkap; tanpa auth.
- **Quote:** pickup fee 0; delivery memakai tarif server (client fee/fake courier ditolak); area invalid → 422; Biteship down → 502 dengan pesan aman.
- **Checkout sukses:** total server = subtotal + ongkir; snapshot item benar; `public_token` unik/acak; payment `pending` + `payment_url`; retry `Idempotency-Key` sama → order sama (tidak dobel); beda branch key sama → order beda.
- **Checkout gagal:** cabang tutup → 422; item produk cabang lain/tidak aktif → 422; qty 0/100 → 422; ongkir berubah di server vs client → 422; tanpa `Idempotency-Key` → 422.
- **Webhook DOKU:** signature salah (termasuk digest salah) → 403 tanpa efek; SUCCESS → paid + broadcast after commit + audit; replay SUCCESS → 200 tanpa efek ganda; FAILED → tidak paid (diabaikan); EXPIRED → tidak paid; nominal beda → tidak paid; order sudah paid → aman; SUCCESS setelah order di-expire scheduler → tidak menghidupkan order.
- **POS tidak bisa bayar dobel:** `MarkOrderPaid` untuk order dengan payment DOKU `pending` → 422; `VoidOrder` untuk payment ber-`provider` → 422.
- **Expiry:** order unpaid lewat 30 menit → cancelled oleh scheduler; order paid tidak disentuh.
- **Biteship:** order ready + paid → job membuat shipment (fake Http, assert `reference_id`/`delivery_type`/weight); gagal → `delivery_status=failed` (order tetap); webhook header Signature Key/Secret benar → update status; header salah → 403; delivered → order completed (tanpa konsumsi bahan kedua kali).
- **Keamanan:** token salah → 404; rate limit checkout 429; log tidak memuat nomor HP/alamat; dapur tidak melihat order unpaid customer; cabang A tidak bocor ke B.
- **Regresi:** POS create order & bayar tunai/QRIS statis tetap jalan; `OrderPayload` lama tidak berubah bentuk untuk POS/kitchen; `CalculateShiftTotals` tidak berubah (payment online `shift_id` null).

## Prasyarat & konfirmasi ops

- Akun DOKU (sandbox) + Client-Id/Secret + pasang Notification URL ke `/api/v1/webhooks/doku`; akun Biteship + API key + **saldo** (Biteship menagih per pengiriman; customer sudah bayar ongkir ke kita via DOKU — arus kas: kita bayar Biteship belakangan).
- Biteship: buat webhook di dashboard dengan Headers Signature Key/Secret + event `order.status`, `order.waybill_id`; aktifkan kurir yang diinginkan.
- Data: berat tiap produk, alamat lengkap + area id Biteship 2 cabang, nomor HP cabang.
- Kebijakan bila pesanan sudah paid tapi bahan habis/delivery gagal: dapur/owner batalkan manual + refund manual DOKU (di luar sistem) — konfirmasi owner.
- Dine-in lewat API tidak ada (customer hanya pickup/delivery) — konfirmasi owner.
