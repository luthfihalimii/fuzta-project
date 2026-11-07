# Kontrak API Customer — Pizza Fruzta

Dokumen ini adalah kontrak REST untuk developer frontend (website/aplikasi customer). Spesifikasi mesin tersedia di [`openapi.yaml`](openapi.yaml) (OpenAPI 3.0). Route katalog & checkout tamu publik (tanpa autentikasi); endpoint akun customer (`/me*`, `/auth/logout`) menuntut token bearer (lihat **Autentikasi customer** di bawah). Sumber kebenaran perilaku = kode (`routes/api.php` + modul `app/Modules`); bila dokumen ini dan kode berbeda, kode yang benar.

## Dasar

- **Base URL:** `https://<domain>/api/v1` (produksi) atau `http://127.0.0.1:8000/api/v1` (dev). Semua path di bawah ditulis relatif base URL.
- **Format:** JSON (`Content-Type: application/json`).
- **Uang:** integer IDR (satuan rupiah). `15000` = Rp 15.000. Tarif Biteship yang desimal dibulatkan ke integer saat di-quote.
- **Server otoritatif:** harga, ongkir, dan total selalu dihitung ulang di server. Nilai harga/ongkir/total yang dikirim client diabaikan; yang diproses hanyalah pilihan produk/varian/opsi + kurir.
- **CORS:** origin yang diizinkan diatur oleh env `CORS_ALLOWED_ORIGINS`; default dev hanya `localhost`, produksi wajib diisi domain frontend (jangan `*`).

### Autentikasi customer

Endpoint akun (`/auth/logout`, `/me`, `/me/orders`, `/me/addresses`) menuntut token bearer dari `POST /auth/register` atau `POST /auth/login`:

```
Authorization: Bearer <token>
```

Aturan token:

- Opaque, 40 karakter; dikembalikan server **sekali** di respons. DB menyimpan hash SHA-256-nya saja.
- Berlaku **30 hari** dari penerbitan (lihat `expires_at` di respons).
- Setiap register/login menerbitkan token baru; token lama tetap valid sampai kedaluwarsa.
- `POST /auth/logout` mencabut **token yang dipakai saja**; token lain customer yang sama tetap valid.
- Simpan token dengan aman di sisi client; **jangan pernah di-log**.

`POST /checkout/quote` dan `POST /orders` **opsional** autentikasi: guest tetap bisa checkout; bila header `Authorization` dikirim, bearer harus valid (`401` bila tidak), dan bearer valid menghubungkan order ke akun (lihat `## 9. Akun customer`).

### Format error

Semua error memakai format standar Laravel:

```json
{
    "message": "Pesan ringkas.",
    "errors": {
        "customer_phone": [
            "Format nomor HP tidak valid (contoh: 081234567890)."
        ]
    }
}
```

`errors` hanya ada jika validasi menolak; selain itu cukup `message`.

### Status HTTP

| Kode  | Arti                                                                                                                                                                                                                                                       |
| ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `200` | Sukses (juga untuk replay `Idempotency-Key` yang sudah pernah dipakai).                                                                                                                                                                                    |
| `201` | Order baru berhasil dibuat.                                                                                                                                                                                                                                |
| `401` | Token bearer hilang/tidak valid/kedaluwarsa/tercabut, atau akun customer nonaktif (di `/me*`, `/auth/logout`, dan `POST /checkout/quote`/`POST /orders` bila bearer dikirim). Pesan: `Silakan masuk terlebih dahulu.`                                      |
| `404` | Resource tidak ditemukan (token order tidak dikenal, dsb.)                                                                                                                                                                                                 |
| `409` | `Idempotency-Key` sudah dipakai pemilik lain (customer lain, atau guest vs customer). Pesan: `Kunci idempotensi sudah dipakai.` — tidak ada order yang dibuat maupun diubah.                                                                               |
| `422` | Validasi / aturan bisnis gagal (cabang tutup, produk tidak tersedia, kurir tak cocok, area tak dikenali, `Idempotency-Key` kosong, dsb.).                                                                                                                  |
| `429` | Rate limit terlampaui.                                                                                                                                                                                                                                     |
| `502` | Provider upstream (Biteship/DOKU) tidak tersedia (down/http client menolak=miskonfigurasi sisi kita). Tampilkan "layanan sedang tidak tersedia, coba lagi nanti"; untuk `/orders`, retry dengan `Idempotency-Key` yang sama — order lama tidak akan dobel. |
| `500` | Error internal server (hanya webhook: provider akan retry).                                                                                                                                                                                                |

### Rate limit

