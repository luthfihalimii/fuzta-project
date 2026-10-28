# Realtime Kitchen + POS (Laravel Reverb) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Layar dapur dan POS menerima pesanan baru, perubahan status, dan perubahan pembayaran via WebSocket (Laravel Reverb) — bunyi di dapur saat pembayaran tuntas, timer + indikator terlambat per order, auto-refetch, reconnect-safe.

**Architecture:** Event = isyarat, data = fetch ulang. Tiap perubahan domain (create/pay/status/void) broadcast event privat kecil (tanpa PII) ke channel `kitchen.{branch_id}` / `pos.{branch_id}`; klien subscribes channel cabangnya, lalu memfetch ulang list yang sudah ada (dapur JSON endpoint, POS `router.reload({ only: ['orders'] })`). Dapur 5-detik polling tetap hidup sebagai fallback (notifikasi bukan single source of truth). Timer dihitung di klien dari `orders.accepted_at` (fallback `created_at`) vs ambang `KITCHEN_LATE_MINUTES`.

**Tech Stack:** Laravel 13, `laravel/reverb`, `laravel-echo` + `pusher-js`, Vue 3 + TypeScript (Inertia), Vitest, bun (package manager), PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-10-29-realtime-kitchen-pos-design.md` (sumber benar; baca dulu).

## Global Constraints

- `phpunit.xml` sudah memuat `BROADCAST_CONNECTION=null` — **jangan diubah**. 279 test existing harus tetap hijau tanpa Reverb server.
- Commit `.env.example` memakai nilai **dev-stable** (fixed di bawah, bukan secret produksi). `.env` lokal tidak pernah di-commit.
- Payload broadcast = **hanya** key sesuai kontrak di Task 2 (tes mengunci exact match). Dilarang key: `phone`, `customer_name`, `address`, `note`, `amount`, `total`, `items`, `placed_by_id`.
- Channel privat: `kitchen.{branch_id}`, `pos.{branch_id}` — nama presisi, tidak boleh beda di event kelas dan channel closure.
- Akses cabang = `User::hasBranchAccess()` (+ owner lintas-cabang); peran yang ada: `owner` / `cashier` / `kitchen` (tidak ada "manager").
- UI: string Bahasa Indonesia, desain netral mengikuti halaman yang ada, tanpa elemen brand baru.
- Per task: gerbang `CI=true composer ci:check` hijau sebelum commit. Frontend pakai `bun run …`.
- Dependensi baru **hanya**: `laravel/reverb` (composer), `laravel-echo` + `pusher-js` (bun). Tidak ada Redis/FCM/push di rilis ini.

## Review Focus

1. **PII/amount bocor lewat payload broadcast** → Task 2 mengunci payload dengan `assertSame` exact keys + `assertArrayNotHasKey('phone')` dkk.
2. **Langganan lintas-cabang** (kasir/dapur cabang A subscribe channel cabang B) → Task 3 matriks 200/403 pada endpoint `/broadcasting/auth`.
3. **Event ganda dari idempotent replay & paid-dua-kali** (lonceng berdering 2×) → Task 2: `assertBroadcasted(OrderCreated::class, null, 1)` untuk replay idempotent; `assertBroadcasted(OrderPaymentChanged::class, null, 1)` untuk paid-dua-kali.
4. **Reverb mati / env hilang di dev** → halaman dapur/POS harus tetap jalan via polling, tidak crash. Task 4: `realtimeEcho()` mengembalikan `null` tanpa key VITE; subscribe guarded; `bun run build` green tanpa VITE_ vars (sudah dikenali CI).
5. **Timer/late salah saat `accepted_at` null, tanggal invalid, or tepat di boundary** → Task 4 Vitest `isLate` cases; Task 2 tes `accepted_at` set-once.

---

### Task 1: Reverb install, env dev-stable, docs

**Files:**

- Modify: `composer.json` (require `laravel/reverb`), `bun.lock`
- Create: `config/reverb.php`, `config/broadcasting.php` (hasil `reverb:install`)
- Modify: `.env.example`, README.md, AGENTS.md
- Test: n/a (verifikasi lewat `ci:check` + boot app)

**Interfaces:**

- Consumes: none
- Produces: driver `reverb` tersedia di `config/broadcasting.php`; env lokal `BROADCAST_CONNECTION=reverb`; VITE_ vars untuk Task 4. Nilai dev-stable (sama di `.env.example` dan `.env`):
    - `REVERB_APP_ID=1`
    - `REVERB_KEY=ZnV6dGEtZGV2LWtleS0yMDI2LTAwMDAwMDAwMDAwMDE=`
    - `REVERB_SECRET=ZnV6dGEtZGV2LXNlY3JldC0yMDI2LTAwMDAwMDAwMDI=`
    - `REVERB_PORT=8080`, `REVERB_SCHEME=http`
    - `VITE_REVERB_APP_KEY=ZnV6dGEtZGV2LWtleS0yMDI2LTAwMDAwMDAwMDAwMDE=`
    - `VITE_REVERB_HOST=127.0.0.1`, `VITE_REVERB_PORT=8080`, `VITE_REVERB_SCHEME=ws`

- [ ] **Step 1: Tambah dependensi**

Run: `composer require laravel/reverb && bun add laravel-echo pusher-js`
Check: `composer.json` + `bun.lock` ter-update; `bun install` bersih.

- [ ] **Step 2: Publikasi konfigurasi Reverb**

Run: `php artisan reverb:install --no-interaction`
Check: `config/reverb.php` + `config/broadcasting.php` ada; koneksi `reverb` di broadcasting config menunjuk env `REVERB_*`.
Jalankan `git status`: jika `routes/broadcasting.php` ter-create, **hapus** (channel authorization milik Task 3, di `routes/channels.php`).

- [ ] **Step 3: Env dev-stable**

Di `.env.example`: set `BROADCAST_CONNECTION=reverb`, ganti/generate blok `REVERB_*` dengan 5 nilai di atas, tambah blok `VITE_REVERB_*` (4 nilai). Tambah 1 baris komentar di atas blok: `# Dev-stable values — sama untuk semua dev-fork; produksi harus generate baru (php artisan reverb:make:app)`.
Di `.env` lokal (tidak commit): sama persis nilainya + `BROADCAST_CONNECTION=reverb`.
Check: `git diff .env.example` hanya menambah/mengubah variabel di atas.

