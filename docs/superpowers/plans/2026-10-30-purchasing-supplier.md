# Pembelian & Supplier — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modul Pembelian & Supplier: daftar supplier (owner kelola, tidak pernah dihapus) + dokumen penerimaan barang dari supplier yang dicatat dapur/kasir/owner, harga beli per satuan supplier tercatat permanen, stok bertambah lewat ledger append-only, idempoten, auditable, branch-scoped.

**Architecture:** Modul `app/Modules/Purchasing` mengikuti pola modul lain: model `Supplier` / `Purchase` / `PurchaseItem` (immutable), action `RecordPurchase` (transaction: dokumen + konversi unit + ledger + audit), 2 controller owner (`.gitkeep`-free, tipis) + 1 FormRequest untuk pembelian. UI: 1 komponen form Vue dipakai 3 layar (POS, dapur, admin) + 2 halaman riwayat owner + 1 halaman supplier. Uang integer rupiah; konversi satuan via `ConvertUnit` yang sudah ada dengan pengecekan round-trip agar hasil non-integer ditolak.

**Tech Stack:** Laravel 13 (PHP), Vue 3 + TypeScript (Inertia), PostgreSQL, PHPUnit, Pint. Tidak ada dependensi baru.

**Spec:** `docs/superpowers/specs/2026-10-30-purchasing-supplier-design.md` (sumber benar; baca dulu).

**Catatan operator (bukan untuk builder):** semua subagent SDD memakai model `opencode/*` (larang `9router/*`). Ledger SDD: `.superpowers/sdd/2026-10-30-purchasing-supplier/`.

## Global Constraints

- Uang selalu **integer rupiah** (kolom `bigInteger`), tanpa float di mana pun (DB, PHP, JS).
- Ledger append-only: penulisan `stock_balances`/`stock_movements` **hanya** dengan pola `ReceiveStockTransfer` (`insertOrIgnore` → `lockForUpdate` → `update` → `StockMovement::create`). **Jangan sentuh** `RecordStockMovement` (penerimaan manual tetap owner-only).
- Dokument pembelian & itemnya **immutable**: model menolak update/delete (`LogicException`, pola `StockMovement`).
- Idempotency: `unique(['branch_id','idempotency_key'])` di `purchases`; POST ulang key sama = kembalikan pembelian pertama (bukan 422, bukan duplikat). Preseden: `CreateOrder`.
- Otorisasi dua lapis: middleware route (`role:...`) + guard di action (`role ∈ {owner,cashier,kitchen}` **dan** `hasBranchAccess($branchId)`).
- `received_at` DATE, boleh mundur maks 90 hari, tanpa tanggal masa depan (validasi server-side).
- Timezone bisnis `Asia/Jakarta` (default app). Kebutuhan rate-limit extra: limiter `purchases` 10/menit di AppServiceProvider (AGENTS: rate-limit endpoint sensitif).
- UI Bahasa Indonesia, mengikuti kelas/komponen halaman yang ada (`Input`, `Select`, `EmptyState`, `Money`), tanpa brand baru.
- Tidak ada broadcast/event realtime untuk pembelian.
- Per task: gerbang `CI=true composer ci:check` (phpunit 295→naik, vitest, build, pint) hijau **sebelum** commit. `migrate:fresh` dilarang; tes pakai `RefreshDatabase` (DB tes `pizza_fruzta_test`).
- Commit message pola repo: `feat: …`, `test: …`, `docs: …`.

## Review Focus

1. **Ledger ganda / idempotency bocor** → replay key sama harus kembali dokumen pertama (Task 2 + Task 3 tes HTTP); concurrency (Task 6).
2. **Lintas-cabang bypass** (dapur A catat pembelian cabang B) → guard action `hasBranchAccess` + matriks tes HTTP (Task 2/3).
3. **Konversi non-integer diam-diam dibulatkan jadi 0** (`intdiv` di `ConvertUnit` lantai) → round-trip check di `RecordPurchase` + tes 750 g → 0 kg ditolak (Task 2).
4. **Float / drift uang** pada jumlah besar → semua kolom integer; tes 4,4 triliun rupiah (Task 2).
5. **404 vs 422 salah** — supplier tidak ada = 404 (`findOrFail`), supplier nonaktif = 422 (Task 3).
6. **Privilege lewat route** (kasir/dapur ke `/admin/*`) → matriks 403 (Task 3); riwayat = owner-only (Task 5 hanya di nav owner).
7. **`RecordStockMovement` berubah** → preservation test regression bahwa receipt manual tetap owner-only (Task 3).

---

### Task 1: Skema + model + factory + tes schema

**Files:**

- Create: `database/migrations/2026_10_30_000001_create_suppliers_table.php`
- Create: `database/migrations/2026_10_30_000002_create_purchases_table.php`
- Create: `database/migrations/2026_10_30_000003_create_purchase_items_table.php`
- Create: `app/Modules/Purchasing/Models/Supplier.php`, `Purchase.php`, `PurchaseItem.php`
- Create: `database/factories/SupplierFactory.php`, `PurchaseFactory.php`, `PurchaseItemFactory.php`
- Create: `tests/Feature/PurchaseSchemaTest.php`

