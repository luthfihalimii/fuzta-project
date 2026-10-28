# Shift Kasir Design

Tanggal: 2026-10-28
Status: Draft untuk persetujuan
Modul: Shift Kasir
Stack: Laravel 13, PHP 8.3+, Vue 3 + Inertia, PostgreSQL

## Tujuan

Menambahkan shift kasir per kasir per cabang agar:

- Kasir tidak bisa membuat pesanan atau menerima pembayaran tanpa shift terbuka.
- Uang tunai awal, penjualan tunai, kas masuk/keluar, kas dihitung, setoran, dan selisih tercatat per shift.
- Shift ditutup dengan rekap yang bisa diperiksa owner.
- Shift yang sudah ditutup tidak bisa diedit, kecuali pengisian kas untuk shift yang ditutup otomatis.
- Owner bisa melihat rekap shift per cabang, kasir, dan tanggal, termasuk ringkasan harian per kasir/cabang.

## Keputusan Yang Sudah Ditetapkan

- Shift dimiliki per kasir per cabang, bukan satu shift per cabang.
- Satu kasir hanya boleh punya satu shift terbuka per cabang.
- Beberapa kasir boleh membuka shift bersamaan di cabang yang sama.
- Tiap kasir punya tanggung jawab kas sendiri meskipun secara fisik memakai satu laci.
- Shift tersedia 09:00-15:00 dan 15:00-21:00 Asia/Jakarta.
- Buka shift divalidasi terhadap jendela shift. Buka lebih awal maksimal 15 menit sebelum jendela dimulai.
- Tutup shift terlambat diperbolehkan, tetapi shift basi mendapat reminder dan bisa ditutup otomatis oleh scheduler sesuai aturan di bawah.
- Shift yang terlewat ditutup otomatis tanpa mengarang kas fisik. Owner atau kasir pemilik shift merekonsiliasi kasnya setelah auto-close.
- Reminder shift hanya in-app pada MVP; belum ada push, WhatsApp, atau email.
- Tutup shift lebih awal hanya boleh oleh owner dan wajib alasan.
- Shift tidak bisa ditutup jika masih ada pesanan belum dibayar yang dibuat di shift tersebut. Pesanan yang sudah dibatalkan tidak menghalangi.
- Float kas awal ditetapkan owner per cabang. Kasir hanya mengonfirmasi uang fisik; kasir tidak bisa mengubah nominal float.
- Kasir memasukkan kas dihitung dan jumlah setoran saat tutup shift. Setoran manual dengan saran otomatis.
- Selisih kas boleh terjadi. Jika selisih bukan nol, catatan wajib diisi.
- Kas kecil, pengeluaran manual, dan koreksi kas hanya bisa dicatat owner.
- Cash movement hanya memakai arah masuk/keluar dan alasan; tidak ada kategori terstruktur.
- Setoran adalah bagian dari tutup shift, bukan cash movement biasa.
- Owner bisa melihat ringkasan harian per kasir dan cabang selain daftar per shift.
- QRIS tidak memengaruhi kas fisik.
- Shift tertutup tidak bisa dibuka ulang atau dihapus. Edit hanya untuk rekonsiliasi kas auto-close.
- Owner bisa melihat semua shift. Kasir hanya melihat shift miliknya saat ini.
- Owner bisa menutup shift kasir lain, termasuk untuk tutup lebih awal dengan alasan.
- Owner bisa mencatat cash movement pada shift yang masih terbuka.

## Asumsi Desain

- Jendela shift disimpan di config aplikasi, bukan tabel jadwal per cabang. Perubahan jadwal per cabang ditunda.
- Tidak ada shift lintas tengah malam.
- Pesanan bisa dibuat selama shift terbuka sampai cutoff auto-close, yaitu 15 menit setelah jadwal shift berakhir.
- Setelah cutoff, pesanan baru ditolak tetapi pembayaran pesanan yang sudah ada tetap boleh sampai shift ditutup.
- Owner yang bertindak sebagai kasir juga wajib membuka shift sendiri.
- Owner boleh punya shift terbuka di beberapa cabang sekaligus, maksimal satu per cabang.
- Verifikasi setoran oleh owner bersifat manual melalui laporan, belum ada tombol approval setoran.
- Perubahan float cabang hanya berlaku untuk shift berikutnya; shift yang sudah dibuka menyimpan nominal float saat dibuka.
- Kasir tidak memasukkan angka hitung float. Checkbox konfirmasi berarti kasir sudah menghitung dan jumlahnya sesuai float. Jika tidak sesuai, buka shift ditolak.
- Pembatalan/void pembayaran yang terhubung ke shift tertutup diblokir sampai modul Diskon & Refund dibuat.
- Reminder ditampilkan 30 menit sebelum jadwal berakhir dan saat jadwal berakhir. Reminder hanya tampil di aplikasi.
- Auto-close berjalan 15 menit setelah jadwal berakhir, setiap 5 menit lewat scheduler.
- Auto-close tidak mengisi kas dihitung. Shift auto-close berstatus belum direkonsiliasi sampai owner atau kasir pemilik shift mengisi kas.
- Auto-close tidak menutup shift yang masih punya pesanan `unpaid` non-cancelled. Shift tetap terbuka agar pesanan bisa dibayar/dibatalkan, dan owner melihat peringatan.
- Jika tidak ada pesanan `unpaid` saat cutoff, auto-close menutup shift dan semua pesanan/pembayaran baru ditolak.
- Jika masih ada pesanan `unpaid` saat cutoff, auto-close melewati shift. Shift tetap terbuka, pesanan baru ditolak, tetapi pembayaran pesanan yang ada tetap bisa sampai pesanan lunas/dibatalkan; setelah itu auto-close berikutnya menutup shift.
- Ringkasan harian hanya mengagregasi shift tertutup. Shift terbuka ditampilkan sebagai jumlah terpisah agar angka finansial tidak berubah-ubah.

## Keputusan Tambahan Dari Builder Check

