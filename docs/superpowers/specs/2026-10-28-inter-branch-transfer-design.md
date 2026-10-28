# Design: Transfer Antar-Cabang (Stock Transfer)

Tanggal: 2026-10-28. Cabang basis: `feat/in-branch-operations` (setelah fitur recipe inventory).

## Niat & Mengapa

Pizza Fruzta punya 2 cabang dengan stok bahan terpisah. Bahan sering berpindah antar cabang
(umumnya pusat → cabang, kadang sebaliknya) lewat kurir antar-cabang. PRD §7.9 P0:
"Transfer stock between warehouse and branches through explicit statuses: draft, dispatched,
received, cancelled. Dispatch reduces source available stock and records stock in transit;
receipt adds stock to destination. Transfer quantity discrepancies require a recorded reason."

Sedangkan saat ini tidak ada cara memindahkan stok antar cabang secara tercatat — hanya
adjustment manual di masing-masing cabang, yang memutus jejak "barang pindah dari mana ke mana".

Kesepakatan owner (sudah dikonfirmasi):
- Alur fisik: **klik kirim → antre → terima**. Cabang A klik "kirim" (stok A berkurang, barang
  berstatus in-transit); barang diantar; cabang B klik "terima" (stok B bertambah).
- Discrepancy: **penerima input jumlah aktual + alasan wajib** bila aktual < dikirim. Tanpa
  hierarki persetujuan.
- Replay (approved owner): cancel hanya dari `draft`; role owner-only; tidak ada tabel
  in-transit terpisah (in-transit = baris transfer berstatus `dispatched`).

Simplifikasi yang diteken: PRD menyebut "warehouse ↔ branches"; MVP tidak punya entitas
warehouse → transfer antar **cabang ↔ cabang** saja (setiap cabang bisa menjadi origin/dest).

## Goal / Non-goal / Anti-goal

**Goal**
1. Pemilik (owner) bisa membuat transfer A→B (draft), mengirim (dispatch), dan receiver menerima
   (receive) dengan input jumlah aktual — seluruhnya tercatat di `stock_movements` (append-only)
   dengan balance tetap = jumlah ledger (kompatibel `inventory:reconcile`).
2. Tema amount selalu integer unit dasar bahan; shortfall di dispatch → 422 tanpa perubahan apa pun.
3. In-transit terlihat di UI (daftar transfer berstatus `dispatched`).
4. Tidak bisa dispatch dua cabang di transaksi race yang sama → saldo double-spend.

**Non-goal (MVP)**
- Entitas warehouse; purchase order / supplier / price history (PRD §7.9 P1).
- Hierarki persetujuan untuk discrepancy atau dispatch.
- Override negative stock saat dispatch (tetap basis default: saldo kurang = 422) — open item owner.
- Transfer multi-leg / paragon / split-barang di tengah jalan.
- UI di sisi POS/kitchen (admin owner only).

**Anti-goal**
- Tidak boleh ada kode yang menulis `stock_balances.qty` di luar ledger (maintain invariant
  reconciliation). Tidak boleh overwrite/rotate baris `stock_movements`.
- Tidak boleh menulis receiver asli memory yang dikirim post-dispatch (audit trail utuh).

## Decision yang Sudah Diteken (owner)

1. **Status machine**: `draft → dispatched → received`; `draft → cancelled`. Tidak ada
   `dispatched → cancelled`. Cancel hanya dari draft (stok belum bergerak).
2. **Trigger stok**: dispatch = kurangi origin (movement `transfer_out` qty negatif);
   receive = tambah destination (movement `transfer_in` qty positif). Draft/cancel tidak menyentuh stok.
3. **In-transit**: direpresentasikan oleh status `dispatched` itu sendiri. Tidak ada
   `stock_in_transit` table terpisah; in-transit qty for (origin, ingredient) = jumlah item baris
   per baris aktif transfer berstatus dispatched.
4. **Discrepancy**: saat receive, per item penerima input **jumlah aktual** (default = dikirim).
   Bila aktual < dikirim → field `discrepancy_reason` wajib (non-empty). Bila aktual > dikirim
   diperbolehkan (kurir tambah/kompensasi katup kebocoran) — catat juga. Movement `transfer_in`
   menggunakan qty **aktual**.
   **Qty 0 (barang hilang)**: diijinkan. Movement `transfer_in` untuk baris berqty 0 DILEWATI
   (ledger menolak movement qty 0); `discrepancy_reason` tetap wajib. Transfer boleh ditutup
   `received` meskipun SEMUA baris 0 (kargo hilang total tercatat, tanpa path stuck).
