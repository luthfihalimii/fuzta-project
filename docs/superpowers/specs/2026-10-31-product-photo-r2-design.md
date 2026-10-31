# Desain: Foto Produk + Penyimpanan Cloudflare R2

- **Tanggal:** 31 Oktober 2026
- **Status:** Menunggu persetujuan owner
- **Merujuk:** PRD §7.x P0 ("Admin can manage categories, products, descriptions, images, and availability"), stack PRD (S3-compatible object storage)

## Niat & mengapa

Katalog produk belum punya foto. Kasir dan owner memilih rasa dari teks di layar POS; foto membuat menu lebih mudah dipindai (PRD §non-functional: "Product photography and pricing are easy to scan"). Foto disimpan **di Cloudflare R2 (bukan disk VPS), keputusan owner**.

## Keputusan owner (2026-10-31)

1. Penyimpanan: **Cloudflare R2** (bukan disk VPS). R2 S3-compatible, egress gratis.
2. Domain foto: **domain publik R2 bawaan** (`pub-xxxx.r2.dev`), sementara, sampai ada subdomain sendiri.
3. **1 foto per produk** (bukan per varian). Varian ikut foto produknya.
4. Foto berasal dari upload owner (foto asli dari HP), bukan dibuat sistem.

## Desain

**Dependensi baru (1, pin versi):** `league/flysystem-aws-s3-v3` (driver S3 standar Laravel untuk R2; sekarang belum terpasang, hanya `flysystem-local`). Sesuai aturan AGENTS, sudah dikonfirmasi ke owner.

**Schema** (1 migrasi): `products.image` string nullable — isi = **key** R2 (misal `products/12/abc.jpg`), BUKAN URL. URL dihitung saat render, jadi ganti domain tidak menyentuh data.

**Config:**

- `config/filesystems.php`: tambah disk `r2` (driver `s3`): `key`/`secret` dari `R2_ACCESS_KEY_ID`/`R2_SECRET_ACCESS_KEY`, `region` default `auto`, `bucket` dari `R2_BUCKET`, `endpoint` dari `R2_ENDPOINT`, `use_path_style_endpoint: true`, `url` dari `R2_PUBLIC_BASE_URL` (domain publik `pub-xxx.r2.dev`).
- `config/images.php` baru: `['disk' => env('IMAGES_DISK', 'public')]` — keputusan gue: default disk `public` (sudah ada, `storage/app/public`) agar dev/test jalan tanpa kredensial; di VPS `.env` set `IMAGES_DISK=r2` + `R2_*`.
- Kode memakai `Storage::disk(config('images.disk'))` di satu titik (helper kecil di modul Catalog).

**Upload (store/update product, `role:owner` sudah ada di grup route):**

- Field opsional `image` (file) + `remove_image` (boolean) di FormRequest yang ada (`StoreProductRequest`/`UpdateProductRequest`, cek nama sebenarnya saat implementasi).
- Validasi server: `file`, `mimes:jpg,jpeg,png,webp`, `max:5120` (KB).
- Proses (GD, sudah ada): re-encode jadi JPEG, lebar maksimum 1200px, quality 82. Key: `products/{product_id}/{uniqid}.jpg`.
- Ganti foto: upload key baru DULU, lalu simpan ke DB, baru hapus key lama (DB gagal = foto lama masih utuh; key baru yatim tanpa nama di bucket).
- Store (produk baru): proses + upload foto DULU, baru buat produk. GD tak bisa decode (foto rusak/HEIC) = error validasi 422 pada `image`; disk gagal saat tulis = 500 dan produk BELUM dibuat (owner retry tidak bikin duplikat).
- Error disk saat update = 500 yang jujur, kolom `image` lama tetap menunjuk ke file yang masih ada.

**Tampilan:**

- `admin/catalog/Products.vue`: thumbnail per baris; tanpa foto = kotak abu netral berisi inisial nama produk (placeholder jujur, bukan gambar palsu).
- `admin/catalog/ProductEdit.vue`: preview foto, input upload, tombol hapus foto.
- `pos/Index.vue`: thumbnail kecil di baris produk (kotak abu kecil jika null).

**URL foto:** domain URL publik R2 (dan URL lokal `/storage/...`) diambil dari `.env` VPS; checklist owner: buat bucket **public**, R2 API token (Access Key ID + Secret), Account ID (untuk endpoint), catat `pub-xxx.r2.dev`.

## Keputusan builder (2026-10-31, hasil builder-check)

1. **Tanpa HEIC.** Daftar mime tetap `jpg/jpeg/png/webp`; foto iPhone (HEIC) auto-convert jadi JPG via WA/TG sebelum upload (dicatat di panduan README). Tidak tambah dependensi (Imagick) untuk kasus spekulatif.
2. **Tanpa varian thumbnail.** Satu file per produk; `loading="lazy"` + size CSS di POS/admin. Tambah derivatif hanya kalau tablet POS terbukti lambat.
3. **Urutan deploy:** bucket + token + env R2 disiapkan di VPS DULU, baru deploy fitur dengan `IMAGES_DISK=r2`. Foto produk belum ada sama sekali saat fitur rilis, jadi tidak ada file yang perlu dimigrasikan dari disk `public`. (Kalau nanti foto sempat terupload saat disk masih `public`: satu perintah salin sekali, catat di deployment.md.)
4. **VPS:** tambah `php8.3-gd` di daftar paket deployment + `upload_max_filesize = 8M` / `post_max_size = 8M` di php.ini FPM (default PHP 2M memotong upload sebelum validasi 5 MB).

## Non-goal

- Multi-foto per produk, crop, resim client-side.
- Foto per varian.
- Privat bucket + signed URL (foto non-sensitif; bucket publik cukup, hemat kode).
- Migrasi/CDN custom domain (sudah dicatat untuk nanti).

## Tes (tanpa R2 nyata; via disk lokal)

- Upload valid → key tersimpan, file ada di disk (test disk `public`), produk terupdate.
- Tipe/ukuran invalid → 422 (key error `image`).
- Ganti foto → key lama hilang dari disk.
- `remove_image` → kolom null.
- Non-owner → 403 (grup role:owner).
- URL render: key → URL disk yang aktif.

## Dokumen

- PRD: paragraf "Implemented in the current release" di bagian branch/catalog untuk foto produk (gaya §7.9 purchasing).
- README: seksi `## Product photos` (cepat): di mana upload, disk aktif via env, checklist R2.
- `docs/operations/deployment.md`: env VPS foto (`IMAGES_DISK=r2`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT`, `R2_PUBLIC_BASE_URL`) + paket `php8.3-gd` + `upload_max_filesize`/`post_max_size`.
