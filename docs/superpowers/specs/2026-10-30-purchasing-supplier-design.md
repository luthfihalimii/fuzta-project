# Desain: Pembelian & Supplier

- **Tanggal:** 30 Oktober 2026
- **Status:** Menunggu persetujuan owner
- **Merujuk:** PRD §7.9 P0 ("Record suppliers and purchase receipts"; "Receive stock against a purchase record or documented manual receipt"), §7.10 P1 (harga bahan → dasar laporan biaya, data disimpan sekarang, laporannya menyusul)

## Niat & mengapa

Sampai sekarang stok bahan hanya bisa bertambah lewat "Penerimaan" manual (owner-only) di halaman gerak-
an stok — tanpa tahu **dari supplier mana**, **berapa harganya**, dan **kapan**. Di lapangan: kiriman
supplier dipegang owner, dicatat di kertas/catatan pribadi, dan tidak tersimpan di sistem. Akibatnya:
tidak ada jejak biaya bahan (laporan margin mustahil), dan penerima barang di lokasi (dapur/kasir) tidak
bisa mencatat kiriman tanpa minta akses owner.

Fitur ini menutup PRD §7.9 P0 yang tersisa: daftar supplier + dokumen penerimaan barang dari supplier,
dengan harga beli per satuan yang tersimpan selamanya.

## Keputusan owner (sudah disepakati, 2026-10-30)

1. **Harga beli dicatat** — setiap baris penerimaan punya harga per satuan (rupiah bulat).
2. **Yang boleh menerima barang: dapur & kasir** (+ owner, yang sudah punya semua).
3. **Alur:** pilih supplier → pilih cabang → baris (bahan, jumlah, harga) → simpan → stok bertambah +
   dokumen tersimpan permanen. (Semua detail lain di bawah sudah dipresentasikan dan disetujui
   owner: "oke sudah bagus".)
4. **Satuan entri = unit supplier (opsi B):** tiap baris boleh pilih unit (kg, pack, pcs, …),
   jumlah & harga diinput dalam satuan itu; disamakan ke unit dasar lewat konversi yang sudah ada.

## Keputusan pembangun ("my call" — boleh diobjeksi sebelum plan)

1. **Modul baru `app/Modules/Purchasing`** (pola modul yang sudah ada): model `Supplier`, `Purchase`,
   `PurchaseItem`; action `RecordPurchase`; controller `SupplierController` (admin) +
   `PurchaseController` (admin/POS/dapur).
2. **Model data:**
   - `suppliers`: `name` (wajib, unik), `phone` (opsional), `is_active` (default true), timestamps.
     **Tidak ada hapus** — supplier hanya bisa dinonaktifkan (pola staf).
   - `purchases`: `branch_id`, `supplier_id`, `received_at` (DATE, default hari ini = tanggal
     bisnis), `reference` (opsional, bebas — nomor nota supplier), `total` (integer rupiah,
     tak-ubah), `recorded_by` → user, timestamps.
   - `purchase_items`: `purchase_id`, `ingredient_id`, `unit_id` (**unit entri** — pilihan per
     baris, default = unit dasar bahan), `quantity` (integer, **dalam unit entri**),
     `unit_price` (integer rupiah **per unit entri**), `line_total` (integer, dihitung & disimpan
     server = qty × harga, dalam unit entri — persis, tanpa pembulatan). Baris tak di-update.
   - Selalu integer rupiah, jangan pernah float.