- Tutup shift dari POS memakai cabang yang sedang dipilih. Owner wajib mengirim `branch_id`; kasir tidak boleh memilih cabang lain.
- Shift dianggap sama untuk idempotensi buka hanya jika `window_key`, `scheduled_start_at`, dan `scheduled_end_at` sama. Shift basi dari hari sebelumnya tetap menghalangi sampai ditutup manual atau auto-close.
- Tanggal laporan shift memakai tanggal Jakarta dari `opened_at`, bukan `closed_at`. Shift yang tutup lewat tengah malam tetap masuk tanggal saat shift dibuka.
- Pembayaran antar kasir dalam cabang yang sama tetap diperbolehkan. Kas masuk ke shift kasir yang menerima pembayaran, sedangkan pesanan tetap terkait ke shift kasir pembuatnya. Shift pembuat tidak bisa tutup selama pesanan belum dibayar.
- Kasir yang dinonaktifkan atau dipindah cabang tidak otomatis menutup shift. Shift lama ditutup oleh auto-close atau owner dari admin. Kasir yang sudah pindah tidak bisa menutup shift cabang lama dari POS.
- Cash movement dengan idempotency key yang sudah ada mengembalikan movement lama walaupun shift sudah tertutup. Pengecekan idempotensi dilakukan sebelum validasi shift terbuka.
- Tutup shift dari POS hanya menutup shift milik aktor di cabang yang dipilih. Owner yang ingin menutup shift kasir lain memakai halaman admin.
- Satu kasir hanya boleh punya satu shift per jendela per cabang per hari, baik masih terbuka maupun sudah tertutup. Jendela yang sudah ditutup tidak bisa dibuka lagi.
- Owner tidak membuka shift atas nama kasir lain; owner hanya membuka shift untuk dirinya sendiri.
- Owner yang menutup shift sendiri lebih awal tetap wajib mengisi alasan. Tidak ada aturan terpisah untuk self-close.
- Void pembayaran yang masih berada di shift terbuka mengurangi penjualan tunai dan kas seharusnya shift itu karena query menghitung payment berstatus `paid` saja. Uang fisik yang sudah diterima harus dikembalikan kasir; tidak ada cash movement otomatis. Jika uang belum dikembalikan, selisih akan muncul saat tutup.
- `expected_cash` selalu dibandingkan dengan kas fisik dihitung sebelum setoran. Float tetap berada di laci. `counted_cash` mencakup float. Setoran yang disarankan adalah `counted_cash - opening_cash`. Float shift berikutnya tetap memakai `branch.cash_float`, bukan float yang dipertahankan.
- Gate shift berada di action layer: `CreateOrder` dan `MarkOrderPaid` memanggil action `ResolveOpenShift`. Controller hanya meneruskan aktor dan cabang. Factory, seeder, dan command yang membuat data langsung tidak memakai gate.
- Pada 14:50, jendela aktual masih `morning`, jadi shift yang dibuka adalah `morning`, bukan `evening`. Jendela aktual selalu diprioritaskan.
- Kasir yang mengonfirmasi float tidak mencatat angka fisik berbeda. Jika uang fisik tidak sesuai float, buka shift ditolak dan kasir harus memanggil owner. Tidak ada variance saat buka.
- Pesanan lama dengan `shift_id = null` yang masih belum dibayar boleh dibayar setelah kasir membuka shift. Pembayaran masuk ke shift penerima saat itu, dan `orders.shift_id` lama tidak diubah.
- Cash movement tidak memakai kategori terstruktur. Hanya ada `direction` dan `reason` wajib. Kas kecil, pengeluaran, penambahan kas, dan koreksi dibedakan lewat alasan.
- Setoran yang berbeda dari saran tidak dianggap variance kedua dan tidak mewajibkan catatan tambahan. Rekap menampilkan saran, setoran aktual, dan float yang dipertahankan agar owner bisa memeriksa.
- Tanggal bisnis shift adalah tanggal Jakarta dari `opened_at`. Tidak ada kolom `business_date`; query memakai rentang UTC yang dikonversi dari tanggal Jakarta.
- Auto-close adalah penutupan operasional, bukan rekonsiliasi kas. `counted_cash`, `variance`, dan `cash_deposited` tetap null sampai owner atau kasir pemilik shift merekonsiliasi.
- Owner atau kasir pemilik shift hanya bisa merekonsiliasi shift yang `close_type = auto` dan `reconciled_at = null`, dan hanya field kas yang bisa diisi. Snapshot penjualan tidak berubah.
- Manual close langsung dianggap terekonkiliasi: `reconciled_at = closed_at` dan `reconciled_by_id = closed_by_id`.
- Auto-close menulis audit dengan aktor null. `audit_logs.actor_id` dibuat nullable dan `WriteAuditLog` menerima `?User`.
- Jika tutup manual dan auto-close layak bersamaan, lock baris shift menentukan pemenang. Jika auto-close menang, input kasir ditolak dengan pesan agar memakai rekonsiliasi. Jika tutup manual menang, command auto-close melihat shift sudah tertutup dan melewatinya. Auto-close tidak menunggu percobaan tutup manual.
- Auto-close memakai cutoff yang sama dengan blokir pesanan baru. Tidak ada masa tenggang tambahan di luar 15 menit.
- Command auto-close berjalan setiap 5 menit dan idempotent. Jika ada pesanan `unpaid`, command melewati shift dan mencoba lagi pada run berikutnya.
- Setelah cutoff auto-close, `CreateOrder` menolak pesanan baru. `MarkOrderPaid` tetap mengizinkan pembayaran sampai shift benar-benar tertutup; jika auto-close sudah berjalan, shift tertutup dan pembayaran ditolak.
- Ringkasan harian mengagregasi hanya shift yang sudah tertutup, termasuk auto-close yang belum direkonsiliasi. Shift terbuka hanya dihitung di kolom `open_count`.
- Totals penjualan pada ringkasan harian tetap dihitung dari semua shift tertutup. Totals kas dihitung, variance, dan setoran hanya dari shift yang sudah direkonsiliasi. Shift auto-close belum direkonsiliasi dihitung di `unreconciled_count`.

## Non-Tujuan

- Jadwal shift, absensi, lembur, payroll.
- Perhitungan tips, komisi, atau bagi hasil.
- Integrasi laci kas, printer struk kas, atau hardware kasir.
- Rekonsiliasi bank, QRIS settlement, atau pembayaran non-tunai.
- Refund, void, dan koreksi finansial setelah shift tertutup.
- Shift lintas tengah malam.
- Multi-currency.
- Approval workflow setoran.
- Notifikasi push, WhatsApp, atau email untuk reminder shift.
- Auto-close yang mengisi kas dihitung atau mengarang selisih.
- Laporan shift untuk kasir selain shift aktifnya.
- Ringkasan harian untuk kasir.

## Model Data

### Perubahan `branches`

Tambahkan:

- `cash_float` bigint, default 0, minimal 0. Nominal float kas awal per cabang dalam Rupiah. Shift menyimpan `opening_cash` saat dibuka, sehingga perubahan float tidak mengubah shift lama.

Form cabang owner menampilkan field `Float kas`. Perubahan masuk audit `branch.updated` dan hanya memengaruhi shift baru.

### Tabel `cashier_shifts`

Kolom:

- `id`
- `branch_id` foreign key branches, restrict on delete
- `user_id` foreign key users, restrict on delete; kasir pemilik shift
- `window_key` string; contoh `morning`, `evening`
- `scheduled_start_at` timestamp UTC
- `scheduled_end_at` timestamp UTC
- `opened_at` timestamp UTC
- `closed_at` timestamp nullable UTC
- `opening_cash` bigint
- `cash_sales_total` bigint nullable; snapshot saat tutup
- `qris_sales_total` bigint nullable; snapshot saat tutup
- `cash_in_total` bigint nullable; snapshot saat tutup
- `cash_out_total` bigint nullable; snapshot saat tutup
- `expected_cash` bigint nullable; snapshot saat tutup
- `counted_cash` bigint nullable
- `variance` bigint nullable; `counted_cash - expected_cash`
- `cash_deposited` bigint nullable
- `closing_note` text nullable; alasan tutup manual atau pesan auto-close
- `closed_by_id` foreign key users nullable, restrict on delete; null untuk auto-close
- `close_type` string nullable: `manual` atau `auto`; null saat terbuka
- `reconciled_at` timestamp nullable UTC
- `reconciled_by_id` foreign key users nullable, restrict on delete
- `reconciliation_note` text nullable
- `timestamps`

