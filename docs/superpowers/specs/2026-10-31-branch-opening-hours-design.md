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

**Schema** (1 migrasi): `branches.opens_at` dan `branches.closes_at`, string(5) `HH:MM`, NOT NULL default `'09:00'`/`'21:00'` (branch yang sudah ada langsung dapat default). Validasi `date_format:H:i` (keduanya) + `closes_at` harus setelah `opens_at` (banding leksikografis `H:i` aman). Batas: buka dari `opens_at` (inklusif), tutup di `closes_at` (eksklusif — jam persis 21:00 sudah `Tutup`).

**Dihitung di klien (keputusan builder):** halaman POS/kitchen tidak pernah me-reload props, jadi boolean dari server akan basi (POS yang dibiarkan terbuka sejak 08:45 masih menampilkan `Buka` lewat 21:00). Status dihitung oleh satu fungsi kecil `resources/js/lib/opening-hours.ts`: `isOpenNow(opensAt, closesAt, date = new Date())`; dapur sudah punya ref `now` (interval 1 s), POS menambah interval 60 s. Jam device staff = jam cabang (kedua branch satu zona). `BranchPresenter::summary()` hanya mengirim `opens_at` + `closes_at`.

**Di mana terlihat:**

- `BranchPresenter::summary()` (sudah dipakai POS + dapur + halaman cabang): tambah `opens_at`, `closes_at` (lihat "Dihitung di klien" di atas).
- POS (`pos/Index.vue`) + Dapur (`kitchen/Index.vue`): chip kecil di header dekat info cabang: `"Buka 09:00-21:00"` + status `Buka`/`Tutup`.
- POS: satu baris flash lembut seperti banner yang sudah ada saat status `Tutup` (informasi saja, tidak menghalangi; owner setuju warning-only).
- Cabang nonaktif (`is_active = false`): chip menampilkan `Tutup` meskipun sedang dalam jam (banner nonaktif sudah ada).

**Edit (admin):**

- `BranchController@index` sudah memetakan field; tambah dua field.
- `@update` (owner-only sudah ada via grup `role:owner` + policy `update`): terima `opens_at`/`closes_at` dengan validation di atas; audit `branch.updated` before/after sudah dipakai (WriteAuditLog), nilai baru ikut snapshot.
- `store()`: terima `opens_at`/`closes_at` juga (form create+edit satu; tidak masuk `store()` = field di-discard silent); default tetap berlaku kalau tidak diisi.
- `admin/branches/Index.vue`: 2 input `<input type="time">` di form cabang + ditampilkan di tabel.

## Non-goal

- Jam per hari minggu, jam beda per cabang otomatis, penutupan sementara, block order, jam lewat tengah malam.
- Situs publik (PRD §6.1 "Branch locations and opening hours"): halaman publik belum ada di repo; data sudah ada di `Branch` + presenter, jadi UI publik menyusul saat halaman publik dibangun.

## Tes

- Schema: branch mendapat default 09:00/21:00 (NOT NULL default di migrasi); bukti via assert model setelah create (dengan dan tanpa jam eksplisit).
- Helper `isOpenNow` (vitest): sebelum buka → false, tepat `opens_at` → true, tengah rentang → true, tepat `closes_at` → false, setelah → false; rentang terbalik → false.
- Update: valid H:i tersimpan; invalid (`25:00`, `0900`) → 422; `closes_at <= opens_at` (misal 21:00→09:00) → 422; non-owner → 403.
- Props halaman POS/kitchen memuat `opens_at`/`closes_at` (expand tes halaman yang sudah ada).
- Audit: baris `branch.updated` memuat before/after jam.
