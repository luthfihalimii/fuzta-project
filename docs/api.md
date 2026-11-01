# Kontrak API Customer — Pizza Fruzta

Dokumen ini adalah kontrak REST untuk developer frontend (website/aplikasi customer). Semua rute publik, tanpa autentikasi. Sumber kebenaran perilaku = kode (`routes/api.php` + modul `app/Modules`); bila dokumen ini dan kode berbeda, kode yang benar.

## Dasar

- **Base URL:** `https://<domain>/api/v1` (produksi) atau `http://127.0.0.1:8000/api/v1` (dev). Semua path di bawah ditulis relatif base URL.
- **Format:** JSON (`Content-Type: application/json`).
- **Uang:** integer IDR (satuan rupiah). `15000` = Rp 15.000. Tarif Biteship yang desimal dibulatkan ke integer saat di-quote.
- **Server otoritatif:** harga, ongkir, dan total selalu dihitung ulang di server. Nilai harga/ongkir/total yang dikirim client diabaikan; yang diproses hanyalah pilihan produk/varian/opsi + kurir.
- **CORS:** origin yang diizinkan diatur oleh env `CORS_ALLOWED_ORIGINS` (dev default `*`).

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
| `404` | Resource tidak ditemukan (token order tidak dikenal, dsb.)                                                                                                                                                                                                 |
| `422` | Validasi / aturan bisnis gagal (cabang tutup, produk tidak tersedia, kurir tak cocok, area tak dikenali, `Idempotency-Key` kosong, dsb.).                                                                                                                  |
| `429` | Rate limit terlampaui.                                                                                                                                                                                                                                     |
| `502` | Provider upstream (Biteship/DOKU) tidak tersedia (down/http client menolak=miskonfigurasi sisi kita). Tampilkan "layanan sedang tidak tersedia, coba lagi nanti"; untuk `/orders`, retry dengan `Idempotency-Key` yang sama — order lama tidak akan dobel. |
| `500` | Error internal server (hanya webhook: provider akan retry).                                                                                                                                                                                                |

### Rate limit

| Limiter             | Batas                   | Berlaku untuk                                                                  |
| ------------------- | ----------------------- | ------------------------------------------------------------------------------ |
| `customer-public`   | 60 request/menit per IP | `GET /branches`, `GET /products`, `GET /delivery/areas`, `GET /orders/{token}` |
| `customer-checkout` | 10 request/menit per IP | `POST /checkout/quote`, `POST /orders`                                         |

Webhook tidak di-throttle (verifikasi signature adalah gerbangnya).

### Daftar endpoint

| Method | Path                     | Keterangan                                                                   |
| ------ | ------------------------ | ---------------------------------------------------------------------------- |
| GET    | `/branches`              | Cabang aktif (jam buka & status buka hari ini).                              |
| GET    | `/products?branch_id=`   | Menu cabang: kategori, varian, opsi (modifier).                              |
| GET    | `/delivery/areas?query=` | Pencarian area Biteship (untuk memilih alamat pengiriman).                   |
| POST   | `/checkout/quote`        | Perkiraan ongkir + opsi kurir untuk keranjang.                               |
| POST   | `/orders`                | Buat order customer + invoice DOKU (header `Idempotency-Key` wajib).         |
| GET    | `/orders/{token}`        | Cek status order customer via token (tanpa auth).                            |
| POST   | `/webhooks/doku`         | **Internal** — notifikasi pembayaran DOKU. Jangan dipanggil frontend.        |
| POST   | `/webhooks/biteship`     | **Internal** — update status pengiriman Biteship. Jangan dipanggil frontend. |

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
