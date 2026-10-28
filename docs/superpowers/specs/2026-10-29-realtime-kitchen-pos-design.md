# Desain: Dapur & POS Realtime (Laravel Reverb)

- **Tanggal:** 29 Oktober 2026
- **Status:** Disetujui owner (2026-10-29: "langsung kerjakan")
- **Merujuk:** PRD §7.7 (P1: alarm bunyi/visual + indikator waktu persiapan), §7.11 (notifikasi bukan satu-satunya sumber kebenaran), §7.4 (status order), §10.1 (Laravel Reverb belum di stack — ditambahkan sekarang)

## Niat & kenapa

Hari ini layar dapur dan daftar pesanan POS hanya baru jika seseorang menekan F5. Kue dingin, kasir kehilangan, status pembayaran tidak terlihat. Ini fitur realtime pertama sistem: pesanan baru, perubahan status, dan perubahan pembayaran mengalir lewat WebSocket. Di samping itu, ini membangun fondasi untuk notifikasi di masa depan (push mobile/FCM, channel online) — PRD §7.11.

## Keputusan owner (sudah disepakati)

1. **Layar:** dapur (bunyi + timer + indikator terlambat) + POS (auto-refresh daftar pesanan, tanpa bunyi).
2. **Ambang terlambat:** SATU angka global — menit dari `accepted` sampai harus siap. Default **15**, via `KITCHEN_LATE_MINUTES` di env. (Per-status = bukan untuk rilis ini.)
3. **Bunyi** hanya di dapur, default ON per sesi, tombol mute di header dapur.
4. **Desain netral** — tanpa keputusan brand; gaya mengikuti halaman dapur/POS yang ada.

## Keputusan builder (biar bisa dicek owner)

1. **Event = isyarat, data = fetch ulang.** Payload broadcast sengaja kecil: `order_number`, `status`, `branch_id`, `created_at` (ISO-8601), `accepted_at` (nullable ISO-8601), dan `kind` (`created` | `status` | `payment`). **Tanpa PII** (tanpa nama/telepon/alamat pelanggan), tanpa harga, tanpa detail item. Klien yang menerima isyarat melakukan **fetch ulang** dengan idiom yang sudah ada di repo — dapur dan POS adalah halaman Inertia yang sudah memakai `router.reload()`, jadi "fetch ulang" = `router.reload()` pada komponen/props yang terkait. Alasan: payload tetap mini & bebas PII, ketahanan terhadap drift otomatis, dan PII tidak pernah lewat kanal lebih jauh dari yang perlu. Lihat "Payload & privasi".
    - **Lonceng dapur (keputusan owner 29/10):** berbunyi **persis saat pembayaran tuntas** (`kind: 'payment'`) — yaitu saat pesanan baru muncul di layar dapur (`scopeVisibleToKitchen` memfilter `payment_status = paid`). `kind: 'created'` tetap ter-broadcast (dipakai POS; ke depan kanal online yang pre-paid memancarkan `created` + `payment` sekaligus), tapi dapur **tidak berbunyi** untuknya. bunyi palsu dihindari: pesanan yang di-void sebelum kasir menagih tak pernah berlonceng. Trigger bunyi adalah helper murni klien (`shouldRing(payload, seenOrderNumbers)`) dan dites di Vitest.
2. **Channel privat per cabang:** `kitchen.{branch_id}` dan `pos.{branch_id}` (private, hostname otorisasi server-side di `routes/channels.php`). Peran yang ada di sistem: **owner / cashier / kitchen** (tidak ada peran "manager"). Aturan akses: owner → semua channel semua cabang; cashier → `pos.{cabang}-nya` (+`kitchen.{cabang}`? **tidak** — kasir hanya `pos.*`); kitchen → `kitchen.{cabang}-nya`. Cabang "miliknya" = mekanisme yang sudah ada `User::hasBranchAccess()` (kolom `users.branch_id` + owner lintas-cabang). Dapur cabang A **tidak bisa** menerima/membaca channel cabang B — wajib di-test.
3. **Event domain** (event Laravel, di-`shouldBroadcast` ke channel milik cabang order):
    - `OrderCreated` — saat order masuk antrean (dari POS maupun, di kemudian hari, dari web; emitennya sama).
    - `OrderStatusChanged` — setiap transisi status yang sah (queued→accepted→…→cancelled), membawa `from`, `to`.
    - `OrderPaymentChanged` — pembayaran berubah (belum-bayar→terbayar, refund via void/cancel).
      Emiten: `CreateOrder`, `Kitchen\TransitionOrderStatus`, `Payments\MarkOrderPaid`, `Ordering\CancelOrder`/`VoidOrder` (untuk refund payment) — titik yang sudah ada, jangan logika baru.