**Interfaces:**

- Consumes: none.
- Produces (dipakai Task 2–6):
  - `App\Modules\Purchasing\Models\Supplier` — `#[Fillable(['name','phone','is_active'])]`, cast `is_active` boolean, `HasFactory`. Puede di-`update` (nama/telepon/aktif), **pola staf: tidak ada delete di UI/route**.
  - `App\Modules\Purchasing\Models\Purchase` — `#[Fillable(['branch_id','supplier_id','received_at','reference','total','recorded_by','idempotency_key'])]`, cast `received_at => 'date:Y-m-d'`, `total => 'integer'`; `const UPDATED_AT = null;`; `booted()`: `updating`/`deleting` → `LogicException('Purchases are immutable.')`; relations `branch()`, `supplier()`, `recordedBy(): BelongsTo User`, `items(): HasMany PurchaseItem`; `HasFactory`.
  - `App\Modules\Purchasing\Models\PurchaseItem` — `#[Fillable(['purchase_id','ingredient_id','unit_id','quantity','unit_price','line_total'])]`, cast `quantity`/`unit_price`/`line_total` integer; `const UPDATED_AT = null;` + guard imutabel (pesan `'Purchase items are immutable.'`); relations `purchase()`, `ingredient()`, `unit()`; `HasFactory`.
  - Factory: `SupplierFactory` (name unique `fake()->unique()->company()`, phone, `is_active` true), `PurchaseFactory` (default `Branch::factory()`, `Supplier::factory()`, `received_at => now()->toDateString()`, `reference null`, `total 0`, `recorded_by => User::factory()`, `idempotency_key => Str::uuid()`), `PurchaseItemFactory` (default `Purchase::factory()`, `Ingredient::factory()`, `Unit::factory()`, `quantity 1`, `unit_price 0`, `line_total 0`).
  - Skema DB (uraian kolom):
    - `suppliers`: `id`, `name` string **unique**, `phone` nullable, `is_active` boolean default true, timestamps.
    - `purchases`: `id`, `branch_id` FK `branches` restrict, `supplier_id` FK `suppliers` restrict, `received_at` DATE, `reference` nullable, `total` bigInteger default 0, `recorded_by` FK `users` restrict, `idempotency_key` string, `timestamps()`; **unique `['branch_id','idempotency_key']`**, index `['branch_id','received_at']`.
    - `purchase_items`: `id`, `purchase_id` FK `purchases` restrict, `ingredient_id` FK `ingredients` restrict, `unit_id` FK `units` restrict, `quantity` bigInteger, `unit_price` bigInteger, `line_total` bigInteger, `timestamps()`.

- [ ] **Step 1: Tes gagal dulu — `tests/Feature/PurchaseSchemaTest.php`**

`use RefreshDatabase;` (mirip `RecipeSchemaTest`). Tes:

1. `test_purchase_documents_and_items_are_immutable` — buat `Purchase::factory()->create()` + item; `expectException(LogicException::class)` saat `$purchase->update(['reference' => 'x'])`; ulang untuk `delete()` purchase item (2 expectException terpisah / 2 metode).
2. `test_idempotency_key_unique_per_branch` — 2 purchase, branch sama, `idempotency_key` sama → `QueryException` 23505 (pola `RecipeSchemaTest::test_one_recipe_per_recipeable`); branch berbeda dengan key sama = boleh.
3. `test_supplier_name_unique` — 2 supplier nama sama → 23505.
4. `test_relations_and_casts` — traversal `purchase->supplier`, `->recordedBy`, `->items()->ingredient`, cast `received_at` jadi `Carbon` string `Y-m-d`.

Run: `php artisan test --filter=PurchaseSchemaTest`
Expected: FAIL (table tidak ada / class belum ada).

- [ ] **Step 2: Migrasi + model + factory**

Tulis 3 migrasi + 3 model + 3 factory sesuai uraian di atas. Nama migrasi presisi: `2026_10_30_000001_create_suppliers_table.php`, `2026_10_30_000002_create_purchases_table.php`, `2026_10_30_000003_create_purchase_items_table.php`.

- [ ] **Step 3: Hijaukan**

Run: `php artisan test --filter=PurchaseSchemaTest`
Expected: PASS.

- [ ] **Step 4: Gerbang**

Run: `CI=true composer ci:check`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_10_30_00000{1,2,3}_*.php \
  app/Modules/Purchasing/Models database/factories \
  tests/Feature/PurchaseSchemaTest.php
