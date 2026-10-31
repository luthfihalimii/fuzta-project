# Desain: Jam Operasional Cabang

- **Tanggal:** 31 Oktober 2026
- **Status:** Menunggu persetujuan owner
- **Merujuk:** PRD §7.x P0 ("Each branch has name, address, contact, opening hours, and active status"); P1 "Temporary closures and special operating hours" TIDAK termasuk (non-goal di bawah)

## Niat & mengapa

Model `Branch` belum punya jam operasional, padahal PRD P0 mencantumkannya sebagai atribut cabang. Owner sudah memberi data nyata: **dua cabang sama, 09:00-21:00 WIB**. Staff (kasir/dapur) juga perlu lihat jam + status Buka/Tutup di layar mereka tanpa bertanya.

## Keputusan owner (2026-10-31)

1. Jam default dua cabang: **09:00-21:00 WIB** (Asia/Jakarta; timezone default app sudah sama).
2. Di luar jam operasional: **warning lembut saja di POS**, transaksi TIDAK di-block. (Block/penutupan khusus = P1.)
3. Owner yang edit jam, dari halaman cabang admin (konsisten dengan field cabang yang lain).

## Desain

**Schema** (1 migrasi): `branches.opens_at` dan `branches.closes_at`, string(5) `HH:MM`, NOT NULL default `'09:00'`/`'21:00'` (branch yang sudah ada langsung dapat default). Validasi `date_format:H:i`.

**Aturan "sedang buka":** `now('Asia/Jakarta')` antara `opens_at` dan `closes_at` (tanpa dukungan jam yang lewat tengah malam, non-goal).

**Di mana terlihat:**

- `BranchPresenter::summary()` (sudah dipakai POS + dapur + halaman cabang): tambah `opens_at`, `closes_at`, `is_open_now` (boolean dihitung di presenter, timezone Asia/Jakarta).
- POS (`pos/Index.vue`) + Dapur (`kitchen/Index.vue`): chip kecil di header dekat info cabang: `"Buka 09:00-21:00"` + status `Buka`/`Tutup`.
- POS: satu baris flash lembut seperti banner yang sudah ada saat `is_open_now === false` (informasi saja, tidak menghalangi; keputusan gue, owner setuju warning-only).

**Edit (admin):**

- `BranchController@index` sudah memetakan field; tambah dua field.
- `@update` (owner-only sudah ada via grup `role:owner` + policy `update`): terima `opens_at`/`closes_at` dengan `date_format:H:i`; audit `branch.updated` before/after sudah dipakai (WriteAuditLog); nilai baru ikut snapshot.
- `admin/branches/Index.vue`: 2 input `<input type="time">` di form cabang + ditampilkan di tabel.

## Non-goal

- Jam per hari minggu, jam beda per cabang otomatis, penutupan sementara, block order.
- Setengah jam sebagai unit (resolusi 1 menit sudah cukup).

## Tes

- Schema: dua branch baru mendapat default 09:00/21:00 (NOT NULL default di migrasi); bukti via assert model setelah create.
- Logic `is_open_now`: `Carbon::setTestNow` (atau `Date::fake()`) 3 kasus: sebelum buka, sedang buka (termasuk jam persis opens_at), setelah close.
- Update: valid H:i, invalid (misal `25:00`, `0900`) → 422; non-owner → 403.
- Props POS/kitchen halaman memuat `opens_at`/`closes_at`/`is_open_now` (expand tes halaman yang sudah ada).
- Audit: baris `branch.updated` memuat before/after jam.
