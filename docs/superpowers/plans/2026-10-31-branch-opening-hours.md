# Jam Operasional Cabang Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cabang punya jam operasional yang bisa diedit owner (default 09:00–21:00), status Buka/Tutup dihitung di klien dari jam itu, dan terlihat di header POS + dapur plus satu flash lembut "di luar jam" di POS (tanpa memblokir transaksi).

**Architecture:** Kolom `branches.opens_at`/`closes_at` string `H:i` NOT NULL (default `09:00`/`21:00`). Validasi server = satu titik di `BranchController` (format `H:i` + `closes_at` harus setelah `opens_at`, dibandingkan terhadap nilai efektif: request kalau dikirim, DB/default kalau tidak). Status Buka/Tutup dihitung **klien** oleh satu helper `resources/js/lib/opening-hours.ts` (`isOpenNow`) + tick 60 s di POS (dapur pakai `now` 1 s yang sudah ada), karena halaman POS/kitchen tidak me-reload props.

**Tech Stack:** Laravel (migrasi + validasi + Inertia props), Vue 3 + TypeScript, vitest.

**Spec:** `docs/superpowers/specs/2026-10-31-branch-opening-hours-design.md`

## Global Constraints

- Jam = string `H:i` (5 digit, 24 jam, zero-padded). Bandingkan leksikografis (`'21:00' > '09:00'` aman).
- Batas: buka inklusif (tepat `opens_at` = `Buka`), tutup eksklusif (tepat `closes_at` = `Tutup`).
- `closes_at` **setelah** `opens_at`; `closes_at <= opens_at` → 422 di `closes_at`. Dibandingkan terhadap nilai **efektif** (field antrean bila dikirim, selain itu DB/default).
- Default: `opens_at`/`closes_at` NOT NULL, DB default `'09:00'`/`'21:00'` — branch yang sudah ada langsung dapat nilai saat migrasi.
- Edit hanya owner (`BranchPolicy` sudah `isOwner` — jangan diutak-atik); audit `branch.updated` before/after otomatis ikut jam karena `snapshot()`.
- Di luar jam: **warning lembut saja** di POS; transaksi TIDAK di-block. Cabang nonaktif tetap menampilkan `Tutup` (banner nonaktif yang sudah ada tidak berubah).
- Jam device staff = jam cabang (kedua branch satu zona Asia/Jakarta).
- Non-goal (jangan dibuat): jam per hari minggu, penutupan sementara, block order, jam lintas tengah malam, situs publik.
- Tes: `php artisan test` + `$HOME/.bun/bin/bun run test:js` hijau sebelum commit; jangan melemahkan tes yang gagal.

## Review Focus

1. Tepat `closes_at` (21:00) → status `Tutup` (batas eksklusif) — orang melihat jam dinding tepat ketika tutup. → `tests/js/opening-hours.test.ts` (Task 2).
2. Tepat `opens_at` (09:00) → status `Buka` (batas inklusif) — pelanggan pertama tepat 09:00. → `tests/js/opening-hours.test.ts` (Task 2).
3. Jam terbalik (`closes_at <= opens_at` — server melarang, tapi row lama/edit DB manual mungkin) → helper `false`, tidak crash. → `tests/js/opening-hours.test.ts` (Task 2).
4. Owner mengirim hanya satu field (mis. `closes_at=08:00` saat DB `opens_at=09:00`) → 422, bukan lolos karena field lain tak dikirim. → `tests/Feature/BranchTest.php` (Task 1).
5. POS dibiarkan terbuka lewat jam tutup → status ikut jadi `Tutup` tanpa reload (tick 60 s; logika diliput helper vitest, tick mengikuti pola polling yang sudah ada di page). → Task 2 Step 6 (verifikasi kode/pola, bukan tes).

---

### Task 1: Schema + validasi + edit admin