git commit -m "feat: skema & model modul pembelian (suppliers, purchases, purchase_items)"
```

---

### Task 2: Action `RecordPurchase` + tes level action

**Files:**

- Create: `app/Modules/Purchasing/Data/RecordPurchaseData.php`
- Create: `app/Modules/Purchasing/Actions/RecordPurchase.php`
- Create: `tests/Feature/PurchaseActionTest.php`

**Interfaces:**

- Consumes: Task 1 (model+factory); `App\Modules\Inventory\Actions\ConvertUnit`; `App\Modules\Identity\Actions\WriteAuditLog`; `Branch`, `Ingredient`, `StockBalance`, `StockMovement`, `StockMovementType`.
- Produces (dipakai Task 3):
  - `App\Modules\Purchasing\Data\RecordPurchaseData`:

    ```php
    final class RecordPurchaseData
    {
        /**
         * @param list<array{ingredient_id: int, unit_id: int, quantity: int, unit_price: int}> $items
         */
        public function __construct(
            public readonly int $branchId,
            public readonly int $supplierId,
            public readonly string $receivedAt, // 'YYYY-MM-DD'
            public readonly ?string $reference,
            public readonly string $idempotencyKey,
            public readonly array $items,
        ) {}
    }
    ```

  - `App\Modules\Purchasing\Actions\RecordPurchase`:

    ```php
    public function __construct(
        private readonly ConvertUnit $convertUnit,
        private readonly WriteAuditLog $writeAuditLog,
    ) {}
    public function __invoke(User $actor, RecordPurchaseData $data): Purchase
    ```

    Kontrak perilaku (urut; semua tes mengunci ini):
    1. Guard role: `role() ∉ {owner,cashier,kitchen}` → `AuthorizationException('Anda tidak dapat mencatat pembelian.')`.
    2. `! $actor->hasBranchAccess($branchId)` → `AuthorizationException('Anda tidak dapat mencatat pembelian untuk cabang lain.')`.
    3. Idempotency pre-check (di luar transaction): `Purchase::with('items')->where('branch_id',…)->where('idempotency_key',…)->first()` → `return $existing` (tanpa perubahan apa pun).
    4. Supplier: `Supplier::query()->findOrFail($supplierId)` → 404 y.a.; `! $supplier->is_active` → `ValidationException` `supplier_id`: `'Supplier tidak tersedia.'`.
    5. Branch: tidak ada / nonaktif → `ValidationException` `branch_id`: `'Cabang tidak aktif.'` (pola `CreateOrder`).
    6. `items` kosong → `ValidationException` `items`: `'Minimal satu baris.'`.
    7. Bahan duplikat (2 baris `ingredient_id` sama) → `ValidationException` `items`: `'Bahan tidak boleh duplikat dalam satu dokumen.'`.
    8. Per baris `i`, urut input: `Ingredient::find` null → `ValidationException` `items.$i.ingredient_id`: `'Bahan tidak ditemukan.'`.
    9. Konversi `entryUnit → baseUnit` (`ConvertUnit`): jika tidak ada path → tangkap `ValidationException`-nya dan ulang sebagai `ValidationException` `items.$i.unit_id`: `'Definisikan konversi unit terlebih dahulu.'`. **Round-trip:** `ConvertUnit(baseUnit, $converted, entryUnit) !== $quantity` → `ValidationException` `items.$i.quantity`: `'Hasil konversi bukan bilangan bulat.'` (penebus pembulatan lantai `intdiv`).
    10. `line_total[i] = quantity * unit_price` (dalam unit entri, integer); `total = Σ line_total`.
    11. `DB::transaction`: cek ulang `findExisting` (return jika ada) → `Purchase::create([...total])` → `items()->create(...)` per baris → per baris **diurutkan `ingredient_id`** (hindari deadlock, pola `ReceiveStockTransfer`): `StockBalance insertOrIgnore → lockForUpdate → quantity += $converted` → `StockMovement::create` (`branch_id`, `ingredient_id`, `unit_id = base_unit_id`, `quantity = $converted`, `type = StockMovementType::Receipt`, `reason = "Penerimaan pembelian #{$purchase->id} — {$supplier->name}"`, `reference_type = Purchase::class`, `reference_id = $purchase->id`, `actor_id`).
    12. Audit `purchase.received` (target `Purchase`; after: `supplier_id`, `supplier_name`, `branch_id`, `total`, `items` = snapshot `[ingredient, unit_code, quantity, unit_price, line_total, base_quantity]`).
    13. `catch (QueryException)`: pesan mengandung `purchases_branch_id_idempotency_key_unique` → kembalikan pembelian yang ada (pola `CreateOrder::isIdempotencyViolation`) — ini cabang race 2 POST paralel.
    14. Return `Purchase` dengan `items` ter-load.

- [ ] **Step 1: Tes gagal dulu — `tests/Feature/PurchaseActionTest.php`**

`use RefreshDatabase;`. Setup umum: 2 branch, user kitchen/cashier/owner (`User::factory()->kitchen()->create(['branch_id' => $branchA->id])` dst.), supplier aktif, ingredient dengan base unit, unit + `UnitConversion` (1 kg = 1000 g). Helper `makeData(...)` membangun `RecordPurchaseData`. Tes (nama presisi = kontrak):

1. `test_kitchen_records_own_branch_purchase_with_ledger_and_audit` — 1 baris `25 kg @ 12000`, ingredient base **g**: assert `Purchase` created, `purchase->total === 300000`, `item->line_total === 300000` (qty 25 × harga 12000 dalam kg), `PurchaseItem.unit_id` = unit entri kg, movement: `type === StockMovementType::Receipt`, `unit_id` = base g, `quantity === 25000`, `reference_type === Purchase::class`, `reference_id === $purchase->id`, `reason` persis `"Penerimaan pembelian #{$purchase->id} — {nama}"`, saldo `stock_balances` bertambah 25000, audit row `purchase.received` ada.
2. `test_cashier_and_owner_can_record_purchase` — masing-masing role OK (assert purchase dibuat).
3. `test_kitchen_cannot_record_for_other_branch` — kitchen APosting ke branch B → `expectException(AuthorizationException)`.
4. `test_missing_supplier_returns_404` — `expectException(ModelNotFoundException)` (`supplierId: 999999`).
5. `test_inactive_supplier_rejected` — `ValidationException` keyed `supplier_id`.
6. `test_inactive_branch_rejected` — branch `is_active=false` → `ValidationException` keyed `branch_id`.
7. `test_empty_items_rejected`, `test_duplicate_ingredient_rejected`.
8. `test_missing_conversion_rejected` — unit entri tanpa path konversi ke base → `ValidationException` keyed `items.0.unit_id`.
9. `test_non_integer_conversion_result_rejected` — ingredient base **kg**, konversi `1 kg = 1000 g`, baris unit entri **g** qty `750` → `ValidationException` keyed `items.0.quantity` (750 g → 0 kg, round-trip 0 g ≠ 750).
10. `test_idempotent_replay_returns_first_purchase` — invoke 2× key sama: `assertSame($first->id, $second->id)`; `StockMovement::count() === 1`; saldo terhitung sekali.
11. `test_money_total_is_sum_of_lines_with_large_values` — baris `quantity 5_000_000 @ 888_888` → `line_total === 4_444_440_000_000` (integer, tanpa drift).

Run: `php artisan test --filter=PurchaseActionTest`
Expected: FAIL (action belum ada).

- [ ] **Step 2: Implementasi**

Tulis `RecordPurchaseData` + `RecordPurchase` mengikuti kontrak di atas (copy struktur transaction dari `ReceiveStockTransfer`, idempotency dari `CreateOrder`).

- [ ] **Step 3: Hijaukan**

Run: `php artisan test --filter=PurchaseActionTest`
Expected: PASS.

- [ ] **Step 4: Gerbang**

Run: `CI=true composer ci:check`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Purchasing/Data app/Modules/Purchasing/Actions tests/Feature/PurchaseActionTest.php
git commit -m "feat: action RecordPurchase (dokumen + konversi unit + ledger + idempotency + audit)"
```