- [ ] **Step 4: Test env tetap terisolasi**

Check: `grep BROADCAST_CONNECTION phpunit.xml` menampilkan `value="null"` — jangan diubah.

- [ ] **Step 5: Docs**

README.md: seksi baru "**Realtime (Reverb)**" (setelah "Tests and checks"): 2 baris — dev butuh proses kedua `php artisan reverb:start --debug`; deploy produksi = checklist di spec (satu link ke path spec). AGENTS.md: tambah `php artisan reverb:start --debug  # proses kedua (realtime dev)` ke blok perintah Web UI.

- [ ] **Step 6: Gerbang**

Run: `CI=true composer ci:check`
Expected: green (phpunit 279/279, build, pint).

- [ ] **Step 7: Commit (bawa juga dokumen feature)**

```bash
git add composer.json composer.lock bun.lock config/ .env.example README.md AGENTS.md \
  docs/superpowers/specs/2026-10-29-realtime-kitchen-pos-design.md \
  docs/superpowers/plans/2026-10-29-realtime-kitchen-pos.md
git commit -m "feat: scaffold laravel-reverb broadcasting (config, dev env, docs)"
```

---

### Task 2: `accepted_at` + 3 event domain + emit di 5 titik

**Files:**

- Create: `database/migrations/2026_10_29_000001_add_accepted_at_to_orders_table.php`
- Create: `app/Modules/Ordering/Events/OrderCreated.php`, `OrderStatusChanged.php`, `OrderPaymentChanged.php`
- Create: `tests/Feature/BroadcastOrderEventsTest.php`
- Modify: `app/Modules/Ordering/Models/Order.php` (casts + fillable `accepted_at`)
- Modify: `app/Modules/Kitchen/Actions/TransitionOrderStatus.php` (set-once `accepted_at` + emit)
- Modify: `app/Modules/Ordering/Actions/CreateOrder.php` (emit; **jangan** pada jalur idempotent-existing)
- Modify: `app/Modules/Payments/Actions/MarkOrderPaid.php` (emit; jalur baru-payment saja)
- Modify: `app/Modules/Ordering/Actions/CancelOrder.php` (emit status)
- Modify: `app/Modules/Ordering/Actions/VoidOrder.php` (emit status + payment)
- Modify: `app/Modules/Ordering/Support/OrderPayload.php` (tambah key `accepted_at`)
- Modify: `resources/js/lib/order.ts` (tambah `accepted_at: string | null` ke `PosOrder`)