**Files:**
- Create: `database/migrations/2026_10_31_000002_add_opening_hours_to_branches_table.php`
- Modify: `app/Modules/Branches/Models/Branch.php`
- Modify: `app/Modules/Branches/Http/Controllers/BranchController.php`
- Modify: `resources/js/pages/admin/branches/Index.vue`
- Test: `tests/Feature/BranchTest.php` (create)

**Interfaces:**
- Produces: kolom `branches.opens_at`/`closes_at` (`H:i`, NOT NULL, default); `snapshot()` + payload index memuat keduanya — dipakai `BranchPresenter`/UI di Task 2.

- [ ] **Step 1: Tulis tes yang gagal** — `tests/Feature/BranchTest.php` baru (pola `RefreshDatabase`, factory `User::owner()`/`cashier()` dari tes lain):

```php
public function test_new_branch_gets_default_opening_hours(): void
{
    $branch = Branch::factory()->create();

    $this->assertSame('09:00', $branch->fresh()->opens_at);
    $this->assertSame('21:00', $branch->fresh()->closes_at);
}

public function test_owner_can_store_branch_with_opening_hours(): void
{
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->post('/admin/branches', [
        'name' => 'Cabang Timur',
        'code' => 'PF-03',
        'opens_at' => '08:00',
        'closes_at' => '22:00',
    ])->assertRedirect();

    $this->assertDatabaseHas('branches', [
        'code' => 'PF-03',
        'opens_at' => '08:00',
        'closes_at' => '22:00',
    ]);
}

public function test_store_rejects_invalid_time_format(): void
{
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->post('/admin/branches', [
        'name' => 'Cabang', 'code' => 'PF-03', 'opens_at' => '25:00',
    ])->assertStatus(422)->assertJsonValidationErrors('opens_at');

    $this->actingAs($owner)->post('/admin/branches', [
        'name' => 'Cabang', 'code' => 'PF-03', 'closes_at' => '0900',
    ])->assertStatus(422)->assertJsonValidationErrors('closes_at');

    $this->assertDatabaseCount('branches', 0);
}

public function test_store_rejects_closes_not_after_opens(): void
{
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->post('/admin/branches', [
        'name' => 'Cabang', 'code' => 'PF-03',
        'opens_at' => '21:00', 'closes_at' => '09:00',
    ])->assertStatus(422)->assertJsonValidationErrors('closes_at');

    $this->assertDatabaseCount('branches', 0);
}

public function test_update_rejects_inverted_hours_against_db_value(): void
{
    $owner = User::factory()->owner()->create();
    $branch = Branch::factory()->create(); // 09:00–21:00

    // satu field saja: bandingkan terhadap nilai DB yang tidak dikirim
    $this->actingAs($owner)->patch("/admin/branches/{$branch->id}", [
        'closes_at' => '08:00',
    ])->assertStatus(422)->assertJsonValidationErrors('closes_at');

    $this->actingAs($owner)->patch("/admin/branches/{$branch->id}", [
        'opens_at' => '22:00',
    ])->assertStatus(422)->assertJsonValidationErrors('closes_at');

    $branch->refresh();
    $this->assertSame('09:00', $branch->opens_at);
    $this->assertSame('21:00', $branch->closes_at);
}

public function test_cashier_cannot_update_branch_hours(): void
{
    $cashier = User::factory()->cashier()->create();
    $branch = Branch::factory()->create();

    $this->actingAs($cashier)->patch("/admin/branches/{$branch->id}", [
        'closes_at' => '23:00',
    ])->assertForbidden();
}

public function test_update_audit_includes_hours_before_after(): void
{
    $owner = User::factory()->owner()->create();
    $branch = Branch::factory()->create();

    $this->actingAs($owner)->patch("/admin/branches/{$branch->id}", [
        'closes_at' => '22:00',
    ])->assertRedirect();

    $log = AuditLog::query()->where('action', 'branch.updated')->latest('id')->firstOrFail();

    $this->assertSame('21:00', $log->before['closes_at']);
    $this->assertSame('22:00', $log->after['closes_at']);
}

public function test_branches_page_sends_opening_hours(): void
{
    $owner = User::factory()->owner()->create();
    $branch = Branch::factory()->create();

    $this->actingAs($owner)->get('/admin/branches')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/branches/Index')
            ->where('branches.0.opens_at', '09:00')
            ->where('branches.0.closes_at', '21:00')
        );
}
```