5. **Authz**: owner-only, mengikuti grup `admin.stock` yang sudah `['auth','role:owner']` +
   FormRequest `authorize()` = `isOwner()`.
6. **Audit**: `transfer.created`, `transfer.dispatched`, `transfer.received`, `transfer.cancelled`
   via `WriteAuditLog` (before/after values safe: origin/dest/id + daftar item name&qty).
   Discrepancy ikut terekam di payload audit receive + movement reason.
7. **Cancel** mengembalikan status ke `cancelled` (baris permanen, non-destructive), tanpa
   perubahan stok, + audit. `cancelled` tidak hidup lagi (final).
8. **Tidak ada edit-draft**: draft yang salah (qty salah ketik) → cancel (reason tercatat) +
   buat ulang. Tidak ada route `PATCH /transfers/{id}` untuk edit item draft.

## Keputusan Pembangun (tulis sebagai my call; boleh diobjeksi sebelum plan)

- **Model & tabel**: `StockTransfer` + `StockTransferItem`.
  - `stock_transfers`: id, `origin_branch_id` (FK branches), `destination_branch_id` (FK branches),
    `status` (string/enum: draft|dispatched|received|cancelled), `created_by` (FK users),
    `dispatched_at` nullable, `received_at` nullable, `cancel_reason` nullable (wajib saat cancel),
    timestamps. Unique constraint `(origin_branch_id, destination_branch_id, status)` BELUM ada —
    beberapa transfer menang/hilang untuk pasangan cabang itu sah.
    Constraint: `origin_branch_id != destination_branch_id` (validasi app-level).
  - `stock_transfer_items`: id, `transfer_id` (FK restrict), `ingredient_id` (FK restrict),
    `quantity` (int, = dikirim), `received_quantity` (int nullable), `discrepancy_reason`
    (string nullable). Unique `(transfer_id, ingredient_id)` — satu bahan satu baris per transfer.
- **Enum status**: `TransferStatus` (Laravel backed enum, casing lower 'draft' dst).
- **Enum `StockMovementType`** tambahan: `TransferOut` (label `'Pengiriman antar cabang'`,
  qty negatif) dan `TransferIn` (label `'Penerimaan antar cabang'`, qty positif);
  sign rule di `RecordStockMovement::validateMovement` mengikuti pola yang ada;
  `requiresOwner()` tetap false (authz di layer route, bukan movement type).
- **Aksi (module Inventory/Actions)**: `CreateStockTransfer`, `DispatchStockTransfer`,
  `ReceiveStockTransfer`, `CancelStockTransfer` — `__invoke`, constructor-injected, semua operasi
  stok dalam satu `DB::transaction`, lock order deterministik: lock baris `stock_transfers`
  (lockForUpdate) → lock baris `stock_balances` per ingredient urut (sort by id, pola
  `ConsumeOrderIngredients`) → tulis movement + balance. Dispatch menolak jika origin sudah bukan
  `draft` (cek dari baris terkunci). Receive menolak jika bukan `dispatched`.
- **quantity = unit dasar bahan** (sama dengan resep); UI menampilkan label `unit.code` di samping
  input (pola `Recipes.vue`) — anti salah satuan. Float dilarang.
- **Dispatch shortfall**: draft-butuh qty satuan X, saldo asal setelah kurang 0 →
  `ValidationException` pesan nama bahan (pola `ConsumeOrderIngredients`), seluruh tx abort,
  status tetap `draft`.
- **Duplicate dispatch (race)**: dua user owner dispatch bersamaan — lock baris transfer + status
  check di dalam tx → satu menang, yang lain 422 "status" (bukan 500). Test khusus (fork atau
  serial asserts).
- **Controller**: `app/Modules/Inventory/Http/Controllers/StockTransferController` —
  `index` (list + create form data: cabang A/B, bahan+unit, transfer-aktif in-transit),
  `store` (buat draft), `dispatch`, `receive` (perbarui actual qty + alasan), `cancel`.
  Route di grup `admin.stock`: `GET /admin/stock/transfers`, `POST /admin/stock/transfers`,
  `PATCH /admin/stock/transfers/{transfer}/dispatch`,
  `PATCH /admin/stock/transfers/{transfer}/receive`,
  `PATCH /admin/stock/transfers/{transfer}/cancel`.
  Tanpa throttle — konsistensi dengan sibling `admin/stock/*` (route admin stock yang ada tidak
  memakai throttle; auth/checkout saja yang di-throttle house).