**Interfaces:**

- Consumes: Task 1 (driver `reverb` ada; test driver `null`).
- Produces (nama eksak — dipakai Task 3–5):
    - `App\Modules\Ordering\Events\OrderCreated` — ctor `public readonly Order $order`; `broadcastWith()` = `{ kind: 'created', order_number: string, branch_id: int, created_at: string|null (ISO-8601), accepted_at: string|null }`.
    - `App\Modules\Ordering\Events\OrderStatusChanged` — ctor `public readonly Order $order, public readonly OrderStatus $from, public readonly OrderStatus $to`; `broadcastWith()` = `{ kind: 'status', order_number, branch_id, from: $from->value, to: $to->value, created_at, accepted_at }`.
    - `App\Modules\Ordering\Events\OrderPaymentChanged` — ctor `public readonly Order $order`; `broadcastWith()` = `{ kind: 'payment', order_number, branch_id, created_at, accepted_at }`.
    - Semuanya `implements ShouldBroadcast` (bukan `ShouldBroadcastNow` — `BroadcastEvent` `ShouldDispatchAfterCommit`, jadi emit di dalam DB::transaction aman) + `broadcastOn()` = `[new PrivateChannel('kitchen.'.$order->branch_id), new PrivateChannel('pos.'.$order->branch_id)]`.
    - **`broadcastAs()` wajib ada** (default Laravel = FQCN class — jangan andalkan itu): `OrderCreated` → `'order-created'`, `OrderStatusChanged` → `'order-status-changed'`, `OrderPaymentChanged` → `'order-payment-changed'`. Ini nama event **wire** (persis apa yang dikirim server). Klien **wajib** listen dengan prefix titik: `.listen('.order-created')` — laravel-echo default `namespace: 'App.Events'` akan mem-prefix nama polos menjadi `App\Events\order-created` (tak pernah match); prefix titik berarti "tanpa namespace" (terverifikasi dari source `laravel-echo` terpasang).
    - Emit via helper `event(new …)` di dalam transaction yang sudah ada.
    - JSON kitchen/POS (`OrderPayload`) kini memuat `accepted_at` (ISO-8601, nullable).

- [ ] **Step 1: Migrasi `accepted_at` + model**

Migration: `Schema::table('orders', fn (Blueprint $table) => $table->timestamp('accepted_at')->nullable()->after('payment_status'));` (down: drop). Model `Order`: tambah `'accepted_at'` ke `Fillable` + `'accepted_at' => 'datetime'` ke `casts()`.
Run: `php artisan migrate`

- [ ] **Step 2: Tulis test gagal — `tests/Feature/BroadcastOrderEventsTest.php`**

Follow setup house (pola `tests/Feature/OrderCreationTest.php` + `tests/Feature/KitchenTest.php` untuk shift/paid order; 2 cabang `branchA`/`branchB`, cashier & kitchen per cabang). **Erratum:** Laravel 13 tidak punya `Broadcast::fake()` — semua test pakai `Event::fake([OrderCreated::class, OrderStatusChanged::class, OrderPaymentChanged::class])` + `Event::assertDispatched*` atas objek event (pin `broadcastWith()`/`broadcastOn()`/`broadcastAs()`/count); `$this->assertInstanceOf(ShouldBroadcast::class, …)` mem-pin interface broadcast. 7 test:

1. `order_creation_broadcasts_to_both_branch_channels_with_locked_payload` — buat order via `app(CreateOrder::class)`; `Broadcast::assertBroadcasted(OrderCreated::class, fn (OrderCreated $e) => …)` dengan:
    - `assertSame(['kind' => 'created', 'order_number' => $order->order_number, 'branch_id' => $order->branch_id, 'created_at' => $order->created_at->toIso8601String(), 'accepted_at' => null], $e->broadcastWith())` — **exact match = kunci PII** (semua key ekstra otomatis gagal).
    - `assertSame([new PrivateChannel('kitchen.'.$branchA->id), new PrivateChannel('pos.'.$branchA->id)], $e->broadcastOn())`.