(import `AuditLog` dari `App\Modules\Audit\Models\AuditLog` — namespace sama yang dipakai `WriteAuditLog`; kolom `before`/`after` tersimpan sebagai JSON.)

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter BranchTest`
Expected: FAIL — kolom belum ada / aturan validasi belum ada.

- [ ] **Step 3: Implementasi**

**Migrasi** `2026_10_31_000002_add_opening_hours_to_branches_table.php` (pola migrasi existing, `down()` dropColumn kedua kolom):

```php
Schema::table('branches', function (Blueprint $table) {
    $table->string('opens_at', 5)->default('09:00');
    $table->string('closes_at', 5)->default('21:00');
});
```

**`Branch` model** — `#[Fillable]`: tambah `'opens_at'`, `'closes_at'`.

**`BranchController`**:
- `index()` mapping: tambah `'opens_at' => $branch->opens_at,` + `'closes_at' => $branch->closes_at,`.
- `snapshot()`: tambah dua key yang sama.
- `store()` rules: tambah `'opens_at' => ['sometimes', 'date_format:H:i'],` + `'closes_at' => ['sometimes', 'date_format:H:i'],`. Setelah `$data = $request->validate([...])` dan sebelum `DB::transaction`, cek nilai efektif (default DB = `'09:00'`/`'21:00'`):

```php
if (($data['closes_at'] ?? '21:00') <= ($data['opens_at'] ?? '09:00')) {
    throw ValidationException::withMessages([
        'closes_at' => 'Jam tutup harus setelah jam buka.',
    ]);
}
```

(import `Illuminate\Validation\ValidationException`.)
- `update()` rules: dua aturan `['sometimes', 'date_format:H:i']` yang sama (setelah baris `'is_active'`). Setelah `$reason = ...`/`unset($data['reason']);` dan sebelum `DB::transaction`, cek terhadap DB:

```php
if (($data['closes_at'] ?? $branch->closes_at) <= ($data['opens_at'] ?? $branch->opens_at)) {
    throw ValidationException::withMessages([
        'closes_at' => 'Jam tutup harus setelah jam buka.',
    ]);
}
```

- `resources/js/pages/admin/branches/Index.vue`:
  - `BranchRow`: tambah `opens_at: string;` + `closes_at: string;`.
  - `useForm({...})`: tambah `opens_at: '09:00',` + `closes_at: '21:00',`.
  - `startEdit()`: `form.opens_at = branch.opens_at;` + `form.closes_at = branch.closes_at;`.
  - Form: dua `<Input>` `type="time"` (pola `<Input type="number">` yang sudah ada) label "Jam buka"/"Jam tutup", `:error="form.errors.opens_at"` / `:error="form.errors.closes_at"`.
  - Tabel: kolom baru `Jam` (setelah `Telepon`): `{{ branch.opens_at }}–{{ branch.closes_at }}` (+ `<th>` koresponden).

- [ ] **Step 4: Jalankan, pastikan lulus**

