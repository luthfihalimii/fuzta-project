# Spesifikasi: Resep & Konsumsi Stok Sesuai Pesanan

> Implementasi: PRD §7.8 (P0) + §7.9 aksi "conversion rules" + rekonsiliasi saldo stok.
> Keputusan owner (28 Okt 2026): material dipotong **saat order masuk `preparing`**; pembalikan **otomatis** saat order di-void.

## Masalah

Sekarang penjualan tidak pernah mengurangi stok. Laporan stok tidak mencerminkan penjualan; stok "baik-baik saja" selamanya. Ini pelanggaran kualitas inti: catatan stok harus benar dan dapat dijelaskan.

## Keputusan desain

| #   | Keputusan                                                                                                                                                             | Alasan                                                                                                                       |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| 1   | Potong stok saat transisi ke `preparing` (bukan saat paid/queued)                                                                                                     | Order batal sebelum dimasak tidak menguras stok. Dipilih owner.                                                              |
| 2   | Pambar otomatis (movement `consumption_reversal`) saat `void` untuk order yang sudah consuming; `cancel` hanya berlaku queued+unpaid jadi tidak pernah perlu reversal | Append-only ledger: pembalikan tidak menghapus baris asli.                                                                   |
| 3   | Resep terhubung polymorfik ke `Product`, `ProductVariant`, atau `Modifier`. Maksimal 1 resep per target                                                               | Harga modifier sudah per-unit × qty di `PriceOrderItems`; BOM harus mengikuti semantik yang sama.                            |
| 4   | Jumlah item resep = **integer dalam unit dasar bahan** (`ingredient.base_unit_id`)                                                                                    | PRD §8.12: bukan float, jangan campur satuan tanpa aturan konversi.                                                          |
| 5   | Satu `unit_conversions` per pasangan arah: `from_qty` unit A = `to_qty` unit B. Resolusi rantai via BFS; tanpa jalur = error, bukan asumsi                            | "Never mix grams, kilograms, pieces, and packs without a conversion rule."                                                   |
| 6   | Stok tidak cukup saat mulai memasak = transisi **ditolak** (422), order tetap `accepted`                                                                              | PRD: negative stock diblok default. Mekanisme override negative (permission + audit) **ditunda**, dicatat sebagai open item. |
| 7   | `inventory:reconcile` artisan menyalin ulang saldo dari ledger; `--fix` menyetel ulang saldo + audit `stock.reconciled`                                               | `stock_balances` hanya cache; ledger adalah sumber luar.                                                                     |
| 8   | `config('inventory.deduct_on')` satu-satunya titik kebijakan event potong (nilai: `preparing`)                                                                        | AGENTS.md: keputusan bisnis di balik satu titik config, bukan tersebar.                                                      |

## Skema (baru)

```
recipes            id, recipeable_type, recipeable_id, timestamps
                   UNIQUE(recipeable_type, recipeable_id)
recipe_items       id, recipe_id (cascadeOnDelete), ingredient_id (restrict),
                   quantity bigInteger, timestamps
unit_conversions   id, from_unit_id, to_unit_id, from_quantity, to_quantity (bigInteger),
                   UNIQUE(from_unit_id, to_unit_id), timestamps
```

`stock_movements` tidak berubah. Nomor kasus enum baru di `StockMovementType`:
`Consumption = 'consumption'` (qty negatif, label "Konsumsi pesanan") dan
`ConsumptionReversal = 'consumption_reversal'` (qty positif, label "Pembalikan konsumsi").

## Alur kejadian (invariant ledger)

1. Kitchen PATCH status → `TransitionOrderStatus` lock order, validasi status machine, simpan `preparing`.
2. Jika `deduct_on === 'preparing'`: `ConsumeOrderIngredients`:
    - `ResolveOrderRequirements(order)` → map `ingredient_id => qty_total` (unit dasar).
      Ketentuan per baris: `(resep varian ?? resep produk) × qty_baris + Σ (resep modifier × qty_baris)`.
      Target tanpa resep tidak menguras apa-apa (valid, bukan error).
    - Per bahan (di transaksi parentes, balance `lockForUpdate`): `after = before − qty`.
      `after < 0` → `ValidationException` "Stok {bahan} tidak cukup untuk memulai pesanan." — tidak ada baris movement dan order tidak pindah status.
      Jika lolos: update balance, tulis 1 movement `consumption` qty negatif, `reference_type/id` = order, `actor_id` = yang mengubah status.
3. `VoidOrder` pada order berstatus `preparing|ready|completed` (setelah consuming):
   `ReverseOrderConsumption` menulis 1 movement `consumption_reversal` qty positif per bahan yang pernah dikonsumsi, balance naik, alasan menyebut nomor pesanan. Movement asli tetap ada.
4. Rekonsiliasi: `SUM(stock_movements.quantity)` per (branch, ingredient) harus sama dengan `stock_balances.quantity`. `inventory:reconcile` melapor; `--fix` menyetel ulang + audit (actor NULL, diizinkan oleh `audit_logs`).

## Kasus tepi

- **Dobel status**: state machine sudah menolak `preparing → preparing`; tidak ada jalur kedua yang bisa menguras dua kali.
- **Konkurensi**: dua order mulai bersamaan; balance locked, satu menang, yang lain 422. Tidak pernah saldo negatif (ada pula DB CHECK `quantity >= 0`).
- **Void order paid tapi belum preparing**: tidak ada movement pembalikan (belum ada yang dipotong).
- **Order tanpa resep**: order tetap bisa mulai, stok tidak berubah.
- **Resep berubah setelah order dibuat**: konsumsi memakai resep saat `preparing` berjalan (live), karena yang menentukan material adalah kondisi dapur saat itu; snapshot pesanan tidak berubah (snapshot tetap di `order_items`).
- **Unit tidak terhubung**: konversi gagal → error eksplisit, fallback tersembunyi dilarang.
- **Saldo dirusak manual / crash tengah transaksi**: reconcile detect + fix, laporan eksplisit (exit code 1 jika tetap ada selisih).

## Sisi admin (UI staff register, DESIGN.md §1)

- `/admin/stock/recipes` — daftar resep per target (produk/varian/modifier) + create/update/hapus. Setiap baris item wajib menampilkan label unit bahan (mis. "500 g"), supaya tidak salah satuan. Hapus pakai `ConfirmDialog` yang menyebutkan konsekuensinya.
- `/admin/stock/units` — CRUD satuan (add/hapus, rename nama) + CRUD konversi. Unit yang sudah dipakai (bahan, movement, konversi) tidak bisa dihapus.
- Audit: `recipe.created` / `recipe.updated` / `recipe.deleted` (before/after: daftar item nama+qty). Konsumsi/pembalikan tidak perlu audit ekstra: movement + status event sudah attributable.

## Batas (bukan scope rilis ini)

- Override negative stock oleh owner (PRD: "restriction + audit") — open item sampai ada kepastian alur.
- Discount/pajak di order (fitur berikutnya).
- UI input stok memakai satuan selain unit dasar (service konversi sudah ada, dipakai di laporan bila perlu).