2. `idempotent_replay_does_not_broadcast_again` — create 2× dengan `idempotency_key` sama (return order yang sama); `Broadcast::assertBroadcasted(OrderCreated::class, null, 1)`.
3. `marking_paid_broadcasts_order_payment_changed` — paid via `MarkOrderPaid`; `assertBroadcasted(OrderPaymentChanged::class)` payload exact `{kind:'payment', order_number, branch_id, created_at, accepted_at:null}`; `assertNotBroadcasted(OrderCreated::class)`.
4. `marking_paid_twice_does_not_rebroadcast` — panggil `MarkOrderPaid` 2×; `assertBroadcasted(OrderPaymentChanged::class, null, 1)`.
5. `transition_to_accepted_sets_accepted_at_once_and_broadcasts_status` — paid; `TransitionOrderStatus` queued→accepted: `assertBroadcasted(OrderStatusChanged)` dari→`queued`/`accepted`; grab `$t1 = $order->fresh()->accepted_at` (bukan null); transisi accepted→preparing; `assertBroadcasted` dari→`accepted`/`preparing`; `$t2 = $order->fresh()->accepted_at`; `assertSame($t1, $t2)`.
6. `cancel_unpaid_order_broadcasts_status_only` — cancel via `CancelOrder`; `assertBroadcasted(OrderStatusChanged::class)` with `to === 'cancelled'`; `Broadcast::assertNotBroadcasted(OrderPaymentChanged::class)`.
7. `void_paid_order_broadcasts_status_and_payment` — paid + transisi ke accepted; `VoidOrder`; keduanya `assertBroadcasted` (status event `to: 'cancelled'`, plus payment event).

Run: `php artisan test --filter=BroadcastOrderEventsTest`
Expected: FAIL (class event belum ada / belum di-emit).

- [ ] **Step 3: Implement event classes**

Tiga class di `app/Modules/Ordering/Events/` sesuai kontrak Interfaces di atas. `broadcastWith()` exact key set (lihat kontrak). `use Dispatchable, InteractsWithSockets, SerializesModels;`.

- [ ] **Step 4: Emit di 5 titik + set-once `accepted_at`**

- `TransitionOrderStatus`: sebelum `$locked->save()`, `if ($next === OrderStatus::Accepted && $locked->accepted_at === null) { $locked->accepted_at = now(); }`; setelah `OrderStatusEvent::create(…)`: `event(new OrderStatusChanged($locked, $from, $next));`
- `CreateOrder`: di jalur buat-baru (setelah `Order::create` + items, sebelum `return`), `event(new OrderCreated($order));` — **tidak** di jalur `findExisting` yang return existing.
- `MarkOrderPaid`: di jalur baru-payment (sebelum `return $payment`), `event(new OrderPaymentChanged($locked));` — jalur early-return existing payment tidak emit.
- `CancelOrder`: akhir transaction, `event(new OrderStatusChanged($locked, $from, OrderStatus::Cancelled));`
- `VoidOrder`: akhir transaction, `event(new OrderStatusChanged($locked, $from, OrderStatus::Cancelled));` (guard `if ($from !== OrderStatus::Cancelled)`) + `event(new OrderPaymentChanged($locked));`
- `OrderPayload`: tambah `'accepted_at' => $order->accepted_at?->toIso8601String(),` setelah `created_at`.
- `resources/js/lib/order.ts`: `PosOrder` tambah `accepted_at: string | null;` (setelah `created_at`).

- [ ] **Step 5: Tes hijau**

Run: `php artisan test --filter=BroadcastOrderEventsTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Gerbang penuh**

Run: `CI=true composer ci:check`
Expected: green (suites existing tetap hijau — driver null dia; vitest hijau).

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: broadcast order events with accepted_at timestamp"
```

---

### Task 3: Otorisasi channel per-cabang

**Files:**

- Create: `routes/channels.php`
- Modify: `bootstrap/app.php` (`withRouting` tambah param `channels`)
- Create: `tests/Feature/ChannelAuthorizationTest.php`

**Interfaces:**

- Consumes: nama channel Task 2 (`kitchen.*` / `pos.*`), `User::hasBranchAccess()`, `Role`.
- Produces: endpoint `POST /broadcasting/auth` aktif; closure otorisasi:
    - `kitchen.{branch_id}` → `role` ∈ {`kitchen`, `owner`} **dan** `hasBranchAccess((int)$branchId)`
    - `pos.{branch_id}` → `role` ∈ {`cashier`, `owner`} **dan** `hasBranchAccess((int)$branchId)`