Aturan konsistensi:

- Shift terbuka: `closed_at`, `close_type`, `reconciled_at`, `reconciled_by_id` null.
- Tutup manual: `closed_at` dan `reconciled_at` terisi, `close_type = manual`, `reconciled_by_id = closed_by_id`.
- Auto-close: `closed_at` terisi, `close_type = auto`, `closed_by_id` null, `reconciled_at` dan `reconciled_by_id` null.
- Rekonsiliasi auto-close: `reconciled_at` dan `reconciled_by_id` terisi, `close_type` tetap `auto`.
- Snapshot penjualan dan `expected_cash` diisi saat close manual atau auto-close; `counted_cash`, `cash_deposited`, dan `variance` diisi saat close manual atau rekonsiliasi.

Index:

- `(branch_id, opened_at)`
- `(user_id, closed_at)`
- `(closed_at)`
- `(close_type, reconciled_at)`
- Unique `(user_id, branch_id, window_key, scheduled_start_at, scheduled_end_at)` untuk mencegah shift ganda pada jendela yang sama.
- Partial unique index `(user_id, branch_id) WHERE closed_at IS NULL` untuk mencegah dua shift terbuka untuk kasir dan cabang yang sama.

Status shift diturunkan:

- `open`: `closed_at` null.
- `auto_closed_unreconciled`: `close_type = auto` dan `reconciled_at` null.
- `closed`: `reconciled_at` tidak null, baik manual close maupun auto-close yang sudah direkonsiliasi.

Tidak ada kolom `status` terpisah.

### Perubahan `audit_logs`

- `actor_id` dibuat nullable agar auto-close bisa menulis audit sistem.
- `WriteAuditLog` menerima `?User`.
- UI audit menampilkan aktor null sebagai `Sistem`.

### Tabel `cash_movements`

Kolom:

- `id`
- `cashier_shift_id` foreign key cashier_shifts, restrict on delete
- `direction` string: `in` atau `out`
- `amount` bigint, minimal 1
- `reason` string, wajib
- `actor_id` foreign key users, restrict on delete
- `idempotency_key` string
- `timestamps`

Unique:

- `(cashier_shift_id, idempotency_key)`

Index:

- `(cashier_shift_id, direction)`

Cash movement bersifat append-only. Tidak ada update atau delete. Koreksi dilakukan dengan mencatat movement berlawanan arah.

### Perubahan `orders`

Tambahkan:

- `shift_id` foreign key cashier_shifts nullable, restrict on delete
- Index `(shift_id, payment_status)`

Pesanan lama boleh `null`. Pesanan baru dari POS wajib terhubung ke shift kasir yang membuat pesanan.

### Perubahan `payments`

Tambahkan:

- `shift_id` foreign key cashier_shifts nullable, restrict on delete
- Index `(shift_id, method, status)`

Pembayaran lama boleh `null`. Pembayaran baru wajib terhubung ke shift kasir yang menerima pembayaran. Ini berlaku untuk tunai maupun QRIS.

## Config Shift

Buat `config/shifts.php`:

- `timezone`: `Asia/Jakarta`
- `early_open_minutes`: `15`
- `reminder_minutes`: `30`
- `auto_close_grace_minutes`: `15`
- `windows`: daftar jendela dengan `key`, `label`, `start`, `end`
    - `morning`, `Shift 1`, `09:00`, `15:00`
    - `evening`, `Shift 2`, `15:00`, `21:00`

Aturan pemilihan jendela saat buka shift:

- Jika waktu sekarang berada di dalam jendela aktual (`start <= now < end`), pilih jendela itu.
- Jika tidak, tapi berada dalam masa buka awal (`start - 15 menit <= now < start`), pilih jendela berikutnya.
- Jika tidak ada yang cocok, tolak dengan pesan di luar jam shift.
- Jendela aktual diprioritaskan agar tidak ambigu saat mendekati pergantian shift.
- `scheduled_start_at` dan `scheduled_end_at` dihitung di timezone Jakarta lalu disimpan UTC.

## Alur

### Buka Shift

Endpoint: `POST /pos/shift/open`

Input:

- `branch_id` wajib untuk owner, opsional untuk kasir dan harus sama dengan cabang kasir.
- `opening_cash_confirmed` wajib `true`.

Perilaku:

1. Pastikan user aktif, role owner/kasir, dan punya akses cabang.
2. Pastikan cabang aktif.
3. Tentukan jendela shift dari config dan waktu Jakarta. Pada 14:50 jendela aktual `morning` dipilih, bukan masa buka awal `evening`.
4. Jika user sudah punya shift terbuka di cabang itu:
    - Jika `window_key`, `scheduled_start_at`, dan `scheduled_end_at` sama, kembalikan shift yang ada tanpa membuat baru.
    - Selain itu, termasuk shift basi dari hari sebelumnya, tolak dengan pesan agar menutup shift sebelumnya.
5. Jika user sudah punya shift tertutup untuk jendela dan jadwal yang sama, tolak dengan pesan `Shift untuk jendela ini sudah ditutup.` Satu kasir hanya boleh punya satu shift per jendela per cabang per hari.
6. Owner selalu membuka shift untuk dirinya sendiri. Owner tidak bisa membuka shift atas nama kasir lain.
7. Buat shift dengan `opening_cash = branch.cash_float`.
8. Tulis audit `shift.opened`.
9. Kembalikan ringkasan shift.

Jika dua request buka shift berjalan bersamaan, partial unique index menjadi pengaman terakhir. Request yang kalah mengembalikan shift yang sudah ada jika jendelanya sama, atau pesan validasi.

Shift bisa dibuka oleh owner maupun kasir. Kasir tidak bisa membuka shift untuk kasir lain.

### POS Tanpa Shift

- `GET /pos` mengirim shift terbuka user untuk cabang yang dipilih.
- Jika tidak ada shift terbuka, halaman POS menampilkan panel buka shift, bukan katalog dan keranjang.
- `POST /pos/orders` menolak pembuatan pesanan tanpa shift terbuka.
- `POST /orders/{order}/pay` menolak pembayaran tanpa shift terbuka.
- Jika shift mendekati akhir jadwal, POS menampilkan reminder 30 menit sebelum berakhir.
- Jika shift sudah melewati jadwal, POS menampilkan peringatan dan tombol tutup shift. Pesanan baru masih bisa dibuat sampai cutoff 15 menit setelah jadwal berakhir.
- Setelah cutoff, pesanan baru ditolak. Pembayaran pesanan yang sudah ada tetap bisa dilakukan sampai shift ditutup atau auto-close.

### Buat Pesanan

`CreateOrder` wajib:

1. Cek idempotency lebih dulu. Jika pesanan sudah ada, kembalikan pesanan lama meskipun shift sudah tertutup.
2. Di dalam transaksi, cari shift terbuka milik aktor untuk cabang pesanan dan lock share baris shift.
3. Jika tidak ada, tolak dengan pesan `Buka shift terlebih dahulu.`
4. Jika `now >= scheduled_end_at + auto_close_grace_minutes`, tolak dengan pesan `Shift sudah melewati batas pesanan. Tutup shift.`
5. Simpan `orders.shift_id`.

