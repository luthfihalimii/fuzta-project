# Desain: Laporan Penjualan per Channel

- **Tanggal:** 31 Oktober 2026
- **Status:** Menunggu persetujuan owner
- **Merujuk:** PRD §7.10 P0 ("Sales breakdown by payment method and order channel")

## Niat & mengapa

Laporan penjualan (`/admin/reports/sales`) sudah menampilkan breakdown per **metode pembayaran** (tunai, QRIS statis). Separuh PRD §7.10 P0 yang lain belum ada: breakdown per **channel order** (dine-in / takeaway). Ini menutup P0 §7.10 untuk channel.

## Keputusan owner (2026-10-31)

- Channel yang ada sekarang: dine-in dan takeaway (enum `OrderChannel`). Delivery out of scope MVP.
- Bentuk: satu tabel baru di halaman Sales yang sama, filter tanggal + cabang sama seperti tabel yang sudah ada.

## Desain

**Query** (`app/Modules/Reporting/Queries/DailySalesQuery.php`):

- Tambah key `by_channel` di return array: `list<array{channel: string, label: string, order_count: int, amount: int}>`.
- Aturan filter PERSIS sama dengan summary yang ada (konsistensi angka): `orders.payment_status = paid` DAN `orders.status != cancelled`, range `created_at`, filter `branch_id` optional. Group by `orders.channel`, `SUM(orders.total)` dari tabel orders (bukan payments, karena unit pengelolannya per order, konsisten dengan `gross_sales`).
- `label` dari `OrderChannel::tryFrom(...)?->label() ?? value`.

**Controller** (`ReportController::sales`): tidak berubah (prop `sales` sudah menyeluruh; key baru ikut).

**UI** (`resources/js/pages/admin/reports/Sales.vue`):

- Section baru "Order per channel", markup tabel **mirror persis** section "Penjualan per metode" yang ada (kolom: Channel | Pesanan | Jumlah, `formatRupiah`).
- Posisi: setelah section by_payment_method, sebelum "Produk terjual".
- Tanpa data: array kosong tidak merender baris (pola tabel yang sudah ada).

## Non-goal

- Cross-tab channel × metode pembayaran (tidak diminta).
- CSV export (P1).
- Channel baru (delivery) tidak dibangun dulu.

## Yang ditinggalkan untuk builder

- Pola halaman + query sudah ada; ini sekadar 1 groupBy tambahan + 1 tabel.
- Konsistensi angka: `sum(by_channel[].amount)` untuk order yang sama harus == `gross_sales` (tes memandangnya).