- [ ] **Step 1: Tulis test gagal — `tests/Feature/ChannelAuthorizationTest.php`**

Helper: `postJson('/broadcasting/auth', ['channel_name' => 'private-'.$channel, 'socket_id' => '1.2'])` via `actingAs($user)`. Matriks (branchA/branchB, user per cabang, owner):

- kitchen A → `kitchen.{branchA}` **200** (JSON `auth` string ada); → `kitchen.{branchB}` **403**; → `pos.{branchA}` **403**.
- cashier A → `pos.{branchA}` **200**; → `pos.{branchB}` **403**; → `kitchen.{branchA}` **403**.
- owner → `kitchen.{branchB}` **200** dan `pos.{branchA}` **200**.
- guest (tanpa actingAs) → `private-kitchen.{branchA}` **403**.

Run: `php artisan test --filter=ChannelAuthorizationTest`
Expected: FAIL (route 404 — route belum ada).

- [ ] **Step 2: `routes/channels.php`**

```php
<?php

use App\Modules\Identity\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('kitchen.{branch_id}', function (Request $request, string $branchId): bool {
    $user = $request->user();

    return $user !== null
        && in_array($user->role(), [Role::Kitchen, Role::Owner], true)
        && $user->hasBranchAccess((int) $branchId);
});

Broadcast::channel('pos.{branch_id}', function (Request $request, string $branchId): bool {
    $user = $request->user();

    return $user !== null
        && in_array($user->role(), [Role::Cashier, Role::Owner], true)
        && $user->hasBranchAccess((int) $branchId);
});
```

- [ ] **Step 3: Aktifkan di `bootstrap/app.php`**

Tambah ke `->withRouting(…)`:

```php
channels: __DIR__.'/../routes/channels.php',
```

(Laravel 13 `withBroadcasting` otomatis memanggil `Broadcast::routes()` middleware `web` — rute `/broadcasting/auth` teregister; tanpa CSRF, sesuai desain framework.)

- [ ] **Step 4: Tes hijau**

Run: `php artisan test --filter=ChannelAuthorizationTest`
Expected: PASS (9 asert).

- [ ] **Step 5: Gerbang + commit**

Run: `CI=true composer ci:check` (green), lalu:

```bash
git add routes/channels.php bootstrap/app.php tests/Feature/ChannelAuthorizationTest.php
git commit -m "feat: per-branch private channel authorization"
```

---

### Task 4: UI dapur — bunyi, timer, chip terlambat, mute, reconnect

**Files:**

- Create: `resources/js/bootstrap/echo.ts`
- Create: `resources/js/lib/realtime.ts`, `resources/js/lib/sound.ts`
- Create: `tests/js/realtime.test.ts`
- Create: `config/kitchen.php`
- Modify: `app/Modules/Kitchen/Http/Controllers/KitchenController.php` (prop `kitchenLateMinutes`)
- Modify: `resources/js/pages/kitchen/Index.vue`

**Interfaces:**

- Consumes: Task 1 (VITE_ vars; tanpa var = graceful fallback), Task 2 (payload contract, event name via `broadcastAs`: `order-created`, `order-status-changed`, `order-payment-changed`; key `accepted_at` di JSON `/kitchen/orders`), Task 3 (channel `kitchen.{id}`; user dapur = kitchen|owner).
- Produces (dipakai Task 5):
    - `resources/js/bootstrap/echo.ts` → `export function realtimeEcho(): Echo | null` (singleton; `null` bila `VITE_REVERB_APP_KEY` tak ada; build `window.Pusher` + Echo `broadcaster: 'reverb'`).
    - `resources/js/lib/realtime.ts` → `type RealtimeNotification` (mirror payload PHP: `kind: 'created'|'status'|'payment'`, `order_number: string`, `branch_id: number`, `created_at: string | null`, `accepted_at: string | null`, `from?: string`, `to?: string`); `isLate(startAt: string | null, now: number, minutes: number): boolean`; `shouldRing(n: RealtimeNotification, seen: ReadonlySet<string>): boolean`.
    - `resources/js/lib/sound.ts` → `playBeep(): void`, `unlockAudio(): void` (WebAudio-sintesis; tanpa aset).

**Kontrak helper (dipin spec + Vitest):**