Gate memakai cabang pesanan, bukan cabang yang sedang dipilih di UI. Owner yang membuat pesanan untuk cabang lain wajib punya shift terbuka di cabang tujuan. Jika tidak, pesanan ditolak.

Lock share membuat tutup shift menunggu pesanan selesai dibuat, sehingga pesanan tidak bisa lolos setelah pengecekan pesanan belum dibayar.

### Pembayaran

`MarkOrderPaid` wajib:

1. Cek pembayaran yang sudah ada lebih dulu. Jika sudah ada, kembalikan tanpa perlu shift terbuka.
2. Di dalam transaksi, cari shift terbuka milik aktor untuk cabang pesanan dan lock share baris shift.
3. Jika tidak ada, tolak dengan pesan `Buka shift terlebih dahulu.`
4. Simpan `payments.shift_id`.
5. Pembayaran tunai dan QRIS sama-sama terhubung ke shift.
6. Pembayaran tetap boleh setelah cutoff pesanan, selama shift belum ditutup atau di-auto-close. Jika shift dilewati auto-close karena masih punya pesanan `unpaid`, pembayaran tetap bisa sampai pesanan selesai. Jika auto-close sudah menutup shift, pembayaran ditolak; owner atau kasir pemilik shift harus merekonsiliasi, atau memakai modul lain untuk pembayaran susulan.

### Cash Movement

Endpoint owner:

- `POST /admin/shifts/{shift}/cash-movements`

Input:

- `direction`: `in` atau `out`
- `amount`: integer minimal 1, maksimal 1.000.000.000
- `reason`: wajib, maksimal 500 karakter
- `idempotency_key`: wajib, maksimal 100 karakter

Perilaku:

1. Hanya owner.
2. Lock baris shift dengan `FOR UPDATE`.
3. Jika idempotency key sudah ada di shift itu, kembalikan movement lama. Pengecekan ini dilakukan sebelum cek shift terbuka, sehingga retry setelah shift tertutup tetap mengembalikan movement lama.
4. Shift harus masih terbuka.
5. Hitung kas tersedia saat ini. Untuk `direction = out`, tolak jika `amount > kas tersedia`.
6. Simpan movement.
7. Tulis audit `shift.cash_movement` dengan target shift dan detail movement.
8. Movement tidak bisa diubah atau dihapus.

Kas kecil dan pengeluaran manual dicatat sebagai `out`. Penambahan kas oleh owner dicatat sebagai `in`. Koreksi memakai arah yang sesuai dan alasan jelas.

### Tutup Shift

Endpoint kasir: `POST /pos/shift/close`
Endpoint owner untuk shift mana pun: `POST /admin/shifts/{shift}/close`

Tutup manual hanya untuk shift terbuka. Shift yang sudah ditutup otomatis tetapi belum direkonsiliasi memakai alur rekonsiliasi.

Input POS:

- `branch_id`: wajib untuk owner, opsional untuk kasir dan harus sama dengan cabang kasir
- `counted_cash`: integer minimal 0, maksimal 1.000.000.000
- `cash_deposited`: integer minimal 0, maksimal `counted_cash`
- `closing_note`: nullable, wajib jika selisih bukan nol atau tutup lebih awal
- `idempotency_key` tidak diperlukan karena penutupan bersifat natural idempotent.

Perilaku:

1. Tentukan target shift:
    - Kasir: shift terbuka miliknya di cabangnya.
    - Owner lewat POS: shift terbuka miliknya sendiri di cabang yang dipilih. Owner tidak menutup shift kasir lain lewat POS.
    - Owner lewat admin: shift pada route.
2. Pastikan user berhak menutup target shift.
3. Lock baris shift dengan `FOR UPDATE`.
4. Jika shift sudah tertutup:
    - Jika `close_type = auto` dan `reconciled_at` null, tolak dengan pesan `Shift ditutup otomatis. Gunakan rekonsiliasi.`
    - Jika sudah direkonsiliasi atau manual, kembalikan sukses tanpa perubahan.
5. Jika sekarang sebelum `scheduled_end_at` dan aktor bukan owner, tolak.
6. Jika sekarang sebelum `scheduled_end_at` dan aktor owner, wajib `closing_note`.
7. Cek pesanan yang belum dibayar: `shift_id = shift`, `payment_status = unpaid`, dan `status != cancelled`. Jika ada, tolak dan sebutkan jumlahnya. Pesanan `paid` dan `voided` tidak menghalangi. Pesanan yang tertinggal `unpaid` karena dialog pembayaran ditutup tetap menghalangi sampai dibayar atau dibatalkan.
8. Hitung total:
    - `cash_sales_total` = jumlah `payments.amount` dengan `shift_id = shift`, `method = cash`, `status = paid`.
    - `qris_sales_total` = jumlah `payments.amount` dengan `shift_id = shift`, `method = qris_static`, `status = paid`.
    - `cash_in_total` = jumlah cash movement `in` di shift.
    - `cash_out_total` = jumlah cash movement `out` di shift.
    - `expected_cash = opening_cash + cash_sales_total + cash_in_total - cash_out_total`.
    - `variance = counted_cash - expected_cash`.
9. Simpan snapshot `cash_sales_total`, `qris_sales_total`, `cash_in_total`, `cash_out_total`, `expected_cash`, `counted_cash`, `variance`, `cash_deposited`, `closing_note`, `closed_at`, `closed_by_id`, `close_type = manual`, `reconciled_at = closed_at`, `reconciled_by_id = closed_by_id`.
10. Tulis audit `shift.closed` dengan before/after dan alasan catatan.
11. Kembalikan rekap.

Jika `variance != 0` dan `closing_note` kosong, tutup ditolak.

Owner yang menutup shift kasir lain dari admin tetap wajib memasukkan kas dihitung dan setoran. Jika owner tidak bisa menghitung fisik, shift dibiarkan sampai auto-close, lalu direkonsiliasi. Tidak ada status close manual tanpa hitung kas.

### Auto-Close dan Reminder

Reminder bersifat in-app:

- 30 menit sebelum `scheduled_end_at`: POS menampilkan banner `Shift berakhir dalam 30 menit.` Banner hanya informatif, tidak memblokir.
- Saat `scheduled_end_at` lewat: banner berubah menjadi `Shift sudah melewati jadwal. Tutup shift.` dan tombol tutup ditonjolkan.
- Reminder dihitung dari waktu sekarang, bukan dari tabel notifikasi. Tidak perlu tabel reminder.

Auto-close dijalankan command `shifts:auto-close` lewat scheduler setiap 5 menit, timezone `Asia/Jakarta`, `withoutOverlapping()`:

```php
Schedule::command('shifts:auto-close')
    ->everyFiveMinutes()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
```

- Kandidat: shift `closed_at IS NULL` dan `now >= scheduled_end_at + 15 menit`.
- Jika masih ada pesanan `unpaid` non-cancelled, lewati shift dan coba lagi pada run berikutnya.
- Jika tidak ada, hitung snapshot seperti tutup manual dengan `cash_sales_total`, `qris_sales_total`, `cash_in_total`, `cash_out_total`, dan `expected_cash`.
- `counted_cash`, `variance`, dan `cash_deposited` tetap null.
- `closed_at` = waktu command berjalan; `closed_by_id` null; `close_type = auto`; `closing_note` = `Ditutup otomatis oleh sistem karena melewati jadwal shift.`
- Tulis audit `shift.auto_closed` dengan aktor null.
- Auto-close tidak menutup shift yang pesanannya masih `unpaid`. Shift tersebut tetap terbuka dan muncul di daftar peringatan owner.