| Limiter                   | Batas                         | Berlaku untuk                                                                                                                          |
| ------------------------- | ----------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| `customer-public`         | 60 request/menit per IP       | `GET /branches`, `GET /products`, `GET /delivery/areas`, `GET /orders/{token}`                                                         |
| `customer-checkout`       | 10 request/menit per IP       | `POST /checkout/quote`, `POST /orders`                                                                                                 |
| `customer-auth`           | 5 request/menit per IP        | `POST /auth/register`, `POST /auth/login`, `POST /auth/forgot-password` (berbagi satu bucket)                                          |
| `customer-password-reset` | 10 request/menit per IP       | `POST /auth/reset-password`                                                                                                            |
| `customer-me`             | 60 request/menit per customer | `POST /auth/logout`, `GET/PATCH /me`, `GET /me/orders`, `GET/POST /me/addresses`, `PATCH/DELETE /me/addresses/{id}` (fallback: per IP) |

Webhook tidak di-throttle (verifikasi signature adalah gerbangnya).

### Daftar endpoint

| Method | Path                     | Keterangan                                                                                                           |
| ------ | ------------------------ | -------------------------------------------------------------------------------------------------------------------- |
| GET    | `/branches`              | Cabang aktif (jam buka & status buka hari ini).                                                                      |
| GET    | `/products?branch_id=`   | Menu cabang: kategori, varian, opsi (modifier).                                                                      |
| GET    | `/delivery/areas?query=` | Pencarian area Biteship (untuk memilih alamat pengiriman).                                                           |
| POST   | `/checkout/quote`        | Perkiraan ongkir + opsi kurir untuk keranjang. Auth opsional (lihat **Autentikasi customer**).                       |
| POST   | `/orders`                | Buat order customer + invoice DOKU (header `Idempotency-Key` wajib). Auth opsional (lihat **Autentikasi customer**). |
| GET    | `/orders/{token}`        | Cek status order customer via token (tanpa auth).                                                                    |
| POST   | `/auth/register`         | Daftar akun customer + terbitkan token (bagian `## 9`).                                                              |
| POST   | `/auth/login`            | Login + terbitkan token (bagian `## 9`).                                                                             |
| POST   | `/auth/forgot-password`  | Kirim kode OTP reset password ke email (respons generik `202`).                                                      |
| POST   | `/auth/reset-password`   | Reset password dengan OTP email; mencabut semua token customer.                                                      |
| POST   | `/auth/logout`           | **Bearer** — cabut token yang dipakai → 204.                                                                         |
| GET    | `/me`                    | **Bearer** — profil customer.                                                                                        |
| PATCH  | `/me`                    | **Bearer** — ubah nama profil.                                                                                       |
| GET    | `/me/orders`             | **Bearer** — riwayat order (10/halaman).                                                                             |
| GET    | `/me/addresses`          | **Bearer** — daftar alamat tersimpan.                                                                                |
| POST   | `/me/addresses`          | **Bearer** — tambah alamat tersimpan (maks 10).                                                                      |
| PATCH  | `/me/addresses/{id}`     | **Bearer** — ubah alamat (semantik PATCH).                                                                           |
| DELETE | `/me/addresses/{id}`     | **Bearer** — hapus alamat → 204.                                                                                     |
| POST   | `/me/devices`            | **Bearer** — daftarkan/refresh token FCM (idempoten).                                                                |
| DELETE | `/me/devices/{id}`       | **Bearer** — hapus device FCM → 204.                                                                                 |
| POST   | `/webhooks/doku`         | **Internal** — notifikasi pembayaran DOKU. Jangan dipanggil frontend.                                                |
| POST   | `/webhooks/biteship`     | **Internal** — update status pengiriman Biteship. Jangan dipanggil frontend.                                         |

Auth opsional pada `POST /checkout/quote` / `POST /orders`: tanpa token tetap jalan (guest). Bila ada token, harus valid (`401` bila tidak); bila valid, order terhubung ke akun dan kontak diambil dari profil.

---

## 1. `GET /branches`

Cabang aktif, diurutkan nama. `is_open` dihitung server (zona `Asia/Jakarta`, batas tutup eksklusif). `supports_delivery` = `true` bila cabang sudah dikonfigurasi area Biteship (`delivery_area_id`); frontend bisa memangkas pilihan delivery berdasarkan field ini.

Respons `200`:

```json
{
    "branches": [
        {
            "id": 1,
            "code": "PF-01",
            "name": "Pizza Fruzta Kemang",
            "address": "Jl. Kemang Raya No. 1, Jakarta Selatan",
            "phone": "081234567890",
            "opens_at": "10:00",
            "closes_at": "22:00",
            "is_open": true,
            "supports_delivery": true
        }
    ]
}
```

## 2. `GET /products?branch_id={id}`

Katalog cabang: hanya produk aktif dan tersedia di cabang itu (pivot ketersediaan), diurutkan nama. `branch_id` wajib; cabang tidak ada/tidak aktif → `422`. Produk yang terdaftar tapi tidak aktif (atau tidak tersedia di cabang) tidak muncul.