```ts
export function isLate(
    startAt: string | null,
    now: number,
    minutes: number,
): boolean {
    if (startAt === null) return false;
    const startMs = new Date(startAt).getTime();
    return !Number.isNaN(startMs) && now - startMs > minutes * 60_000;
}

export function shouldRing(
    n: RealtimeNotification,
    seen: ReadonlySet<string>,
): boolean {
    return (
        n.kind === 'payment' &&
        n.order_number !== '' &&
        !seen.has(n.order_number)
    );
}
```

`echo.ts` (buat persis satu ini):

```ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo: Echo | null = null;

export function realtimeEcho(): Echo | null {
    if (echo) {
        return echo;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

    if (!key) {
        return null;
    }

    (window as unknown as { Pusher: unknown }).Pusher = Pusher;

    echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost:
            (import.meta.env.VITE_REVERB_HOST as string | undefined) ??
            '127.0.0.1',
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'ws') === 'wss',
        enabledTransports: ['ws', 'wss'],
        auth: {
            headers: {
                'X-CSRF-TOKEN':
                    document.querySelector('meta[name="csrf-token"]')
                        ?.content ?? '',
            },
        },
    });

    return echo;
}
```

- [ ] **Step 1: Vitest — `tests/js/realtime.test.ts` (gagal dulu)**

- `isLate(null, X, 15)` → `false`; `isLate('<invalid>', X, 15)` → `false`; start 16 menit lalu vs 15 → `true`; 14 menit → `false`; **tepat 15 menit** → `false` (strict `>`).
- `shouldRing({kind:'payment', …belum di-seen})` → `true`; `…sudah di-seen) → false`; `kind:'created'` (belum-seen) → `false`; `kind:'status'` → `false`; `order_number:''` → `false`.

Run: `bun run test:js -- tests/js/realtime.test.ts`
Expected: FAIL (module belum ada).

- [ ] **Step 2: `resources/js/lib/realtime.ts`** — tulis per kontrak di atas (+ export `RealtimeNotification`). Tes hijau.

- [ ] **Step 3: `resources/js/bootstrap/echo.ts` + `resources/js/lib/sound.ts`**

`echo.ts` per kontrak. `sound.ts`: singleton `AudioContext` (lazy, `typeof AudioContext !== 'undefined'` guard); `playBeep()` = oscillator sine 880 Hz, gain envelope atik 0.02 s → 0.35, decay ke 0.001 dalam 0.25 s, stop 0.28 s; kalau `audio.state === 'suspended'` → skip (autoplay policy; `unlockAudio()` memanggil `context.resume()`).

- [ ] **Step 4: Konfigurasi ambang + prop**

`config/kitchen.php`:

```php
<?php

return [
    'late_minutes' => (int) env('KITCHEN_LATE_MINUTES', 15),
];
```

`KitchenController::index()`: tambah ke Inertia props: `'kitchenLateMinutes' => (int) config('kitchen.late_minutes', 15),`.
`.env.example`: tambah `KITCHEN_LATE_MINUTES=15` (blok dev).

- [ ] **Step 5: Kaitkan `resources/js/pages/kitchen/Index.vue`**

1. Props: tambah `kitchenLateMinutes: number`.
2. Import: `realtimeEcho`, `isLate`, `shouldRing`, `RealtimeNotification`, `playBeep`, `unlockAudio`.
3. State: `soundOn = ref(sessionStorage.getItem('pf_kitchen_muted') !== '1')`; `seen = new Set<string>(sessionStorage.getItem('pf_kitchen_seen')?.split(',').filter(Boolean) ?? [])`; `now = ref(Date.now())`.
4. `onRealtime(payload: RealtimeNotification)`: skip bila `payload.branch_id !== props.branch?.id`; `if (shouldRing(payload, seen)) { seen.add(payload.order_number); persist; if (soundOn.value && document.visibilityState === 'visible') playBeep(); }`; `void loadOrders()`.
5. `connectRealtime()`: `const echo = realtimeEcho()`; kalau `null` atau `!props.branch` → return (polling 5-detik tetap jalan); `channel = echo.private('kitchen.' + props.branch.id)`; 3× `.listen('.order-created' | '.order-status-changed' | '.order-payment-changed', onRealtime)` (**prefix titik wajib** — lihat erratum Task 2, Interfaces); `echo.connection.on('reconnected', () => void loadOrders())`.
6. `onMounted`: + `connectRealtime()` + `nowTimer = window.setInterval(() => { now.value = Date.now(); }, 30_000)`. `onUnmounted`: `channel?.disconnect()`, `connection.off`, CLEAR kedua interval.
7. Tombol mute di header (dekat link Stok): `type="button"`, `aria-pressed="soundOn"`, label teks "Suara" / dengan ikon SVG lonceng sederhana; toggle `soundOn` + persist `pf_kitchen_muted`; saat ON → `unlockAudio()`.
8. Card order: ganti `formatElapsed(order.created_at)` → `formatElapsed(order.accepted_at ?? order.created_at)`; tambah chip `v-if="activeStatus !== 'completed' && isLate(order.accepted_at ?? order.created_at, now, kitchenLateMinutes)"` → `<span role="status" class="…bg-danger…">Terlambat</span>` di blok status card (style ikuti `statusTone` yang ada, tanpa brand baru).