- **UI**: `resources/js/pages/admin/stock/Transfers.vue` mengikuti pola house (kartu
  `rounded-2xl border border-line bg-white`, `useForm`, `EmptyState`, `ConfirmDialog`,
  flash, error Inertia dot-form per item `{index}.{field}`, disable `form.processing`,
  label Bahasa Indonesia, CTA spesifik: "Buat transfer", "Kirim", "Terima", "Batal").
  Link "Transfer" ditambahkan ke header link-row halaman stock yang ada (5 file).
  Per transfer: form draft (jika belum dispatch) bisa dilihat origin/dest & baris item-nya,
  aksi "Kirim"; yang `dispatched` → form terima dengan input aktual per baris + alasan bila
  aktual < dikirim, aksi "Terima"; yang `draft` → aksi "Batal" (ConfirmDialog, cancel
  reason input). In-transit = badge pada baris `dispatched`.
- **Test** (`tests/Feature/StockTransferTest.php` + kemungkinan
  `tests/Feature/TransferConcurrencyTest.php` atau fork-section di dalamnya):
  1. Cashier semua route 403.
  2. Draft: origin==dest ditolak 422; bahan tidak ada ditolak; draft sukses → 0 movement, saldo utuh.
  3. Dispatch: saldo origin turun tepat, 1 movement `transfer_out` per bahan (qty negatif,
     reference_type/id = transfer, actor), status `dispatched`, `dispatched_at` terisi.
  4. Dispatch shortfall → 422 + nama bahan; saldo & movement & status tidak berubah.
  5. Double dispatch (atau race) → sekali menang, sekali 422; movement tetap 1 set.
  6. Receive qty = dikirim → saldo dest naik, movement `transfer_in` qty positif, status `received`.
  7. Receive aktual < dikirim tanpa alasan → 422; dengan alasan → sukses, `received_quantity` +
     `discrepancy_reason` tersimpan, movement `transfer_in` qty = aktual, audit receive menyertakan
     discrepancy.
  8. Receive aktual > dikirim → sukses (movement qty aktual), discrepancy_reason opsional.
  9. Cancel dari draft → status `cancelled`, cancel_reason tersimpan, 0 movement.
  10. Cancel/Receive/Dispatch dari status yang salah → 422.
  11. Reconciliation invariant: setelah siklus penuh (dispatch+receive),
      `inventory:reconcile` melanjutkan (exit 0) — saldo = jumlah ledger.
  12. Balance kedua cabang tidak pernah negatif (DB CHECK tetap hidup).
  13. Receive semua baris aktual 0 → transfer ditutup `received`, 0 movement `transfer_in`,
      discrepancy_reason semua baris tersimpan, saldo kedua cabang tidak berubah di fase receive.
- **Migration** 2 file bertanggal 2026-10-29 ( atau tanggal plan) — `stock_transfers` lalu
  `stock_transfer_items` (FK aman & cascade policy: `restrict` agar riwayat transfer tidak
  bisa dihapus diam-diam). Factory: `StockTransferFactory` + `StockTransferItemFactory`.
- **Hal alignment**: `StockCount` opname tetap jalan; transfer tidak mengubahnya.
- **Pint**: style file PHP lama; Vue mengikuti `Recipes.vue`.

## Yang Ditinggalkan untuk Pembangun (discretion)

- Nama route, nama URL, penataan card di page — ikuti house pattern.
- Pemasangan suntikan aksi di controller (constructor-inject vs app() — ikuti pola controller
  inventory yang ada).
- Apakah list diindex dipaginate: tidak perlu di MVP (transfer-nya sedikit); jika >50 baris baru
  pertimbangkan.
- Kosakata copy: bebas Bahasa Indonesia yang sopan, asalkan CTA per keputusan (# UI) tetap.

## Dependensi & Obyektif yang Sudah Ada (jangan di-rebuild)

- `StockMovement`, `StockBalance`, `Ingredient`, `Unit`, `Branch` (models/factories ada).
- `RecordStockMovement` (pola lock+balance+movement+sign rules — ekstensi sign rule
  `TransferOut`/`TransferIn` mengikuti pola `Consumption`/`ConsumptionReversal`).
- `WriteAuditLog`, grup route `admin.stock`, halaman stock house components.
- Test idiom: `RefreshDatabase`/`DatabaseMigrations`, fork concurrency `StockConcurrencyTest`.

## Obyektif Owner (TETAP PARKIR, jangan implementasi)

- Override negative stock + permission (juga untuk dispatch: tetap 422).
- Purchase orders / supplier / approval thresholds (PRD §7.9 P1).
- Approval hierarchy untuk discrepancy (keputusan: tidak ada; penerima mencatat + alasan saja).