4. **Kolom `orders.accepted_at`** (nullable `timestamp`, di-set sekali saat transisi ke `accepted`) — belum ada di skema; dibutuhkan timer keterlambatan. Small, one-off, testable.
5. **Bunyi:** beep WebAudio disintesis (tanpa aset file), diputar maks. sekali per `order_number` baru (dedup via `Set` + `sessionStorage`), hanya saat tab terlihat, dan hanya setelah interaksi pertama user (kebijakan autoplay browser — tombol "suara ON/OFF" adalah interaksinya).
6. **Timer & terlambat:** dihitung **di klien**: `elapsed = now - accepted_at` (fallback `created_at` bila belum accepted); terlambat bila `elapsed > KITCHEN_LATE_MINUTES` → chip merah. Dihitung ulang tiap 30 detik dan tiap fetch ulang. Pakan angka ambang dikirim ke prop halaman dapur (bukan rahasia; satu angka menit). Helper pengujian murni `isLate(acceptedAt, now, minutes)` dites di Vitest.
7. **Reconnect:**pusher-js (via `laravel-echo`) otomatis reconnect; saat event `reconnected` → fetch ulang list aktif (dapur dan/atau POS). Tombol refresh manual **tetap** ada. Notifikasi memang bukan single source of truth (PRD §7.11).
8. **CI tidak menguji koneksi WS nyata.** CI menguji: (a) event ter-broadcast ke channel benar dengan payload benar, (b) otorisasi channel lintas-cabang DITOLAK dan lokal LULUS, (c) `accepted_at` ter-set persis sekali, (d) payload bebas PII (assert daftar key), (e) helper `isLate` (Vitest). Uji koneksi WS nyata = disaat dev-deploy (tercatat di seksi deployment).
9. **Dependensi baru** (sudah dapat iya owner via ChatGPT-saran + diperkenankan): `centrifuge/laravel-reverb` (composer), `laravel-echo` + `pusher-js` (npm). Tidak ada Redis untuk ini (Reverb bukan Redis; Redis tetap opsional untuk queue).
10. **Tanpa Reverb server di CI.** Dev menjalankan `php artisan reverb:start --dev` sebagai proses kedua (dicatat di README + AGENTS). Produksi (VPS): binari Reverb via supervisor + nginx `Upgrade: websocket` ke port 8080 — **hanya didokumentasikan** di deployment checklist; production deploy sendiri masih item ops terpisah.

## Luas & batas (scope)

Diluar rilis ini (tetap): push notifikasi mobile (FCM), dashboard live owner, prioritas antrean yang dikonfigurasi, bunyi di POS, operasi offline (PRD: offline bukan MVP), multi-channel selain dapur/POS, penghapusan tombol refresh manual, per-status ambang.

## Payload & privasi (kontrak)

```
OrderCreated        { kind: 'created',  order_number, branch_id, created_at, accepted_at }
OrderStatusChanged  { kind: 'status',   order_number, branch_id, from, to, created_at, accepted_at }
OrderPaymentChanged { kind: 'payment',  order_number, branch_id, created_at, accepted_at }
```

Hanya key-key di atas (test mengunci). Tidak ada `user_id` pelanggan, `phone`, `address`, `amount`, detail item.

## Setup env & konfigurasi

- `php artisan reverb:install` mempublikasikan `config/reverb.php`; env kunci: `REVERB_APP_ID`, `REVERB_KEY`, `REVERB_SECRET`, `REVERB_PORT=8080`, transport standar ws/wss.
- `.env.example` di-update dengan nilai **dev stabil** (dipilih/fixed untuk semua dev-fork — bukan secret produksi; dipisahkan eksplisit).
- `config/broadcasting.php`: driver `reverb` (di-publikasi reverb:install).

## Deployment notes (produksi, dikerjakan di item deploy — bukan sekarang)

1. `composer show centrifuge/laravel-reverb` menyediakan binari via `php artisan reverb:start` (paket bundel Go-binary, `vendor/bin/reverb` atau via composer platform note). Jalankan di supervisor: `php artisan reverb:start` (bukan `--dev`).
2. Nginx: block `location /app` (atau khusus `/ws`) → `proxy_pass http://127.0.0.1:8080` + header `Upgrade`/`Connection` + `Authorization` (Echo mengirim token Bearer di query — sudah dibantu reverb config di klien melalui `Echo` option).
3. HTTPS wss otomatis bila nginx terminasi TLS.
4. Uji smoke dev-→-prod: buka dapur, buat order dari POS, dengar bunyi; cabut proses Reverb secara sengaja → Echo reconnect + fetch ulang terjadi.

## Testing (ci)

Feature (PHP):

- `BroadcastOrderEventsTest`: order dibuat → `OrderCreated` ter-broadcast ke `kitchen.{b}` & `pos.{b}`; payload persis kontrak (set key); `accepted_at` null; transisi → `OrderStatusChanged` dengan from/to benar + `accepted_at` di-set; bayar → `OrderPaymentChanged`; cancel/void → status + payment event.
- `ChannelAuthorizationTest`: kitchen cabang A mengamati `kitchen.{bA}` (ya) dan `kitchen.{bB}`/`pos.{bB}` (tidak); kasir cabang A pada `pos.{bA}` (ya) dan `pos.{bB}` (tidak).
- Regression: test suite yang ada tetap hijau (event broadcast tidak mengubah perilaku transaksi; `phpunit.xml` **sudah** memuat `BROADCAST_CONNECTION=null` — driver tidak aktif di test-env, jadi test lain tak perlu handling ekstra; test broadcast memakai `Event::fake()`/`assertBroadcasted` pada event domain).
- Vitest: `isLate()` + `shouldRing(payload, seenOrderNumbers)` (bunyi hanya pada `kind:'payment'` & order_number belum terdengar; dedup kerja).

## Advertorial/ulur dokumen

- README: seksi "Realtime (Reverb)" + perintah dev (`reverb:start --dev`).
- AGENTS.md: ada di perintah dev.
- PRD §7.7: catatan satu baris "Diimplementasikan di rilis ini: …" (pola soal shift yang ada) — pada task terakhir.

## Urutan task (sketsa; detail di plan)

1. Reverb install: composer dep, `reverb:install`, env publik stabil, `BROADCAST_CONNECTION=null` di test-env, README/AGENTS.
2. Kolom `accepted_at` + set-once di `TransitionOrderStatus` + event domain 3 + emit di 5 titik + test broadcast & payload & PII.
3. Otorisasi channel (`routes/channels.php`, policy/klausul akses cabang) + test lintas-cabang.
4. UI dapur: Echo init + bunyi + timer + chip terlambat + mute + reconnect-refetch (+ Vitest helper).
5. UI POS: Echo init + auto-refresh pada event + reconnect-refetch; catatan PRD; Polish gate akhir.