Respons `200`:

```json
{
    "products": [
        {
            "id": 12,
            "name": "Pizza Margherita",
            "description": "Keju mozzarella, saus tomat, basil.",
            "category": { "id": 1, "name": "Pizza" },
            "base_price": 90000,
            "weight_grams": 750,
            "image_url": "https://.../margherita.jpg",
            "variants": [
                { "id": 1, "name": "S", "price": 90000 },
                { "id": 2, "name": "L", "price": 140000 }
            ],
            "modifier_groups": [
                {
                    "id": 3,
                    "name": "Topping tambahan",
                    "modifiers": [
                        { "id": 7, "name": "Nugget", "price_delta": 15000 },
                        { "id": 8, "name": "Keju ekstra", "price_delta": 20000 }
                    ]
                }
            ]
        }
    ]
}
```

Catatan:

- `price`/`base_price`/`price_delta` = integer IDR. Produk yang punya varian aktif: `variant_id` wajib saat checkout (`422` "Pilih varian produk ini."); harga satuan = harga varian (menggantikan `base_price`). Modifier ditambahkan sebagai `price_delta` per item.
- Stok tidak digaransi di sini; bila bahan habis saat pesanan diproses, dapur/owner batalkan (lihat bagian "Batasan" di bawah).

## 3. `GET /delivery/areas?query={teks}`

Proxy ke pencarian area Biteship (agar API key tidak bocor ke frontend). `query` wajib, string 2–100 karakter (mis. "Kemang"). Nilai yang dikirim Biteship diteruskan apa adanya (field bisa `null`).

Respons `200`:

```json
{
    "areas": [
        {
            "id": "IDJP10",
            "name": "Kemang",
            "city": "Jakarta Selatan",
            "province": "DKI Jakarta",
            "postal_code": "12730"
        }
    ]
}
```

`502` bila Biteship tak bisa dihubungi: `{"message": "Layanan pengiriman sedang tidak tersedia."}`.

## 4. `POST /checkout/quote`

Perkiraan ongkir + daftar opsi kurir untuk sebuah keranjang. **Server me-quote ulang Biteship** dengan area asal cabang + area tujuan + berat item (`weight_grams` produk, fallback 500 g). Pickup tidak pernah memanggil Biteship.

Request:

```json
{
    "branch_id": 1,
    "fulfillment": "delivery",
    "destination_area_id": "IDJP10",
    "items": [
        { "product_id": 12, "variant_id": 1, "quantity": 1 },
        {
            "product_id": 15,
            "modifier_ids": [7, 8],
            "quantity": 2,
            "note": "tanpa bawang"
        }
    ]
}
```

Field:

| Field                 | Aturan                                                                                                                                                                                        |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `branch_id`           | wajib, harus cabang aktif.                                                                                                                                                                    |
| `fulfillment`         | wajib, `pickup` atau `delivery`.                                                                                                                                                              |
| `destination_area_id` | wajib jika `delivery` (max 64); diabaikan untuk `pickup`.                                                                                                                                     |
| `items`               | 1–100 item; per item: `product_id` (wajib, produk aktif + tersedia di cabang itu), `variant_id` (opsi), `modifier_ids[]` (opsi, id yang distinct), `quantity` (1–99), `note` (opsi, max 255). |

Respons `200`:

```json
{
    "options": [
        {
            "courier_name": "Gojek",
            "courier_service": "Instant",
            "fee": 15000,
            "etd": "1 - 2 hours"
        },
        {
            "courier_name": "JNE",
            "courier_service": "Yes",
            "fee": 21000,
            "etd": "1 - 2 days"
        }
    ]
}
```

Untuk `pickup` selalu satu opsi tetap (tanpa panggilan Biteship):

```json
{
    "options": [
        {
            "courier_name": "Ambil sendiri",
            "courier_service": "pickup",
            "fee": 0,
            "etd": null
        }
    ]
}
```

Error: `422` — "Cabang tidak aktif." / "Cabang ini tidak melayani pengiriman." / "Alamat pengiriman tidak dikenali." (Biteship menolak area, 4xx dari provider) / "Tidak ada kurir untuk alamat ini." (rate list kosong); `502` bila Biteship tak terhubung / 401/403/429/5xx dari provider.

## 5. `POST /orders`

Buat order customer (tamu: nama + HP) + invoice DOKU. **Header `Idempotency-Key` wajib** (lihat aturan di bawah).

### Aturan `Idempotency-Key`