Rekonsiliasi owner:

- Endpoint `POST /admin/shifts/{shift}/reconcile` hanya untuk owner, dan `POST /pos/shifts/{shift}/reconcile` untuk kasir pemilik shift.
- Kasir hanya bisa merekonsiliasi shift auto-close miliknya di cabangnya. Owner bisa semua.
- Input `counted_cash`, `cash_deposited`, `reconciliation_note`.
- `variance = counted_cash - expected_cash`.
- `reconciliation_note` wajib jika variance bukan nol.
- Set `reconciled_at`, `reconciled_by_id`, `reconciliation_note`.
- Tidak mengubah `closing_note` auto-close, snapshot penjualan, payment, atau order.
- Tulis audit `shift.reconciled`.
- Manual close dianggap sudah direkonsiliasi pada saat tutup.

Rute rekonsiliasi kasir:

- `POST /pos/shifts/{shift}/reconcile` name `pos.shifts.reconcile`, middleware `auth` dan `role:cashier,owner`.
- Route POS hanya untuk shift milik aktor. Owner memakai route admin untuk shift kasir lain.
- Link rekonsiliasi muncul di POS saat shift auto-close milik kasir belum direkonsiliasi.

`counted_cash` adalah uang fisik di laci sebelum setoran, termasuk float. `cash_deposited` adalah uang yang benar-benar diserahkan. Server tidak menghitung setoran otomatis. UI menampilkan saran `max(0, counted_cash - opening_cash)`, tetapi kasir bisa mengubahnya selama tidak melebihi kas dihitung. `float_retained = counted_cash - cash_deposited` ditampilkan di rekap, bukan kolom database. Shift berikutnya tetap memakai `branch.cash_float`; jika float yang dipertahankan berbeda, owner harus menyesuaikan uang fisik sebelum shift berikutnya.

### Pesanan pada Shift Basi

Shift yang belum ditutup bisa punya pesanan `unpaid` dari hari sebelumnya. Halaman pesanan POS hari ini saja tidak cukup.

- `OrderController@index` mendukung query `shift=<id>`.
- Untuk kasir, daftar pesanan selalu menyertakan semua pesanan dari shift terbuka miliknya, termasuk yang dibuat sebelum hari ini.
- Untuk owner, query `shift=<id>` menampilkan semua pesanan pada shift itu, tanpa batas tanggal.
- Admin shift detail menyediakan link `Lihat pesanan shift` ke `/pos/orders?branch=<branch>&shift=<shift>`.
- Owner atau kasir yang berhak bisa membayar atau membatalkan pesanan `unpaid` dari halaman itu memakai route yang sudah ada.
- Tidak ada force-close atau abandon. Shift hanya bisa ditutup setelah pesanan `unpaid` dibayar atau dibatalkan.

### Void Pembayaran Pada Shift Tertutup

`VoidOrder` wajib menolak pembayaran yang `shift_id`-nya menunjuk shift dengan `closed_at` tidak null. Pesan: `Pembayaran pada shift yang sudah ditutup tidak dapat di-void. Gunakan modul refund.`

Void pada shift yang masih terbuka tetap diperbolehkan dan tidak membuat cash movement. Payment berubah menjadi `voided`, sehingga otomatis keluar dari `cash_sales_total` dan `expected_cash`. Kasir bertanggung jawab mengembalikan uang tunai ke pelanggan; jika tidak, selisih kas akan muncul saat tutup.

Karena tutup manual menolak pesanan `unpaid` dan auto-close melewati shift yang masih punya pesanan `unpaid`, tidak ada pesanan `unpaid` baru yang tertinggal di shift tertutup. Satu-satunya pengecualian adalah data lama dengan `shift_id = null`, yang tidak terkait shift mana pun.

Ini menjaga snapshot kas shift tetap konsisten. Modul Diskon & Refund akan menangani reversing entry.

## Perhitungan dan Snapshot

- Halaman laporan berada di `admin/shifts`, bukan `admin/reports`.
- Laporan punya dua tampilan: daftar per shift dan ringkasan harian per tanggal + cabang + kasir.
- Filter tanggal laporan memakai tanggal Jakarta dari `opened_at`. Label UI: `Tanggal buka shift`.
- Rekap menampilkan total tunai, total QRIS, jumlah pesanan, dan jumlah/nilai void sebagai informasi. Hanya tunai yang masuk `expected_cash`.
- `jumlah pesanan` memakai `orders.shift_id`; total uang dan void memakai `payments.shift_id`. Jika kasir A membuat dan kasir B membayar, A mendapat hitungan pesanan, B mendapat uang dan void.
- Shift terbuka menampilkan total live dari query.
- Shift tertutup menampilkan snapshot yang disimpan saat tutup.
- Auto-close menyimpan snapshot penjualan dan `expected_cash`, tetapi tidak mengisi kas dihitung, variance, atau setoran.
- Rekonsiliasi auto-close hanya mengisi field kas dan tidak mengubah snapshot penjualan.
- Snapshot mencegah laporan berubah jika ada perubahan data setelah tutup.
- `expected_cash` dan `variance` disimpan saat tutup manual. Untuk auto-close, `expected_cash` disimpan dan `variance` diisi saat rekonsiliasi.
- Total live untuk shift terbuka dihitung dari payment tunai berstatus paid dan cash movement.
- QRIS tidak dihitung sebagai kas, tetapi totalnya disimpan di `qris_sales_total` untuk laporan.
- Pembayaran tunai yang di-void sebelum shift tutup tidak dihitung karena statusnya bukan paid.
- Pesanan yang dibatalkan tetapi belum dibayar tidak menghalangi tutup shift dan tidak dihitung sebagai penjualan tunai.
- Ringkasan harian mengagregasi hanya shift tertutup, termasuk auto-close yang belum direkonsiliasi. Shift terbuka muncul sebagai `open_count` dan tidak masuk total finansial.
- Total penjualan tunai, QRIS, kas masuk, kas keluar, dan kas seharusnya dihitung dari semua shift tertutup, termasuk auto-close yang belum direkonsiliasi.
- Total `variance`, `counted`, dan setoran hanya menjumlahkan shift yang sudah direkonsiliasi; shift auto-close yang belum direkonsiliasi tetap dihitung di `unreconciled_count`.
- `jumlah pesanan` per shift mengikuti `orders.shift_id` (shift pembuat). `void` mengikuti `payments.shift_id` (shift pembayar), karena yang memengaruhi kas adalah pembayaran yang di-void.
- Total tunai per shift mengikuti `payments.shift_id` (shift penerima), bukan shift pembuat.

## Hak Akses

- Owner:
    - buka shift di cabang mana pun
    - lihat semua shift
    - tutup shift mana pun, dengan kas dihitung dan setoran
    - tutup lebih awal dengan alasan
    - rekonsiliasi shift auto-close
    - catat cash movement
    - lihat laporan shift dan ringkasan harian
    - atur float cabang