---

### Task 3: Controllers, FormRequest, routes, throttle, tes HTTP

**Files:**

- Create: `app/Modules/Purchasing/Http/Controllers/PurchaseController.php`
- Create: `app/Modules/Purchasing/Http/Controllers/SupplierController.php`
- Create: `app/Http/Requests/StorePurchaseRequest.php`
- Create: `app/Http/Requests/StoreSupplierRequest.php`, `UpdateSupplierRequest.php`
- Modify: `routes/web.php` (3 blok + import)
- Modify: `app/Providers/AppServiceProvider.php` (limiter `purchases`)
- Create: `tests/Feature/PurchaseHttpTest.php`
- Create: `tests/Feature/SupplierHttpTest.php`

**Interfaces:**

- Consumes: Task 1 (model/factory), Task 2 (`RecordPurchase`, `RecordPurchaseData`), `ResolvePosBranch`, `BranchPresenter`, `WriteAuditLog`.
- Produces (dipakai Task 4/5 — route name & props **persis**):

  Routes (tambah di `routes/web.php`):
  - Grup `['auth','role:cashier,owner']` (POS, sisip dekat route pos yang ada):
    - `GET /pos/purchases` → `PurchaseController::posCreate` — name `pos.purchases.create`
    - `POST /pos/purchases` → `PurchaseController::store` — middleware `throttle:purchases` — name `pos.purchases.store`
  - Grup `['auth','role:kitchen,owner']` (dapur):
    - `GET /kitchen/purchases` → `PurchaseController::kitchenCreate` — name `kitchen.purchases.create`
    - `POST /kitchen/purchases` → `PurchaseController::store` — `throttle:purchases` — name `kitchen.purchases.store`
  - Grup `['auth','role:owner']` prefix `admin`:
    - `GET /admin/purchases` → `index` — `admin.purchases.index`
    - `GET /admin/purchases/create` → `create` — `admin.purchases.create`  (WAJIB terdaftar **sebelum** `/purchases/{purchase}`)
    - `POST /admin/purchases` → `store` — `throttle:purchases` — `admin.purchases.store`
    - `GET /admin/purchases/{purchase}` → `show` — `admin.purchases.show`
    - `GET /admin/suppliers` → `SupplierController::index` — `admin.suppliers.index`
    - `POST /admin/suppliers` → `store` — `admin.suppliers.store`
    - `PATCH /admin/suppliers/{supplier}` → `update` — `admin.suppliers.update`
  - AppServiceProvider: `RateLimiter::for('purchases', fn (): Limit => Limit::perMinute(10));` — copy pola limiter `orders` persis.

  `PurchaseController` (namespace `App\Modules\Purchasing\Http\Controllers`):
  - `posCreate(Request, ResolvePosBranch): Response` → `Inertia::render('pos/ReceiveStock', $props)`
  - `kitchenCreate(...)` → `Inertia::render('kitchen/ReceiveStock', $props)`
  - `create(...)` → `Inertia::render('admin/purchases/Receive', $props)`
  - `index(Request, ResolvePosBranch): Response` → `Inertia::render('admin/purchases/Index', [...])`
  - `show(Purchase $purchase): Response` → `Inertia::render('admin/purchases/Show', [...])`
  - `store(StorePurchaseRequest, RecordPurchase): RedirectResponse`
  - `props` form create = (helper private, dipakai 3 create):

    ```php
    [
        'branch' => $branch ? BranchPresenter::summary($branch) : null,
        'branches' => $user->isOwner() ? BranchPresenter::options() : [],
        'canChooseBranch' => $user->isOwner(),
        'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
        'units' => Unit::query()->orderBy('name')->get(['id', 'code', 'name'])->all(),
        'ingredients' => Ingredient::query()->with('baseUnit')->orderBy('name')->get()
            ->map(fn (Ingredient $i) => ['id' => $i->id, 'name' => $i->name,
                'base_unit_id' => $i->base_unit_id, 'unit' => $i->baseUnit?->code])->values()->all(),
        'default_date' => now()->toDateString(),
    ]
    ```

    `$branch = $resolveBranch($user, $request->integer('branch') > 0 ? $request->integer('branch') : null)` (pola `kitchenIndex`).
  - `store()`: from `$request->validated()` bangun `RecordPurchaseData` (cast int untuk items); `return back()->with('success', "Penerimaan #{$purchase->id} tercatat.");`
  - `index()`: `branch` filter (owner: `?branch=`) seperti halaman `admin/stock/Movements`; list `Purchase::with(['supplier','branch','recordedBy'])` (branch terkunci? non-applicable — grup owner) `orderByDesc('received_at')->orderByDesc('id')->limit(100)`; row: `id, received_at (Y-m-d), reference, supplier_name, branch_name, total, recorded_by`.
  - `show()`: `load(['supplier','branch','recordedBy','items.ingredient','items.unit'])`; payload: `purchase: { id, received_at, reference, total, supplier_name, branch_name, recorded_by, created_at, items: [ { id, ingredient_name, unit_code, quantity, unit_price, line_total } ] }`.

  `SupplierController`:
  - `index(): Response` → `admin/suppliers/Index`, `suppliers` = semua `orderBy('name')`: `id, name, phone, is_active`.
  - `store(StoreSupplierRequest, WriteAuditLog): RedirectResponse` — `Supplier::create([...validated, 'is_active' => $data['is_active'] ?? true])`, audit `supplier.created` (after = snapshot), `back()->with('success', "Supplier {$supplier->name} ditambahkan.");`
  - `update(UpdateSupplierRequest, Supplier $supplier, WriteAuditLog): RedirectResponse` — before/after snapshot, audit `supplier.updated`, `back()->with('success', 'Supplier diperbarui.')`
  - `snapshot(Supplier): array` = `['name','phone','is_active']`
  - (Tanpa Policy — grup `role:owner` sudah guard server-side; konsisten dengan `BranchController`.)

  `StorePurchaseRequest`:
  - `authorize()`: `in_array($this->user()?->role(), [Role::Owner, Role::Cashier, Role::Kitchen], true)`
  - `rules()`:

    ```php
    'branch_id' => ['required', 'integer', 'exists:branches,id'],
    'supplier_id' => ['required', 'integer'], // exists di action → 404 sesuai spec
    'received_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today',
        'after_or_equal:' . now()->subDays(90)->toDateString()],
    'reference' => ['nullable', 'string', 'max:100'],
    'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
    'items' => ['required', 'array', 'min:1'],
    'items.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
    'items.*.unit_id' => ['required', 'integer', 'exists:units,id'],
    'items.*.quantity' => ['required', 'integer', 'min:1'],
    'items.*.unit_price' => ['required', 'integer', 'min:0'],
    ```

  `StoreSupplierRequest`: `name` required unique:suppliers,name; `phone` nullable max:30; `is_active` sometimes boolean.
  `UpdateSupplierRequest`: `name` required unique:suppliers,name,ignore; `phone` nullable; `is_active` required boolean.