- Header `Idempotency-Key`, string non-kosong, maks 100 karakter. Kosong/terlalu panjang → `422`.
- Kunci unik per `(branch_id, idempotency_key)` — **bukan global**: key yang sama di cabang berbeda membuat order berbeda (legal).
- Request pertama dengan key baru → `201` + order baru.
- Request ulang dengan key yang sama di cabang yang sama → `200` + **order yang sama** (tidak ada order ke-2; harga/ongkir tidak dihitung ulang lagi untuk order lama).
- Bila respons pertama `502` (DOKU down), **retry dengan key yang sama** — order lama dikembalikan dan invoice dicoba lagi, tidak ada order/invoice dobel.
- Saran frontend: pakai UUID yang di-generate sekali per checkout, simpan di sesi keranjang.

Request:

```json
{
    "branch_id": 1,
    "fulfillment": "delivery",
    "customer_name": "Budi Santoso",
    "customer_phone": "081234567890",
    "note": "Pickup Sabtu",
    "delivery_address": "Jl. Kemang Raya No. 10, Jakarta Selatan",
    "delivery_area_id": "IDJP10",
    "courier_name": "Gojek",
    "courier_service": "Instant",
    "items": [
        { "product_id": 12, "variant_id": 1, "quantity": 1 },
        { "product_id": 15, "modifier_ids": [7], "quantity": 2 }
    ]
}
```

Field:

| Field                              | Aturan                                                                                  |
| ---------------------------------- | --------------------------------------------------------------------------------------- |
| `branch_id`                        | wajib, cabang harus aktif **dan sedang buka** ("Cabang sedang tutup." → `422`).         |
| `fulfillment`                      | `pickup` atau `delivery`.                                                               |
| `customer_name`                    | wajib, max 255.                                                                         |
| `customer_phone`                   | wajib, format `08xxxxxxxxxx` (regex `^08[0-9]{8,13}$`).                                 |
| `note`                             | opsional, max 500.                                                                      |
| `delivery_address`                 | wajib jika `delivery`, max 500.                                                         |
| `delivery_area_id`                 | wajib jika `delivery` (hasil dari `/delivery/areas`).                                   |
| `courier_name` + `courier_service` | wajib jika `delivery`; pasangan persis harus ada di opsi yang terakhir di-quote server. |
| `items`                            | sama seperti `/checkout/quote`.                                                         |

Catatan penting:

- Ongkir **tidak perlu dan tidak boleh** dikirim client; server me-quote ulang saat request (fee hasil quote terakhir yang dipakai). `courier_name`/`courier_service` yang tidak cocok dengan opsi server → `422` "Kurir atau layanan tidak tersedia untuk alamat ini."
- Total = `subtotal + delivery_fee` (integer), dihitung server.
- Order dibuat dengan `payment_status = unpaid`, `status = queued`; untuk `pickup` channel disimpan `takeaway`, untuk `delivery` `delivery`.
- Setelah order dibuat, invoice DOKU dibuat otomatis (QRIS). `FAILED`/`EXPIRED` dari DOKU **tidak** menandai order gagal — hanya webhook `SUCCESS` yang melunasi; order unpaid yang lewat jatuh tempo dibatalkan oleh scheduler (`orders:expire-unpaid`, tiap 10 menit).

Respons `201` (baru) / `200` (replay key):

```json
{
    "order": {
        "order_number": "PF-PF-01-20261101-0001",
        "branch_id": 1,
        "token": "6f1e2a84-9b7c-4d1e-8f2a-1c9b3d5e7f10",
        "channel": "delivery",
        "status": "queued",
        "payment_status": "unpaid",
        "subtotal": 140000,
        "delivery_fee": 15000,
        "total": 155000,
        "customer_name": "Budi Santoso",
        "customer_phone": "081234567890",
        "note": null,
        "delivery_address": "Jl. Kemang Raya No. 10, Jakarta Selatan",
        "courier_name": "Gojek",
        "courier_service": "Instant",
        "delivery_status": null,
        "tracking_url": null,
        "created_at": "2026-11-01T12:00:00+07:00",
        "items": [
            {
                "product_name": "Pizza Margherita",
                "variant_name": "S",
                "quantity": 1,
                "unit_price": 110000,
                "line_total": 110000,
                "note": null,
                "modifiers": [
                    { "modifier_name": "Keju ekstra", "price_delta": 20000 }
                ]
            },
            {
                "product_name": "Soft Drink 330ml",
                "variant_name": null,
                "quantity": 2,
                "unit_price": 15000,
                "line_total": 30000,
                "note": "iceless",
                "modifiers": []
            }
        ]
    },
    "token": "6f1e2a84-9b7c-4d1e-8f2a-1c9b3d5e7f10",
    "payment_url": "https://checkout.doku.com/checkout-link-v2/XXXX",
    "payment_expires_at": "2026-11-01T12:30:00+07:00"
}
```