- Kasir:
    - buka shift sendiri di cabangnya
    - lihat shift aktif sendiri
    - tutup shift sendiri jika sudah lewat jadwal atau tepat di akhir jadwal
    - rekonsiliasi shift auto-close miliknya
    - tidak bisa melihat shift kasir lain
    - tidak bisa mencatat cash movement
    - tidak bisa mengubah float
    - tidak bisa menutup shift cabang lama setelah dipindah cabang; owner yang menutup dari admin
- Kitchen:
    - tidak punya akses ke modul shift.

## UI

Mengikuti design system yang sudah ada. Tidak membuat visual identity baru. Warna, tipografi, spacing, dan komponen memakai token yang ada.

### POS

- Jika belum ada shift:
    - tampilkan panel `Buka shift` di area utama
    - tampilkan cabang, jendela shift saat ini, float kas, checkbox konfirmasi uang fisik, dan tombol buka
    - jika di luar jendela, tampilkan pesan dan jadwal berikutnya
    - katalog dan keranjang tidak ditampilkan
- Jika shift terbuka:
    - badge shift di header: nama jendela, jam buka, total penjualan tunai, kas seharusnya
    - tombol `Tutup shift`
    - ringkasan kas bisa dibuka tanpa meninggalkan POS
- Dialog tutup shift:
    - ringkasan kas awal, penjualan tunai, kas masuk, kas keluar, kas seharusnya
    - input kas dihitung
    - input setoran dengan saran otomatis
    - tampilkan selisih dan float yang dipertahankan
    - catatan wajib jika selisih atau tutup awal
    - konfirmasi
- Reminder:
    - 30 menit sebelum jadwal berakhir: banner `Shift berakhir dalam 30 menit.`
    - setelah jadwal berakhir: banner `Shift sudah melewati jadwal. Tutup shift.`
    - setelah cutoff: banner `Batas pesanan sudah lewat. Tutup shift.` dan tombol buat pesanan dinonaktifkan di UI; server tetap menolak lewat action.
- Auto-close tidak memunculkan dialog; jika shift tertutup otomatis, POS kembali ke panel buka shift saat halaman dimuat ulang dan menampilkan pesan bahwa shift ditutup sistem.
- Jika ada shift auto-close milik kasir yang belum direkonsiliasi, POS menampilkan panel `Rekonsiliasi shift` dengan input kas dihitung, setoran, dan catatan. Setelah rekonsiliasi, panel buka shift kembali normal.
- Halaman `Pesanan` menampilkan badge shift aktif, status cutoff, pesanan dari shift aktif termasuk yang dibuat sebelum hari ini, dan tombol bayar untuk pesanan `unpaid` sampai shift benar-benar tertutup.
- Halaman `Pesanan` dengan query `shift=<id>` menampilkan semua pesanan shift itu tanpa batas tanggal dan dipakai owner untuk menyelesaikan pesanan basi.

### Admin

- Menu `Shift` untuk owner.
- Dashboard owner:
    - kartu `Shift perlu perhatian` berisi jumlah shift lewat jadwal, shift auto-close belum direkonsiliasi, dan shift dengan pesanan tertunda
    - link ke daftar shift dengan filter yang sesuai
- Halaman daftar shift:
    - tab `Per shift` dan `Ringkasan harian`
    - filter tanggal dari/sampai, cabang, kasir, status
    - kolom per shift: cabang, kasir, jendela, buka, tutup, kas awal, penjualan tunai, QRIS, kas seharusnya, kas dihitung, selisih, setoran, status
    - baris bisa dibuka ke detail
    - shift `auto_closed_unreconciled` diberi badge dan tombol `Rekonsiliasi`
- Ringkasan harian:
    - filter tanggal dari/sampai, cabang, kasir
    - grup per tanggal Jakarta, cabang, dan kasir
    - kolom: jumlah shift tertutup, total tunai, total QRIS, total kas masuk, total kas keluar, total kas seharusnya, total kas dihitung, total selisih, total setoran, jumlah shift auto-close belum direkonsiliasi, jumlah shift terbuka
    - total kas dihitung, selisih, dan setoran hanya dari shift yang sudah direkonsiliasi; beri label `Dari shift terekonkiliasi`
    - agregasi hanya dari shift tertutup. Shift terbuka hanya dihitung di `open_count`.
- Halaman detail shift:
    - ringkasan lengkap
    - daftar cash movement
    - form cash movement jika shift terbuka
    - tombol tutup shift untuk owner
    - tombol `Rekonsiliasi` untuk shift auto-close yang belum direkonsiliasi
    - link ke pesanan terkait
- Form rekonsiliasi:
    - kas dihitung, setoran, catatan rekonsiliasi
    - tampilkan kas seharusnya dan selisih
    - catatan wajib jika selisih bukan nol
- Form cabang:
    - field `Float kas`
    - bantuan: `Nominal uang fisik yang harus ada di awal shift.`

### Komponen

- `ShiftGate.vue`: panel buka shift.
- `ShiftSummary.vue`: ringkasan kas.
- `CloseShiftDialog.vue`: dialog tutup shift.
- `CashMovementForm.vue`: form owner.
- `ShiftStatusBadge.vue`: badge status dan jendela.
- `ReconcileShiftDialog.vue`: form rekonsiliasi auto-close.
- `ShiftDailySummaryTable.vue`: tabel ringkasan harian.

Tidak perlu chart. Tidak perlu animasi dekoratif.

## Validasi

- `branch_id` harus ada dan aktif.
- Kasir hanya boleh memakai cabangnya.
- Float cabang minimal 0 dan hanya boleh diubah owner.
- Shift hanya untuk user aktif dengan role `cashier` atau `owner`; kitchen tidak boleh akses.
- `close_type` hanya `manual` atau `auto`; nilainya null saat shift terbuka.
- `reconciled_at` diisi saat tutup manual, atau saat owner/kasir pemilik shift merekonsiliasi shift `auto`.
- Buka shift hanya dalam jendela atau masa buka awal.
- Tidak boleh ada dua shift terbuka untuk user dan cabang yang sama.
- Tutup shift hanya dengan shift terbuka.
- Kas dihitung minimal 0.
- Setoran minimal 0 dan tidak melebihi kas dihitung.
- Catatan wajib jika selisih bukan nol.
- Catatan wajib jika owner menutup sebelum jadwal berakhir.
- Cash movement minimal 1 dan alasan wajib.
- Cash movement hanya untuk shift terbuka.
- Cash movement `out` tidak boleh melebihi kas tersedia saat itu: `opening_cash + cash_sales_total + cash_in_total - cash_out_total`.
- Cash movement `out` yang membuat expected negatif ditolak.
- Pembayaran/pesanan tanpa shift terbuka ditolak.
- Pesanan baru setelah cutoff auto-close ditolak.
- Void pembayaran pada shift tertutup ditolak.
- Rekonsiliasi hanya untuk shift auto-close yang belum direkonsiliasi.
- Rekonsiliasi wajib catatan jika selisih bukan nol.
- Rekonsiliasi tidak boleh mengubah snapshot penjualan.

## Audit

- `shift.opened`
- `shift.closed`
- `shift.auto_closed`
- `shift.reconciled`
- `shift.cash_movement`
- `branch.updated` mencakup perubahan `cash_float`