- [ ] **Step 1: Tes gagal dulu — `tests/Feature/PurchaseHttpTest.php` + `SupplierHttpTest.php`**

`use RefreshDatabase;`. Matriks (ikuti gaya `ChannelAuthorizationTest`/`StockConcurrencyTest`):

  PurchaseHttpTest:
  1. `test_kitchen_sees_form_and_submits_purchase` — GET `/kitchen/purchases` 200; POST `/kitchen/purchases` (kitchen A, `branch_id` A, supplier aktif, 1 baris, key uuid) → `assertRedirect()`; DB: purchase + movement `Receipt` + saldo + audit.
  2. `test_cashier_submits_via_pos` — POST `/pos/purchases` → 302 + purchase di branch kasir.
  3. `test_owner_browses_admin_pages` — GET `/admin/purchases`, `/admin/purchases/create`, `/admin/purchases/{id}` 200; POST `/admin/purchases` → 302.
  4. `test_role_branch_matrix` (dataProvider): kitchen A → POST `/pos/purchases` 403; kitchen A POST branch B 403; cashier GET `/kitchen/purchases` 403; kitchen GET `/admin/purchases` 403; cashier GET `/admin/purchases` 403; kitchen GET `/admin/suppliers` 403.
  5. `test_validation_failures` (dataProvider → 422): qty `0`; qty `-5`; unit_price `-1`; `items` kosong; 2 baris bahan sama (key error `items`); supplier nonaktif (key `supplier_id`); tanggal besok (key `received_at`); tanggal 91 hari lalu 422; tanggal 90 hari lalu 302.
  6. `test_missing_supplier_is_404` — POST `supplier_id: 999999` → `assertNotFound()`.
  7. `test_http_idempotent_replay` — POST 2× payload + key sama → kedua 302; `Purchase::count() === 1`; movement 1; saldo +1×.
  8. `test_manual_receipt_still_owner_only` (regression) — owner POST `/admin/stock/movements` `type=receipt` → 302; kitchen POST hal yang sama → 403.

  SupplierHttpTest:
  9. `test_owner_creates_supplier` — POST `/admin/suppliers` 302 + `assertDatabaseHas('suppliers', …)` + audit `supplier.created`.
  10. `test_duplicate_name_rejected` → 422.
  11. `test_owner_updates_supplier` — PATCH nama/telepon/`is_active=false` → 302 + audit `supplier.updated` (before/after assert).
  12. `test_non_owner_forbidden` — cashier POST `/admin/suppliers` → 403.