- `token` = `public_token` order. Frontend menyimpannya (mis. di sesi keranjang) dan memakainya di `GET /orders/{token}`. Token = akses baca order — jangan tampilkan ke publik di luar layar status milik customer.
- `payment_url` = halaman bayar DOKU (redirect customer). `payment_expires_at` = batas bayar (default 30 menit, env `DOKU_PAYMENT_DUE_MINUTES`).
- Bila DOKU down saat itu → `502`:

```json
{
    "message": "Pembayaran online sedang tidak tersedia. Coba lagi dengan Idempotency-Key yang sama.",
    "token": "6f1e2a84-9b7c-4d1e-8f2a-1c9b3d5e7f10"
}
```

Order sudah tersimpan (unpaid); retry dengan key yang sama akan mengembalikan order yang sama.

## 6. `GET /orders/{token}`

Cek status order untuk customer, pakai token dari respons create order. Tanpa auth. Token acak (uuid) — tidak bisa ditebak/di-enum.

Respons `200`:

```json
{
    "order": { "...": "sama bentuk dengan respons /orders (field order)" }
}
```

Payload adalah bentuk **customer-safe**: tidak memuat `placed_by_id`, `shift_id`, `idempotency_key`, `biteship_order_id`, atau id order internal. `404` bila token tak dikenal (JSON `{"message": "..."}`) — jangan perlakukan sebagai error auth.

## 7. `POST /webhooks/doku` — internal

**Jangan dipanggil frontend.** DOKU mem-post notifikasi ke URL ini (harus terdaftar di dashboard DOKU, path = `DOKU_NOTIFICATION_PATH`, default `/api/v1/webhooks/doku`).

- Verifikasi: header `Signature` (`HMACSHA256=...`) dihitung ulang dari `Client-Id`, `Request-Id`, `Request-Timestamp`, `Request-Target`, `Digest` (SHA-256 base64 body) dengan `DOKU_SECRET_KEY`; `hash_equals`. Gagal → `403` tanpa efek.
- `body` (ringkas): `transaction.status`, `order.invoice_number`, `order.amount`.
- Perilaku: `SUCCESS` + nominal = total order → pesanan dilunasi (idempotent; replay aman → `200`). Nominal tidak cocok / invoice tak dikenal → `422`. `FAILED`/`EXPIRED`/lainnya → `200` tanpa efek (hanya `SUCCESS` yang melunasi — customer boleh mencoba channel lain). Error DB → `500` (DOKU retry sampai 6x).

## 8. `POST /webhooks/biteship` — internal

**Jangan dipanggil frontend.** Biteship mengirim event (`order.status`, `order.waybill_id`, dsb.) ke URL ini (dashboard Biteship → Integrations → Webhook).

- Verifikasi: satu header custom. **Nama header = env `BITESHIP_WEBHOOK_KEY`** (default `X-Biteship-Signature`), **isi header = env `BITESHIP_WEBHOOK_SECRET`**; dibanding `hash_equals`. Salah/hilang/secret kosong di server → `403` tanpa efek. Saat membuat webhook di dashboard, isi field _header name_ dan _header value_ dengan kedua nilai yang sama persis.
- `body` membawa `order_id` (= `biteship_order_id`) + opsional `status`, `courier_waybill_id`.
- Perilaku: `order_id` tak dikenal → `404`; payload tanpa `order_id` → `422`; event valid → `200` (event boleh diulang Biteship; handler idempotent). `status` yang dikenal disimpan ke `delivery_status`; `courier_waybill_id` → `tracking_url` (`https://track.biteship.com/{waybill}`); `delivered` → order selesai (`completed`).

## 9. Akun customer

Customer masuk dengan nomor HP + password. Reset password mandiri memakai kode OTP 6 digit yang dikirim ke **email** customer (provider Mailketing). Aturan token diringkas di **Autentikasi customer** di atas.

Semua endpoint ber-bearer mengembalikan `401` `{"message": "Silakan masuk terlebih dahulu."}` bila token hilang/tidak valid/kedaluwarsa/tercabut, atau akun customer tidak aktif.

### `POST /auth/register`

Publik (limiter `customer-auth`). Registration + penerbitan token dalam satu request.

Request:

```json
{
    "name": "Budi Santoso",
    "email": "budi@example.com",
    "phone": "081234567890",
    "password": "rahasia123"
}
```

Field:

| Field      | Aturan                                                                                                                                                        |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `name`     | wajib, string, max 255.                                                                                                                                       |
| `email`    | wajib, format email valid, max 255, unik; server menormalisasi ke huruf kecil. Dipakai untuk reset password.                                                  |
| `phone`    | wajib; menerima `08…`, `62…`, `+62…`, spasi/tanda hubung; server menormalisasi ke `08…` **sebelum** validasi (regex `^08\d{8,12}$`). Tersimpan sebagai `08…`. |
| `password` | wajib, minimal 8 karakter (kurang → `422` `Password minimal 8 karakter.`). Tidak pernah dikembalikan atau di-log.                                             |