Run: `php artisan test --filter BranchTest`
Expected: PASS (8/8).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_10_31_000002_add_opening_hours_to_branches_table.php app/Modules/Branches/Models/Branch.php app/Modules/Branches/Http/Controllers/BranchController.php resources/js/pages/admin/branches/Index.vue tests/Feature/BranchTest.php
git commit -m "feat(branches): opening hours (validasi, edit admin, audit)"
```

---

### Task 2: Presenter + `isOpenNow` + chip POS/dapur + flash lembut

**Files:**
- Modify: `app/Modules/Branches/Support/BranchPresenter.php`
- Modify: `resources/js/lib/order.ts` (type `BranchSummary`)
- Create: `resources/js/lib/opening-hours.ts`
- Create: `tests/js/opening-hours.test.ts`
- Modify: `resources/js/pages/pos/Index.vue`
- Modify: `resources/js/pages/kitchen/Index.vue`
- Modify: `tests/Feature/OrderCreationTest.php`, `tests/Feature/KitchenTest.php`
- Modify: `PRD.md`

**Interfaces:**
- Consumes: Task 1 — payload index/branch sudah memuat `opens_at`/`closes_at`; `Branch::opens_at`/`closes_at`.
- Produces: `isOpenNow(opensAt: string, closesAt: string, date: Date = new Date()): boolean` — satu-satunya logika status Buka/Tutup di klien; prop `branch.opens_at`/`branch.closes_at` di page POS + dapur.

- [ ] **Step 1: Tulis tes yang gagal (helper + props)** — `tests/js/opening-hours.test.ts` (pola `tests/js/date.test.ts`; kode leksikografis `H:i`):

```ts
import { describe, expect, it } from 'vitest';
import { isOpenNow } from '../../resources/js/lib/opening-hours';

function at(hhmm: string): Date {
    return new Date(2026, 9, 31, Number(hhmm.slice(0, 2)), Number(hhmm.slice(3, 5)));
}

describe('isOpenNow', () => {
    it('is false before opening', () => {
        expect(isOpenNow('09:00', '21:00', at('08:59'))).toBe(false);
    });

    it('is true exactly at opening (inclusive)', () => {
        expect(isOpenNow('09:00', '21:00', at('09:00'))).toBe(true);
    });

    it('is true mid-range', () => {
        expect(isOpenNow('09:00', '21:00', at('14:30'))).toBe(true);
    });

    it('is false exactly at closing (exclusive)', () => {
        expect(isOpenNow('09:00', '21:00', at('21:00'))).toBe(false);
    });

    it('is false after closing', () => {
        expect(isOpenNow('09:00', '21:00', at('23:15'))).toBe(false);
    });

    it('is false when the range is inverted', () => {
        expect(isOpenNow('21:00', '09:00', at('12:00'))).toBe(false);
    });
});
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `$HOME/.bun/bin/bun run test:js -- opening-hours`
Expected: FAIL — modul belum ada.

- [ ] **Step 3: Implementasi helper**

**`resources/js/lib/opening-hours.ts`**:

```ts
export function isOpenNow(opensAt: string, closesAt: string, date: Date = new Date()): boolean {
    const pad = (n: number): string => String(n).padStart(2, '0');
    const now = `${pad(date.getHours())}:${pad(date.getMinutes())}`;

    return closesAt > opensAt && now >= opensAt && now < closesAt;
}
```

(Jam device = jam cabang — satu zona; bandingkan string `H:i` leksikografis. Range terbalik selalu `false` sebagai guard row tua/DB manual.)

Run: `$HOME/.bun/bin/bun run test:js -- opening-hours`
Expected: PASS (6/6).

- [ ] **Step 4: Props tes halaman (gagal dulu)**

`tests/Feature/OrderCreationTest.php` — tes yang sudah `->get('/pos')` + assert Inertia page: tambah `->where('branch.opens_at', '09:00')` + `->where('branch.closes_at', '21:00')`.
`tests/Feature/KitchenTest.php` — `test_kitchen_page_loads_for_kitchen_user` + `test_owner_can_choose_kitchen_branch` (kedua-duanya assert `branch.id`): tambah asersi yang sama.

Run: `php artisan test --filter "OrderCreationTest|KitchenTest"`
Expected: FAIL — presenter belum mengirim `opens_at`/`closes_at`.

- [ ] **Step 5: Presenter + type TS**

**`BranchPresenter::summary()`** — tambah:

```php
'opens_at' => $branch->opens_at,
'closes_at' => $branch->closes_at,
```

**`resources/js/lib/order.ts`** — type `BranchSummary` tambah `opens_at: string;` + `closes_at: string;`.

Run: `php artisan test --filter "OrderCreationTest|KitchenTest"`
Expected: PASS.

- [ ] **Step 6: Chip UI POS + dapur**

**POS `resources/js/pages/pos/Index.vue`**:
- Import: `onMounted`/`onUnmounted` dari `vue` (ikuti import yang ada), `isOpenNow` dari `@/lib/opening-hours`.
- Tick 60 s (pola interval yang sudah ada di page ini):

```ts
const now = ref(Date.now());

let hoursTimer: number | null = null;

onMounted(() => {
    hoursTimer = window.setInterval(() => {
        now.value = Date.now();
    }, 60_000);
});

onUnmounted(() => {
    if (hoursTimer !== null) {
        window.clearInterval(hoursTimer);
    }
});

const isOpen = computed(
    () =>
        !!props.branch &&
        props.branch.is_active &&
        isOpenNow(props.branch.opens_at, props.branch.closes_at, new Date(now.value)),
);
```

- Header: dekat baris subjudul cabang (`{{ props.branch.name }} · {{ props.branch.code }}`, area `~:500`), satu chip inline (style mengikuti token file: `text-muted`/`text-success`/`text-danger`): teks `Buka {{ props.branch.opens_at }}–{{ props.branch.closes_at }}` + status `Buka` (tone success) / `Tutup` (tone danger) dari `isOpen`.
- Flash lembut (di samping banner nonaktif yang sudah ada `~:582`, pola class yang sama persis): tampil hanya saat cabang **aktif** tapi jam sudah lewat — `v-if="props.branch && props.branch.is_active && !isOpen"`:

```vue
<div
    v-if="props.branch && props.branch.is_active && !isOpen"
    class="rounded-xl border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning"
>
    Di luar jam operasional ({{ props.branch.opens_at }}–{{ props.branch.closes_at }}). Pesanan
    tetap bisa dibuat.
</div>
```

**Dapur `resources/js/pages/kitchen/Index.vue`**:
- Import `isOpenNow`; tambah `const isOpen = computed(() => !!props.branch && props.branch.is_active && isOpenNow(props.branch.opens_at, props.branch.closes_at, new Date(now.value)));` — **pakai `now` (1 s) yang sudah ada, jangan bikin interval baru**.
- Header dekat nama branch (area `~:299` `{{ branch.name }}`): chip persis pola POS.

- [ ] **Step 7: PRD** — di bagian P0 yang menyebut cabang (baris "Each branch has name, address, contact, opening hours, and active status"), setelah poin itu tambah paragraf gaya "implemented in the current release" (sama seperti catatan catalog §7.3): default 09:00–21:00, owner editing di halaman cabang, status dihitung klien (bukan server), batas tutup eksklusif, non-goal (penutupan sementara / block order / jam mingguan = P1). Detail: `docs/superpowers/specs/2026-10-31-branch-opening-hours-design.md`.

- [ ] **Step 8: Verifikasi**

Run: `php artisan test && $HOME/.bun/bin/bun run test:js && $HOME/.bun/bin/bun run typecheck`
Expected: semua PASS + typecheck bersih. Pint: `vendor/bin/pint --parallel --test` pada file PHP berubah.

- [ ] **Step 9: Commit**

```bash
git add app/Modules/Branches/Support/BranchPresenter.php resources/js/lib/order.ts resources/js/lib/opening-hours.ts tests/js/opening-hours.test.ts resources/js/pages/pos/Index.vue resources/js/pages/kitchen/Index.vue tests/Feature/OrderCreationTest.php tests/Feature/KitchenTest.php PRD.md
git commit -m "feat(branches): open/closed status di POS dan dapur + PRD"
```