Run: `php artisan test --filter=PurchaseHttpTest --filter=SupplierHttpTest`
Expected: FAIL (route belum ada).

- [ ] **Step 2: Implementasi**

FormRequest + 2 controller + routes + limiter sesuai uraian di atas.

- [ ] **Step 3: Hijaukan**

Run: `php artisan test --filter=PurchaseHttpTest --filter=SupplierHttpTest`
Expected: PASS.

- [ ] **Step 4: Gerbang**

Run: `CI=true composer ci:check`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Purchasing/Http app/Http/Requests routes/web.php \
  app/Providers/AppServiceProvider.php tests/Feature/PurchaseHttpTest.php \
  tests/Feature/SupplierHttpTest.php
git commit -m "feat: route & controller pembelian + supplier (HTTP, throttle, tes matriks)"
```

---

### Task 4: Form Vue dibagikan + 3 wrapper + tombol header

**Files:**

- Create: `resources/js/components/purchasing/ReceiveStockForm.vue`
- Create: `resources/js/pages/pos/ReceiveStock.vue`
- Create: `resources/js/pages/kitchen/ReceiveStock.vue`
- Create: `resources/js/pages/admin/purchases/Receive.vue`
- Modify: `resources/js/pages/pos/Index.vue` (Link "Terima barang")
- Modify: `resources/js/pages/kitchen/Index.vue` (Link di header)
- Modify: `resources/js/pages/kitchen/Stock.vue` (Link di header)

**Interfaces:**

- Consumes: props controller dari Task 3 (`branch, branches, canChooseBranch, suppliers, units, ingredients, default_date` — prop `default_date` dikirim underscore, wrapper memetakan ke `defaultDate`), `StaffLayout`, `Input`, `Select`, `formatRupiah` dari `@/lib/money`.
- Produces:
  - `ReceiveStockForm.vue` — props: `branch: BranchSummary | null; branches: BranchOption[]; canChooseBranch: boolean; suppliers: {id: number; name: string}[]; units: {id: number; code: string; name: string}[]; ingredients: {id: number; name: string; base_unit_id: number; unit: string | null}[]; defaultDate: string; submitUrl: string;`
  - Perilaku: baris awal 1 (kosong); pilih bahan → `unit_id` otomatis `base_unit_id`; qty/harga input integer (input `type="number" step="1" min="0"`); total baris & total keseluruhan via `formatRupiah` (**paparan saja** — server re-itung); date input `max` hari ini `min` 90 hari lalu (diisi ulang `now()` di klien, server tetap otoritatif); `idempotency_key` = `crypto.randomUUID()` saat mount dan regenerasi tiap sukses; submit: guard klien ringan (supplier wajib, ≥1 baris bahan terpilih) lalu `router.post(props.submitUrl, payload, { preserveScroll: true, onSuccess: () => { reset baris; ulang idempotency key; } })`; error validasi dari `page.props.errors` ditampilkan di bawah field; sukses di-review lewat flash di wrapper.
  - Wrapper (3 file, struktur identik): `StaffLayout` → `Head` judul "Terima barang" → banner flash `success`/`error` (copy persis pola `kitchen/Stock.vue` baris 186–199) → Link kembali (`/pos` | `/kitchen` | `/admin/purchases`) → `<ReceiveStockForm … :submit-url="'/pos/purchases'" />` (per wrapper URL-nya). Type props digandakan di tiap wrapper (pola rumah `kitchen/Stock.vue`).
  - Tombol header:
    - `pos/Index.vue`: 1 baris `Link href="/pos/purchases"` "Terima barang" (kelas button netral yang sama seperti Link lain di halaman POS), posisikan tepat setelah blok flash error (± baris 557, sebelum banner `stale_unpaid`).
    - `kitchen/Index.vue` + `kitchen/Stock.vue`: 1 `Link href="/kitchen/purchases"` "Terima barang" di header, di samping Link Dapur/Admin yang sudah ada (kelas sama: `min-h-12 … border-white/15`).

- [ ] **Step 1: Komponen form**

Tulis `ReceiveStockForm.vue` sesuai kontrak. Referensi gaya: `kitchen/Stock.vue` (local types, `useForm` vs `router.post` — pakai `router.post` + `ref` untuk baris dinamis) dan `admin/stock/Movements.vue` (styling tabel/form admin).

- [ ] **Step 2: 3 wrapper + tombol**

Buat 3 halaman wrapper; tambah 3 Link (1 di POS, 2 di dapur).

- [ ] **Step 3: Cek type + build**

Run: `bun run typecheck && bun run build`
Expected: green tanpa error.

- [ ] **Step 4: Gerbang**

Run: `CI=true composer ci:check`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/purchasing resources/js/pages/pos/ReceiveStock.vue \
  resources/js/pages/kitchen/ReceiveStock.vue resources/js/pages/admin/purchases/Receive.vue \
  resources/js/pages/pos/Index.vue resources/js/pages/kitchen
git commit -m "feat: form terima barang (1 komponen, 3 layar) + tombol di POS & dapur"
```