Nomor sudah terdaftar → `422` pada `phone`: `Nomor HP sudah terdaftar.` Email sudah terdaftar → `422` pada `email`: `Email sudah terdaftar.`

Respons `201`:

```json
{
    "customer": {
        "id": 7,
        "name": "Budi Santoso",
        "phone": "081234567890",
        "email": "budi@example.com",
        "created_at": "2026-11-01T12:00:00+07:00"
    },
    "token": "vJ8kQ2pXwN4mR6tYzL0cB7dFgH3sA9eK1uWqP5oDx",
    "expires_at": "2026-12-01T12:00:00+07:00"
}
```

`token` (40 char) hanya muncul sekali di respons. `expires_at` = diterbitkan + 30 hari.

### `POST /auth/login`

Publik (limiter sama, berbagi bucket dengan register). Request: `{ "phone": "081234567890", "password": "rahasia123" }` (normalisasi HP sama seperti register).

- Sukses → `200`, bentuk sama seperti respons register (**token baru diterbitkan** setiap login; token lama tetap valid sampai kedaluwarsa).
- Password salah **atau** nomor tak dikenal → `422` pada `phone`: `Nomor HP atau password salah.` (pesan sengaja sama untuk keduanya).
- Password benar tapi akun tidak aktif → `422` pada `phone`: `Akun tidak aktif.`

### `POST /auth/forgot-password`

Publik (limiter `customer-auth`). Request:

```json
{ "email": "budi@example.com" }
```

- Selalu `202` dengan pesan sama, baik email terdaftar maupun tidak:
  `{ "message": "Jika email terdaftar, kode reset sudah dikirim." }`
  Ini mencegah enumerasi akun.
- Bila email terdaftar dan akun aktif: kode OTP 6 digit dibuat (berlaku 10 menit) dan dikirim ke email lewat Mailketing.
- Kirim ulang dalam 60 detik untuk akun yang sama tidak mengirim email baru (tetap `202`).
- Format email tidak valid → `422` pada `email`.

### `POST /auth/reset-password`

Publik (limiter `customer-password-reset`). Request:

```json
{
    "email": "budi@example.com",
    "code": "123456",
    "password": "passwordbaru123",
    "password_confirmation": "passwordbaru123"
}
```

Field: `email` wajib; `code` wajib 6 digit; `password` wajib minimal 8 karakter + `password_confirmation` harus sama.

- Sukses → `200` `{ "message": "Password berhasil diubah. Silakan masuk kembali." }`.
- Password diganti, kode dihapus, dan **semua token bearer customer dicabut** — semua perangkat harus login ulang.
- Kode salah/kedaluwarsa/tidak ada, atau email tak dikenal → `422` pada `code` dengan pesan sama:
  `Kode reset tidak valid atau sudah kedaluwarsa.` (tidak membocorkan apakah email terdaftar).
- Kode hangus setelah 5 percobaan salah.

### `POST /auth/logout` — bearer

Tanpa body. Mencabut **token yang dipakai request ini saja**; token lain milik customer yang sama tetap valid. Respons `204` (tanpa body). Token yang sudah dicabut → `401`.

### `GET /me` — bearer

Respons `200`:

```json
{
    "customer": {
        "id": 7,
        "name": "Budi Santoso",
        "phone": "081234567890",
        "email": "budi@example.com",
        "created_at": "2026-11-01T12:00:00+07:00",
        "addresses_count": 2
    }
}
```

### `PATCH /me` — bearer

Hanya `name` yang diterima (wajib, max 255); field lain di body diabaikan. **Nomor HP tidak bisa diubah via API.** Respons `200` = bentuk `GET /me`.

### `GET /me/orders` — bearer

```
GET /me/orders?page=2
```

Respons `200`:

```json
{
    "orders": [
        {
            "order_number": "PF-PF-01-20261101-0001",
            "token": "6f1e2a84-9b7c-4d1e-8f2a-1c9b3d5e7f10",
            "created_at": "2026-11-01T12:00:00+07:00",
            "channel": "delivery",
            "status": "queued",
            "payment_status": "unpaid",
            "delivery_status": null,
            "total": 155000
        }
    ],
    "meta": {
        "current_page": 2,
        "last_page": 4,
        "per_page": 10,
        "total": 33
    }
}
```

- 10/halaman, order terbaru dulu.
- Hanya order yang dibuat **sambil login** ke akun ini. Order tamu (termasuk yang nomor HP-nya sama) tidak pernah muncul dan tidak pernah diklaim.
- `token` di tiap baris bisa dipakai di `GET /orders/{token}` untuk detail lengkap order.