Audit menyimpan aktor, cabang, target, before/after, alasan, IP, dan user agent sesuai pola `WriteAuditLog` yang ada. Untuk aksi sistem, `actor_id` null dan IP/user agent null.

## Rate Limit

Tambahkan limiter `shifts`:

- buka/tutup shift: 10 request per menit per user
- rekonsiliasi: 10 request per menit per user
- cash movement: 20 request per menit per user

Endpoint shift memakai middleware `throttle:shifts`.

## Rute

POS:

- `GET /pos` tetap, payload ditambah `shift`, `shift_window`, `shift_reminder`, `unreconciled_shift`, dan `orders_cutoff_at`.
- `GET /pos/orders` tetap, payload ditambah `shift`, `orders_cutoff_at`, `can_create_orders`, dan `orders` dari shift aktif tanpa batas tanggal. Query `shift=<id>` menampilkan pesanan shift tertentu untuk owner.
- `POST /pos/shift/open` name `pos.shift.open`
- `POST /pos/shift/close` name `pos.shift.close`
- `POST /pos/shifts/{shift}/reconcile` name `pos.shifts.reconcile`

Admin:

- `GET /admin/shifts` name `admin.shifts.index`; query `view=shifts|daily` untuk tab
- `GET /admin/shifts/{shift}` name `admin.shifts.show`
- `POST /admin/shifts/{shift}/close` name `admin.shifts.close`
- `POST /admin/shifts/{shift}/reconcile` name `admin.shifts.reconcile`
- `POST /admin/shifts/{shift}/cash-movements` name `admin.shifts.cash-movements.store`

Semua rute admin memakai middleware `auth` dan `role:owner`. Rute POS memakai `auth` dan `role:cashier,owner`.

## Struktur Modul

`app/Modules/Shifts/`

- `Actions/OpenShift.php`
- `Actions/CloseShift.php`
- `Actions/AutoCloseShift.php`
- `Actions/ReconcileShift.php`
- `Actions/RecordCashMovement.php`
- `Actions/ResolveOpenShift.php`
- `Actions/ResolveShiftWindow.php`
- `Actions/CalculateShiftTotals.php`
- `Actions/ResolveShiftForClose.php`
- `Actions/BuildShiftReminder.php`
- `Data/ShiftWindowData.php`
- `Enums/CashMovementDirection.php`
- `Enums/ShiftCloseType.php`
- `Http/Controllers/PosShiftController.php`
- `Http/Controllers/AdminShiftController.php`
- `Http/Requests/OpenShiftRequest.php`
- `Http/Requests/CloseShiftRequest.php`
- `Http/Requests/ReconcileShiftRequest.php`
- `Http/Requests/RecordCashMovementRequest.php`
- `Models/CashierShift.php`
- `Models/CashMovement.php`
- `Policies/CashierShiftPolicy.php` dengan ability `open`, `view`, `close`, `reconcile`, `recordCashMovement`, `viewAny`
- `Queries/ShiftReportQuery.php`
- `Queries/ShiftDailySummaryQuery.php`
- `Support/ShiftPresenter.php`

File pendukung:

- `config/shifts.php`
- migration baru untuk `branches.cash_float`, `cashier_shifts`, `cash_movements`, `orders.shift_id`, `payments.shift_id`, dan `audit_logs.actor_id` nullable
- `app/Console/Commands/AutoCloseStaleShifts.php` sebagai adapter tipis ke `AutoCloseShift`
- `CashierShiftFactory`, `CashMovementFactory`
- update `BranchFactory` dengan `cash_float`
- update `BranchPresenter::summary` dengan `cash_float`
- update `resources/js/lib/order.ts` atau tipe shift baru
- update `StaffLayout.vue` menu owner
- update `PosController` payload dengan shift, reminder, dan cutoff
- update `CreateOrder`, `MarkOrderPaid`, dan `VoidOrder`
- update `OrderController` untuk query `shift` dan pesanan shift aktif lintas hari
- update `WriteAuditLog` untuk aktor nullable
- update `AuditLogController` dan `resources/js/pages/admin/audit/Index.vue` untuk aktor `Sistem`
- update `BranchController` validasi dan snapshot
- update `DashboardController` dan `resources/js/pages/admin/Dashboard.vue` untuk kartu shift perlu perhatian
- update `routes/console.php` schedule `shifts:auto-close` setiap 5 menit
- update `resources/js/pages/pos/Index.vue` dan `resources/js/pages/pos/Orders.vue`
- halaman baru `resources/js/pages/admin/shifts/Index.vue` dan `Show.vue`
- komponen shift di `resources/js/components/shifts/`
- update README dan PRD setelah implementasi

## Pengujian

Feature test wajib mencakup:

1. Buka shift di dalam jendela berhasil dan memakai float cabang.
2. Buka shift di luar jendela ditolak.
3. Buka shift 10 menit lebih awal diterima; 20 menit lebih awal ditolak.
4. Kasir tidak bisa membuka shift untuk cabang lain.
5. Dua buka shift berurutan mengembalikan shift yang sama, bukan duplikat.
6. Buka shift jendela berbeda saat masih ada shift terbuka ditolak.
7. Owner bisa membuka shift di cabang berbeda.
8. POS tanpa shift menampilkan panel buka shift; POS dengan shift auto-close belum direkonsiliasi menampilkan panel rekonsiliasi.
9. `POST /pos/orders` tanpa shift ditolak.
10. Pesanan yang dibuat memiliki `shift_id`.
11. Pembayaran tunai dan QRIS memiliki `shift_id`.
12. Pembayaran tanpa shift ditolak.
13. Cash movement hanya owner; kasir mendapat 403.
14. Cash movement pada shift tertutup ditolak.
15. Cash movement idempotent dengan key sama.
16. Tutup shift menghitung kas awal, penjualan tunai, kas masuk, dan kas keluar.
17. QRIS tidak masuk perhitungan kas.
18. Selisih bukan nol tanpa catatan ditolak.
19. Tutup shift dengan pesanan belum dibayar ditolak.
20. Pesanan belum dibayar yang dibatalkan tidak menghalangi tutup shift.
21. Kasir tutup lebih awal ditolak; owner dengan catatan diterima.
22. Tutup terlambat diterima.
23. Tutup manual dua kali tidak mengubah data dan tidak error.
24. Setoran melebihi kas dihitung ditolak.
25. Shift tertutup manual tidak berubah setelah percobaan tutup kedua; tutup manual pada shift auto-close yang belum direkonsiliasi ditolak dan diarahkan ke rekonsiliasi.
26. Void pembayaran pada shift tertutup ditolak.
27. Laporan shift hanya owner; kasir 403.
28. Filter laporan berdasarkan cabang, kasir, dan tanggal.
29. Snapshot shift tertutup tetap sama walaupun data lain berubah.
30. Partial unique index mencegah dua shift terbuka untuk user dan cabang yang sama.
31. Perubahan float cabang hanya memengaruhi shift baru.
32. Audit `shift.opened`, `shift.closed`, dan `shift.cash_movement` tercatat.
33. Owner menutup shift sendiri dari POS dengan `branch_id` cabang yang dipilih; shift kasir lain tetap terbuka dan hanya bisa ditutup lewat admin.
34. Buka shift mengembalikan shift lama hanya jika jendela dan jadwalnya sama; shift basi hari sebelumnya ditolak.
35. Laporan tanggal memakai tanggal Jakarta dari `opened_at`, termasuk shift yang tutup lewat tengah malam.
36. Kasir A membuat pesanan dan kasir B membayar; kas masuk ke shift B dan shift A tetap terblokir sampai pesanan dibayar.
37. Kasir yang dinonaktifkan atau dipindah cabang tidak bisa menutup shift lama dari POS; owner bisa menutup dari admin.
38. Cash movement idempotent tetap mengembalikan movement lama setelah shift tertutup.
39. Pesanan `paid` dan `voided` tidak menghalangi tutup shift; hanya `unpaid` non-cancelled yang menghalangi.
40. Jendela yang sudah ditutup tidak bisa dibuka lagi oleh kasir yang sama di cabang dan hari yang sama.
41. Owner tidak bisa membuka shift atas nama kasir lain.
42. Pada 14:50 shift yang dibuka adalah `morning`.
43. Owner membuat pesanan untuk cabang tanpa shift di cabang itu ditolak.
44. Pesanan lama `shift_id = null` bisa dibayar dan pembayaran masuk ke shift penerima.
45. Void pada shift terbuka mengurangi kas seharusnya; tidak ada cash movement otomatis.
46. Setoran berbeda dari saran tetap diterima selama tidak melebihi kas dihitung dan tidak menambah syarat catatan.
47. Rekap memisahkan total tunai, QRIS, jumlah pesanan, dan void.
48. Reminder 30 menit sebelum jadwal berakhir muncul di payload POS.
49. Setelah jadwal berakhir, pesanan masih bisa dibuat sampai cutoff 15 menit.
50. Setelah cutoff, pesanan baru ditolak tetapi pembayaran pesanan lama masih bisa.
51. Command auto-close menutup shift yang lewat cutoff tanpa pesanan unpaid.
52. Command auto-close melewati shift yang masih punya pesanan unpaid.
53. Auto-close mengisi snapshot penjualan dan expected cash, tetapi kas dihitung, variance, dan setoran null.
54. Auto-close menulis audit `shift.auto_closed` dengan aktor null.
55. Rekonsiliasi auto-close mengisi kas dihitung, setoran, variance, dan catatan.
56. Rekonsiliasi dengan variance bukan nol tanpa catatan ditolak.
57. Rekonsiliasi shift manual ditolak.
58. Auto-close idempotent; command kedua tidak mengubah data.
59. Ringkasan harian per tanggal + cabang + kasir menjumlahkan hanya shift tertutup.
60. Ringkasan harian memisahkan shift auto-close yang belum direkonsiliasi dan shift terbuka.
61. Kasir tidak bisa membuka halaman ringkasan harian.
62. UI audit menampilkan aktor null sebagai `Sistem`.
63. Auto-close tidak menutup shift sebelum cutoff.
64. Manual close mengisi `reconciled_at` dan `reconciled_by_id`.
65. Ringkasan harian tidak memasukkan total finansial shift terbuka.
66. Halaman pesanan menampilkan status cutoff dan pembayaran tetap tersedia.
67. Pesanan `unpaid` dari shift terbuka kemarin tetap tampil di halaman pesanan kasir tanpa batas tanggal.
68. Owner membuka `/pos/orders?shift=<id>` dan bisa membayar/membatalkan pesanan basi.
69. Owner tidak bisa menutup manual shift kasir lain tanpa kas dihitung.
70. Kasir bisa merekonsiliasi shift auto-close miliknya; kasir lain ditolak.
71. Ringkasan harian memasukkan expected cash auto-close yang belum direkonsiliasi, tetapi tidak memasukkan counted/variance/setoran.
72. Order count mengikuti shift pembuat; total tunai dan void mengikuti shift pembayar.
73. Cash movement `out` melebihi kas tersedia ditolak.

Concurrency test:

- Dua proses membuka shift untuk user dan cabang yang sama: hanya satu baris shift terbuka.
- Tutup shift bersamaan dengan cash movement: movement yang kalah harus ditolak setelah shift tertutup, dan total tetap konsisten.
- Tutup shift bersamaan dengan pembayaran pesanan yang belum dibayar: salah satu harus menunggu; shift tidak boleh tertutup dengan pembayaran yang lolos setelah snapshot.
- Auto-close bersamaan dengan pembayaran: keduanya memakai lock shift; auto-close tidak boleh menutup sebelum pembayaran yang sedang diproses selesai.
- Tutup manual dan auto-close bersamaan: lock menentukan pemenang; jika auto-close menang, kasir bisa merekonsiliasi shift miliknya tanpa kehilangan hitungan.

Test lama yang perlu diperbarui:

- `OrderCreationTest` perlu shift terbuka untuk kasir sebelum `POST /pos/orders`.
- `PaymentTest` perlu shift terbuka untuk kasir sebelum `POST /orders/{order}/pay`.
- Factory `OrderFactory` dan factory pembayaran terkait tidak wajib membuat shift karena data lama boleh null.

## Rollout

1. Jalankan migration.
2. Pastikan scheduler berjalan setiap menit agar command `shifts:auto-close` berjalan setiap 5 menit.
3. Owner mengatur float masing-masing cabang.
4. Kasir membuka shift pertama.
5. Pesanan dan pembayaran baru terhubung ke shift.
6. Data lama tetap valid dengan `shift_id = null`.
7. Laporan shift hanya menampilkan data baru yang punya shift.

Tidak ada backfill shift untuk data lama. Jika scheduler belum berjalan, auto-close tidak aktif dan shift basi harus ditutup manual.

## Risiko

- Kasir lupa menutup shift membuat shift terbuka basi dan menghalangi shift berikutnya. Mitigasi: reminder in-app, auto-close setelah 15 menit, dan owner bisa menutup dari admin.
- Auto-close tidak mengisi kas fisik. Mitigasi: shift auto-close berstatus belum direkonsiliasi dan muncul di daftar peringatan owner.
- Auto-close gagal jika masih ada pesanan `unpaid`. Mitigasi: command melewati shift, pesanan baru diblokir setelah cutoff, pembayaran tetap bisa, dan owner melihat peringatan.
- Kasir dinonaktifkan atau dipindah cabang saat shift masih terbuka. Mitigasi: auto-close menutup setelah cutoff jika tidak ada pesanan tertunda; owner bisa menutup atau merekonsiliasi dari admin.
- Perbedaan float fisik dan float sistem dapat menghalangi buka shift. Mitigasi: owner memperbaiki float cabang atau menambah kas fisik sebelum buka.
- Setoran manual bisa berbeda dari saran sistem. Mitigasi: tampilkan float yang dipertahankan dan selisih di rekap.
- Void setelah shift tertutup diblokir sementara sampai modul refund ada. Ini disengaja agar snapshot kas tidak berubah.
- Scheduler tidak berjalan membuat auto-close tidak aktif. Mitigasi: dokumentasi deployment dan peringatan shift basi di admin.
- Perubahan jadwal shift masih lewat config, bukan UI owner. Perubahan per cabang ditunda.