---

### Task 5: Halaman admin (riwayat pembelian, detail, supplier) + nav

**Files:**

- Create: `resources/js/pages/admin/purchases/Index.vue`
- Create: `resources/js/pages/admin/purchases/Show.vue`
- Create: `resources/js/pages/admin/suppliers/Index.vue`
- Modify: `resources/js/layouts/StaffLayout.vue` (nav owner: + "Pembelian")

**Interfaces:**

- Consumes: route/props Task 3 — `admin/purchases/Index` (`branch, branches, canChooseBranch, purchases`), `admin/purchases/Show` (`purchase` + `items`), `admin/suppliers/Index` (`suppliers: {id:number; name:string; phone:string|null; is_active:boolean}[]`).
- Produces: (UI final)
  - `admin/purchases/Index.vue`: h1 "Pembelian"; baris tombol: Link "Terima barang" → `/admin/purchases/create` (primer) + Link "Supplier" → `/admin/suppliers`; filter cabang (owner, `router.get` replace seperti halaman movements); tabel `Tanggal | Nota | Supplier | Cabang | Total | Pencatat` (total via `formatRupiah`, tanggal `Y-m-d` ditampilkan `d M Y`… ikuti format halaman existing); baris klik → `/admin/purchases/{id}`; `EmptyState` kalau kosong.
  - `admin/purchases/Show.vue`: kartu header dokumen (supplier, cabang, tanggal diterima, nota, pencatat + waktu buat, total) + tabel baris `Bahan | Satuan entri | Jumlah | Harga/satuan | Total`; Link kembali ke `/admin/purchases`.
  - `admin/suppliers/Index.vue`: formulir atas "Tambah/Ubah supplier" (name, phone, checkbox `is_active` — state edit internal: klik "Ubah" di tabel mengisi form + `router.patch('/admin/suppliers/' + id)`; mode tambah = `router.post('/admin/suppliers')`); tabel `Nama | Telepon | Status`; **tidak ada tombol hapus** (spec: tidak pernah dihapus). Flash success/error ditampilkan (pola yang ada).
  - `StaffLayout.vue`: array nav owner — sisip `{ label: 'Pembelian', href: '/admin/purchases' }` setelah item "Stok". Nav kasir/dapur tidak berubah.

- [ ] **Step 1: Halaman**

Tulis 3 halaman mengikuti gaya `admin/stock/Transfers.vue` (tabel + empty state) dan `admin/staff/Index.vue` (form menambah + ubah).

- [ ] **Step 2: Nav**

Tambah 1 item nav owner di `StaffLayout.vue`.

- [ ] **Step 3: Cek type + build**

Run: `bun run typecheck && bun run build`
Expected: green.

- [ ] **Step 4: Gerbang**

Run: `CI=true composer ci:check`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/admin/purchases resources/js/pages/admin/suppliers \
  resources/js/layouts/StaffLayout.vue