### Alamat tersimpan `/me/addresses` — bearer

Setiap operasi mengembalikan `404` untuk id alamat milik customer lain (tidak ada bocoran keberadaan).

Field:

| Field            | Aturan                                                        |
| ---------------- | ------------------------------------------------------------- |
| `label`          | wajib (`POST`), max 40.                                       |
| `recipient_name` | wajib (`POST`), max 255.                                      |
| `phone`          | wajib (`POST`), format `08…` (normalisasi sama seperti akun). |
| `address`        | wajib (`POST`), max 500.                                      |
| `area_id`        | wajib (`POST`), max 64 (hasil `GET /delivery/areas`).         |
| `notes`          | opsional, max 500.                                            |
| `is_default`     | `POST` opsional boolean; `PATCH` opsional boolean.            |

`GET /me/addresses` → `200`, diurutkan default dulu, lalu lama dulu:

```json
{
    "addresses": [
        {
            "id": 3,
            "label": "Rumah",
            "recipient_name": "Budi Santoso",
            "phone": "081234567890",
            "address": "Jl. Kemang Raya No. 10, Jakarta Selatan",
            "area_id": "IDJP10",
            "notes": "Beli di depan, ada gerbang",
            "is_default": true
        }
    ]
}
```

`POST /me/addresses` → `201` `{ "address": { "...": "field di atas + id" } }`:

- Alamat pertama otomatis menjadi default.
- Penambahan berikutnya: `is_default: true` memindah default (default lama jadi `false`) — maksimal satu default.
- Maksimal 10 alamat per customer → `422` pada `addresses`: `Maksimal 10 alamat tersimpan.`

`PATCH /me/addresses/{id}` → `200` bentuk `{ "address": { ... } }`:

- Semantik PATCH: hanya field yang dikirim yang divalidasi dan ditulis.
- `is_default: false` pada alamat yang sedang default → `422` pada `is_default`: `Pilih alamat default lain sebelum menonaktifkan alamat ini.`
- `is_default: true` = memindah default ke alamat ini.

`DELETE /me/addresses/{id}` → `204`:

- Default yang dihapus → alamat terlama dari sisa menjadi default (bila masih ada).

Catatan frontend: alamat tersimpan **tidak** otomatis terisi ke checkout — `POST /orders` tetap perlu `delivery_address`/`delivery_area_id`/kurir (frontend boleh prefill dari alamat tersimpan).

### Device FCM `/me/devices` — bearer

Endpoint ini menyimpan token registrasi FCM dan dipakai server untuk mengirim push status order (`accepted`, `ready`, `cancelled`) bila kredensial Firebase tersedia. Status order tetap bisa diambil lewat API; push bukan satu-satunya sumber kebenaran.

Field:

| Field      | Aturan                                                     |
| ---------- | ---------------------------------------------------------- |
| `token`    | wajib, string max 512; token registrasi FCM dari Firebase. |
| `platform` | wajib, `android` atau `ios`.                               |

`POST /me/devices` → `200`:

```json
{
    "device": {
        "id": 7,
        "platform": "android",
        "last_seen_at": "2026-11-07T12:00:00+07:00"
    }
}
```

- Idempoten: token yang sama memperbarui baris yang ada, bukan menambah duplikat.
- Token yang sama dipakai akun lain (perangkat berganti akun) dipindahkan ke akun yang sedang login, sehingga notifikasi tidak lintas akun.
- `token` tidak pernah dikembalikan di respons.

`DELETE /me/devices/{id}` → `204`; id milik customer lain → `404`.

### Checkout dengan bearer

`POST /checkout/quote` dan `POST /orders` menerima `Authorization: Bearer` opsional:

- Tanpa token → jalur guest tak berubah (`customer_name`/`customer_phone` dari body).
- Token tidak valid/kedaluwarsa/tercabut → `401` (ditolak di middleware; request tidak diproses).
- Token valid:
    - `customer_name` & `customer_phone` diambil dari **profil**; nilai di body **diabaikan** (boleh tidak dikirim).
    - `customer_id` **tidak pernah** dipercaya dari body — server menetapkan dari token saja.
    - Order tercatat `customer_id` = akun, sehingga muncul di `GET /me/orders`.
    - Aturan `Idempotency-Key` (bagian 5) tetap, dengan **tambahan**: replay key yang sudah dipakai pemilik berbeda (customer lain, atau guest vs customer) → `409`: `Kunci idempotensi sudah dipakai.` — tidak ada order yang dibuat, order lama tidak disentuh. Replay pemilik sama tetap `200` (order yang sama, aturan DOKU-502 tetap berlaku).
    - Order customer tetap dibatalkan `orders:expire-unpaid` bila melewati jatuh tempo, sama seperti order tamu.