2b. **Konversi (keputusan owner, opsi B):** `quantity` disamakan ke **unit dasar bahan** memakai
   `unit_conversions` yang sudah ada; hasil harus integer ≥ 0 (selain itu 422: "hasil konversi
   bukan bilangan bulat"); konversi belum ada → 422 ("definisikan konversi unit terlebih
   dahulu").
2c. **Idempotency (pola rumah, seperti orders):** kolom `idempotency_key` string +
   `unique(['branch_id','idempotency_key'])` di `purchases`. Form generate key saat load & kirim
   tersembunyi; POST ulang dengan key sama mengembalikan pembelian pertama (bukan duplikat,
   bukan 422).
2d. **Tanggal:** `received_at` boleh mundur maks **90 hari** (nota lama); tanggal masa depan →
   422. Tanggal dokumen tidak ikut ditulis ke kolom waktu ledger (disengaja; ledger memakai
   waktu pencatatan).
2e. **Riwayat = owner hanya.** Dapur/kasir **submit-only** (redirect balik + pesan sukses);
   daftar/riwayat pembelian tidak dibangun di layar mereka.
3. **Ledger:** `RecordPurchase` menulis **langsung** (lock saldo → movement → update saldo)
   mengikuti preseden `ReceiveStockTransfer`; `RecordStockMovement` **tidak disentuh**. Per
   baris **satu movement** tipe `Receipt` (`Penerimaan`), `unit_id` = unit dasar bahan,
   `quantity` = hasil konversi, `reference_type = Purchase`, `reference_id = purchase.id`,
   reason `Penerimaan pembelian #{id} — {nama supplier}`. Invarian reconciliation utuh;
   penerimaan selalu menambah → tanpa risiko stok minus.
4. **Penerimaan manual tanpa dokumen pembelian TETAP owner-only** (tipe `Receipt` via
   `RecordStockMovement` tidak disentuh) — jalan "documented manual receipt" milik PRD.
5. **Satu dokumen = satu supplier + satu cabang.** Tidak ada PO, draft, status, approval — kiriman
   dicatat sekali jadi saat barang serah terima (MVP; PRD §7.9 P1 "purchase orders" = nanti).
6. **Dokumen pembelian tak boleh di-edit dan dihapus** — tidak ada route update/delete untuk
   `purchases`. **Supplier** boleh di-**ubah** (nama/telepon/aktif) via `PATCH /admin/suppliers/{supplier}`,
   **tidak pernah dihapus** — hanya dinonaktifkan (pola staf). Kesalahan jumlah penerimaan → koreksi
   stok biasa (tipe `Adjustment`, owner-only, sudah ada); kesalahan supplier/harga pada dokumen
   yang tersimpan tetap tercatat apa adanya (koreksi dokumen = fitur lanjutan). Konsisten dengan
   ledger append-only & "no destructive deletes".
7. **Otorisasi (dicek server-side, 2 lapis: middleware route + guard action):**
   | Aksi | Role |
   |---|---|
   | Lihat/riwayat + terima via panel admin | owner |
   | Terima via layar POS (submit-only, tanpa riwayat) | cashier, owner |
   | Terima via layar dapur (submit-only, tanpa riwayat) | kitchen, owner |
   | Kelola supplier (tambah/ubah/nonaktif) | owner |
   Guard action: `role ∈ {owner, cashier, kitchen}` **dan** `hasBranchAccess(branchId)` — dapur
   cabang A tidak bisa mencatat pembelian cabang B. (Matriks lintas-cabang wajib dites.)
8. **UI:**
   - Panel admin: nav baru **"Pembelian"** → `/admin/purchases` (daftar: tanggal, supplier, cabang,
     total, pencatat; klik → detail barisnya) + tombol **"Terima barang"** → form; halaman
     `/admin/suppliers` (daftar + tambah/ubah/nonaktif).
   - Layar POS & dapur: tombol **"Terima barang"** di header (dekat tombol yang sudah ada) →
     form pembelian yang **sama** (1 komponen Vue, 3 wrapper tipis — pola halaman stok dapur).
   - Form: pilih cabang (hanya owner; kasir/dapur terkunci di cabang sendiri), pilih supplier
     aktif, tanggal (default hari ini; boleh mundur maks 90 hari, tanpa masa depan), nomor nota
     (opsional), lalu baris-baris: pilih bahan (daftar bahan yang sama dengan form gerak-stok),
     **pilih unit entri** (default unit dasar bahan), jumlah, harga per satuan; bahan tidak
     boleh duplikat dalam satu dokumen; total per baris & total keseluruhan ditampilkan
     (di-re-itung server secara diamati); field tersembunyi `idempotency_key`.
   - Bahasa UI: Indonesia, mengikuti halaman yang ada.
9. **Audit:** `supplier.created`, `supplier.updated` (sebelum/sesudah aman), `purchase.received`
   (supplier, cabang, total, daftar bahan+qty+unit+harga) via `WriteAuditLog`.
10. **Seeder (non-prod saja):** 2 supplier contoh + pastikan paling sedikit satu konversi unit
    (mis. kg→gram / pack→gram) tersedia di data dev agar form bisa dicoba end-to-end.
    (Tidak ada purchase contoh — stok dev harus tetap mudah diprediksi.)
11. **Tidak ada broadcast** untuk penerimaan — stok di layar dapur tetap mengandalkan refresh
    manual/polling yang sudah ada.
12. **Dokumentasi:** PRD §7.9 paragraf pola "Implemented in the current release: …" (Inggris,
    seperti §7.6/§7.7); README garis fitur; AGENTS daftar modul +Purchasing.

## Luas & batas (scope)

**Di luar rilis ini:**
- Purchase order / approval tier (PRD §7.9 P1 — sekaligus open decision "approval").
- Laporan riwayat harga beli & biaya bahan/margin (PRD §7.10 P1 — datanya sudah tersimpan).
- Multi-supplier dalam satu nota, barcode/scanner, batch/ekspedisi, pajak/bea pada pembelian.
- Edit/pembatalan dokumen (koreksi lewat jalur koreksi stok yang ada).

**Anti-goal:**
- Tidak boleh ada kode yang menulis `stock_balances`/`stock_movements` di luar pola ledger yang ada.
- Tidak boleh float untuk uang.
- Supplier/akun tidak boleh dihapus (attribution).

## Alur penerimaan (server)

```
POST (pos|kitchen|admin)/purchases
  → FormRequest: role & branch access & supplier aktif & ≥1 baris
    & qty>0 integer & harga≥0 integer & bahan tak duplikat
    & tanggal ≤ hari ini & unit konversi tersedia (hasil integer)
  → Idempotency: (branch, key) sudah ada → kembalikan pembelian pertama
  → action RecordPurchase (DB::transaction):
      1. buat Purchase + PurchaseItem (line_total & total dihitung & disimpan)
      2. per baris: konversi ke unit dasar → lock saldo → tambah → movement Receipt
      3. audit purchase.received
  → redirect balik + success "Penerimaan #N tercatat"
```

Gagal di mana pun = seluruh transaksi batal (bayar tidak separuh-setengah).

## Pengujian (harus sukses + gagal)

PHP (Feature):
- Dapur cabang A tercatat di cabang A → 200; purchase+item+movement+saldo+audit terbentuk
  (assert kunci ledger: tipe `Receipt`, `reference_type=Purchase`, reason, qty).
- Kasir boleh; kitchen lintas-cabang DITOLAK (403); cashier/kitchen tanpa akses cabang DITOLAK.
- Owner boleh di cabang mana pun; kasir di `/admin/purchases` → 403 (non-admin).
- Penerimaan manual `RecordStockMovement` tipe Receipt TETAP owner-only (regression).
- Validasi: qty 0/negatif → 422; harga negatif → 422; 0 baris → 422; bahan duplikat → 422;
  supplier nonaktif → 422; supplier tidak ada → 404.
- Konversi: unit entri = unit dasar → OK; kg→gram → OK, movement tercatat dalam unit dasar;
  konversi belum ada → 422; hasil konversi non-integer → 422.
- Idempotency: POST ulang dengan key sama → mengembalikan pembelian pertama (saldo tetap
  1×), bukan 422 dan tidak ada duplikat.
- Tanggal: mundur 90 hari → OK; tanggal besok → 422.
- Uang: total tersimpan = Σ baris, angka besar tanpa drift (integer); line_total = qty × harga.
- Concurrency: dua penerimaan paralel bahan sama → kedua-duanya tercatat, saldo = jumlah keduanya.

(Not diperlukan Vitest — logika murni tidak ada; total client hanya tampilan.)

## Deployment notes

Tidak ada dependensi/proses baru (queue/reverb/backup tidak berubah).