- [ ] **Step 6: Gerbang**

Run: `CI=true composer ci:check`
Expected: green — **termasuk** `bun run build` tanpa `VITE_REVERB_*` di env (guard `!key → null`).

- [ ] **Step 7: Smoke dev manual (bukan CI; catat di PR-nya)**

Terminal 1 `php artisan reverb:start --debug`, terminal 2 app. POS: buat order → bayar → pastikan: layar dapur update < 3 detik + bunyi (setelah klik pertama/mute-off); tombol mute membisukan; kill Reverb → polling 5-detik tetap berfungsi; buka dapur cabang B (owner) → tidak mendengar/mendapat event cabang A.

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat: kitchen realtime - sound, timer, late indicator, mute"
```

---

### Task 5: UI POS auto-refresh + catatan PRD + gerbang akhir

**Files:**

- Modify: `resources/js/pages/pos/Orders.vue`
- Modify: `PRD.md` (§7.7, 1 baris catatan pola "Diimplementasikan…")

**Interfaces:**

- Consumes: `realtimeEcho()` (Task 4), channel `pos.{branch.id}` (Task 3), event name Task 2, idiom `router.reload({ only: ['orders'] })` (sudah di halaman ini).
- Produces: list POS auto-refresh tanpa bunyi; halaman tetap berfungsi tanpa Reverb.

- [ ] **Step 1: Kaitkan `resources/js/pages/pos/Orders.vue`**

1. Import `realtimeEcho`.
2. `onMounted`: `const echo = realtimeEcho(); const branch = props.branch;` kalau `null` → return; `channel = echo.private('pos.' + branch.id)`; 3× listener (`.listen('.order-created'`, `.listen('.order-status-changed'`, `.listen('.order-payment-changed')` — **prefix titik wajib**) → `refresh()`.
3. `refresh()`: coalesce sederhana — `let pending = false; function refresh() { if (pending) return; pending = true; setTimeout(() => { pending = false; router.reload({ only: ['orders'], preserveScroll: true }); }, 50); }` (burst event jangan menumpuk reload).
4. `reconnected` handler → `refresh()`. `onUnmounted`: `channel?.disconnect()` + off listener.
5. Ganti branch (`switchBranch`) memakai `preserveState: false` → komponen remount → subscribe ulang otomatis (cek: tidak ada double-subscribe saat remount).

- [ ] **Step 2: Catatan PRD**

`PRD.md` §7.7 (alarm & indicator waktu): tambah 1 baris pola rilis terdahulu — "Diimplementasikan (realtime, rilis 2026-10-29): broadcast Reverb ke dapur + POS; bunyi saat pembayaran tuntas; ambang `KITCHEN_LATE_MINUTES` (default 15 menit dari diterima); indikator terlambat + timer per order; reconnect → refetch. Detail: `docs/superpowers/specs/2026-10-29-realtime-kitchen-pos-design.md`."

- [ ] **Step 3: Gerbang akhir**

Run: `CI=true composer ci:check`
Expected: green penuh (phpunit + vitest + build + pint).

- [ ] **Step 4: Commit + push**

```bash
git add -A && git commit -m "feat: pos realtime refresh; note PRD 7.7"
git push origin main
```

- [ ] **Step 5: Verifikasi CI**

Pantau run GitHub (`gh run watch`) sampai hijau di `main`; lapor owner (Bahasa Indonesia, efek bukan mekanisme) + tanyakan fitur berikutnya.