### Batasan

- Reset password mandiri lewat OTP email; belum ada verifikasi nomor HP/email saat pendaftaran (P1).
- Order tamu lama tidak pernah dihubungkan ke akun, walau nomor HP sama.
- Profil hanya bisa mengubah `name`; nomor HP akun tetap seperti saat daftar.

---

## Status (nilai string)

### `status` order

| Nilai       | Label      |
| ----------- | ---------- |
| `queued`    | Antrian    |
| `accepted`  | Diterima   |
| `preparing` | Disiapkan  |
| `ready`     | Siap       |
| `completed` | Selesai    |
| `cancelled` | Dibatalkan |

Online order baru selalu masuk `queued`; dapur hanya memproses yang sudah `paid`. `delivered` (webhook Biteship) langsung memindahkan ke `completed` bila graf status mengizinkan.

### `payment_status`

| Nilai     | Label               | Catatan                                                                            |
| --------- | ------------------- | ---------------------------------------------------------------------------------- |
| `unpaid`  | Belum dibayar       | Status baru; dibatalkan scheduler bila lewat jatuh tempo.                          |
| `pending` | Menunggu pembayaran | Invoice DOKU sudah dibuat, menunggu customer bayar.                                |
| `paid`    | Lunas               | Hanya via webhook DOKU `SUCCESS` (order online tak bisa dilunasi dari POS).        |
| `voided`  | Dibatalkan          | (jalur POS; order online tak di-void otomatis — refund manual via dashboard DOKU.) |

### `channel`

| Nilai      | Arti                               |
| ---------- | ---------------------------------- |
| `takeaway` | Checkout `fulfillment = pickup`.   |
| `delivery` | Checkout `fulfillment = delivery`. |

### `delivery_status`

| Nilai                                                                | Arti                                                                            |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `null`                                                               | Belum dibuat shipment (pickup, atau delivery yang belum `ready`/belum dibayar). |
| `requested`                                                          | Order kurir Biteship baru dibuat (job run setelah order `ready`).               |
| `failed`                                                             | Pembukaan order kurir Biteship gagal (owner bisa retry manual dari admin).      |
| `picked`, `dropping_off`, `delivered`, `cancelled`, `returned`, dsb. | Status kurir dari event Biteship (disimpan apa adanya, huruf kecil).            |
| `unknown`                                                            | Status tidak dikenali.                                                          |

### `courier` (hanya delivery)

`courier_name`/`courier_service` = pasangan kurir yang dipilih (dari opsi quote), contoh `Gojek`/`Instant`. Orders pickup: keduanya `null`. `tracking_url` diisi setelah waybill tersedia.

## Alur delivery (siapa melakukan apa)

1. Frontend panggil `/branches` → cabang dengan `supports_delivery: true` menawarkan delivery.
2. Customer isi alamat → frontend panggil `/delivery/areas?query=...` → customer pilih area → simpan `areas[].id` sebagai `delivery_area_id`.
3. Frontend panggil `/checkout/quote` dengan `destination_area_id` → tampilkan daftar opsi kurir + `fee` + `etd`.
4. Customer pilih pasangan `courier_name` + `courier_service` **persis** dari opsi (fee di layar hanya info; **fee resmi dari server** saat create).
5. Frontend panggil `/orders` dengan `Idempotency-Key` (UUID baru per checkout) + pilihan kurir + `delivery_address` + `delivery_area_id`.
6. Server me-quote ulang Biteship; bila pasangan kurir client tak ada di opsi hasil quote terakhir → `422`. Total = subtotal + fee server.
7. Redirect customer ke `payment_url` (bayar QRIS lewat DOKU). Setelah `payment_status` menjadi `paid`, dapur memproses; saat order `ready`, shipment Biteship dibuat otomatis (asinkron via queue).
8. Status & tracking diketahui customer via polling `GET /orders/{token}` (`delivery_status`, `tracking_url`).

## Batasan yang perlu diketahui frontend

- Stok tidak dijamin: bahan bisa habis setelah browse; pesanan bisa dibatalkan dapur (status `cancelled`) dan refund manual via DOKU.
- Service instan (Gojek/Grab) membutuhkan koordinat asal/tujuan yang **tidak** dikirim aplikasi ini (cukup area id Biteship); bila provider menolak service instan untuk wilayah tertentu, opsi itu tidak akan muncul di quote/checkout — ops/owner akan memverifikasi saat pilot.
- Katalog tidak memuat sisa stok; `weight_grams` produk dipakai untuk menghitung berat pengiriman (fallback 500 g bila belum diisi).
- Order online tak bisa dibatalkan customer; pembatalan/refund via kasir/owner + dashboard DOKU.