git commit -m "feat: halaman admin riwayat pembelian + kelola supplier + nav"
```

---

### Task 6: Concurrency test + seeder + dokumen

**Files:**

- Create: `tests/Feature/PurchaseConcurrencyTest.php`
- Create: `database/seeders/SupplierSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php` (call seeder di blok non-prod)
- Create: `tests/Feature/SupplierSeederTest.php`
- Modify: `PRD.md` (§7.9 paragraf "Implemented in the current release")
- Modify: `README.md` (seksi `## Purchasing & suppliers`)

**Interfaces:**

- Consumes: semuanya dari Task 1–5.
- Produces: kesiapan rilis (tes concurrency di CI, data dev e2e, dokumen).

- [ ] **Step 1: Tes concurrency — `tests/Feature/PurchaseConcurrencyTest.php`**

`use DatabaseMigrations;` copy struktur `StockConcurrencyTest.php` (pcntl_fork, `DB::purge()` + `DB::reconnect()` di child, `exit(0)`). 2 child memanggil `app(RecordPurchase::class)(kitchenUser, makeData(baris sama ingredient, qty 100 dalam unit dasar, key berbeda))`; assert: kedua exit code `0`, `assertDatabaseHas('stock_balances', ['quantity' => 200])`, 2 movement `Receipt`, 2 purchase.

Run: `php artisan test --filter=PurchaseConcurrencyTest`
Expected: PASS.

- [ ] **Step 2: Seeder**

`SupplierSeeder::run()`: `Supplier::firstOrCreate(['name'=>'CV Tepung Nusantara'], ['phone'=>'081230000001','is_active'=>true])` + `Supplier::firstOrCreate(['name'=>'Susu Segar Jaya'], ['phone'=>'081230000002','is_active'=>true])`; ambil unit `kg` & `g` (dibuat `UnitSeeder`), `UnitConversion::firstOrCreate(['from_unit_id'=>$kg->id,'to_unit_id'=>$g->id], ['from_quantity'=>1,'to_quantity'=>1000])`. Di `DatabaseSeeder`: `$this->call(SupplierSeeder::class);` di blok non-prod (setelah user dibuat, sebelum `PilotDemoSeeder`).

- [ ] **Step 3: Tes seeder**

`tests/Feature/SupplierSeederTest.php` (`RefreshDatabase`): `$this->seed(SupplierSeeder::class)` → asser 2 supplier + konversi kg→g `from_quantity 1 / to_quantity 1000` ada.

Run: `php artisan test --filter=SupplierSeederTest`
Expected: PASS.

- [ ] **Step 4: Dokumen**

  - `PRD.md` §7.9 — tambahkan setelah baris P1 terakhir (sebelum `### 7.10`), gaya persis §7.6/§7.7:
    > Implemented in the current release: supplier master data managed by the owner (editable, deactivatable, never deleted) and purchase receipts recorded per branch with per-line purchase prices in supplier units (e.g. kg, pack), converted to base stock units for the stock ledger via existing unit conversions. Purchase documents are immutable with server-calculated line totals, idempotent submission, audit logging, and branch-scoped authorization (kitchen and cashier receive for their own branch; owners across branches). Purchase history and price reporting remain P1. Detail: `docs/superpowers/specs/2026-10-30-purchasing-supplier-design.md`.
  - `README.md` — seksi baru `## Purchasing & suppliers` (setelah `## Cashier shifts`, sebelum `## Operations`):

    > Suppliers live at `/admin/suppliers` (owner; edit/deactivate only, never deleted). Stock receipts against a supplier are recorded from `/admin/purchases`, the POS header, or the kitchen header: per line pick ingredient + supplier unit (kg, pack, …) + quantity + unit price. The server converts to the ingredient's base unit and appends `Receipt` stock movements. Purchase documents are immutable (corrections go through owner stock adjustments); each submission is idempotent and audited.

  - `AGENTS.md` — cek baris modul (± baris 77 sudah memuat "Purchasing & Warehouse" di daftar modularity); **jika sudah ada, jangan diubah** (verifikasi saja). Seksi repository layout sudah generik (`app/Modules/`) — tidak perlu perubahan.

- [ ] **Step 5: Gerbang**

Run: `CI=true composer ci:check`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/PurchaseConcurrencyTest.php tests/Feature/SupplierSeederTest.php \
  database/seeders PRD.md README.md AGENTS.md
git commit -m "test+docs: concurrency penerimaan, seeder supplier non-prod, PRD/README"
```

---

## Urutan eksekusi & verifikasi end-to-end

1. Task 1 → 6 berurutan (satu branch kerja), setiap task: builder → reviewer → gate green → commit.
2. Smoke manual (dev, setelah Task 6): `php artisan migrate && php artisan db:seed`; login `dapur.pf-01@pizzafruzta.test` (`password`) → header Dapur "Terima barang" → pilih supplier, baris "tepung 25 kg @ 12.000" → simpan; cek `/admin` (owner) → Pembelian → detail; cek stok bergerak 25.000 g; cek audit `purchase.received`.
3. Review final whole-branch (subagent `opencode/big-pickle`), push, `gh run watch`, lapor owner.
