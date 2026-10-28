# Recipe Inventory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pesanan yang mulai dimasak memotong stok bahan sesuai resep; void mengembalikan stok secara otomatis; saldo stok bisa diverifikasi ulang dari ledger; satuan dan konversi bisa dikelola admin.

**Architecture:** Modular monolith: model baru di `app/Modules/Inventory`, aksi konsumsi dipanggil dari `TransitionOrderStatus` dan `ReverseOrderConsumption` dari `VoidOrder`, UI admin Inertia mengikuti pola halaman `admin/stock` yang ada. Event potong stok satu titik: `config('inventory.deduct_on')`.

**Tech Stack:** PHP 8.5, Laravel 13, PostgreSQL, Vue 3 + Inertia + Tailwind, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-28-recipe-inventory-design.md`

## Global Constraints

- Uang tak terlibat rilis ini; semua kuantitas integer. Tanpa float di perhitungan.
- Copy UI Bahasa Indonesia. Grafik/label mengikuti token yang sudah ada di `resources/css/app.css` dan kelas Tailwind di halaman `admin/stock/Ingredients.vue` (mis. `rounded-2xl border border-line bg-white`).
- Timestamp tersimpan UTC, ditampilkan `Asia/Jakarta` (sudah ada di app).
- TDD: tulis test gagal dulu, jalankan gagal, implement, jalankan lulus, commit. Commit per task, pesan kecil.
- Jalankan `php artisan test`, `npm run typecheck`, `npm run lint`, `npm run build` sebelum task ditandai selesai (UI task: ketiganya; PHP task: `php artisan test` cukup, tetap jalankan lint pint bila menyentuh banyak file).
- Jangan menambah dependensi baru.
- Jangan merubah/delete baris `stock_movements`. Koreksi hanya lewat baris pembalikan.
- UI staff register (DESIGN.md §1): fungsional, tanpa dekorasi; setiap aksi sensitif punya konfirmasi yang menyebut konsekuensinya; CTA spesifik ("Simpan resep", bukan "Save").

## Review Focus

1. **Dobel klik "Mulai" di kitchen** (transisi `preparing` dipanggil dua kali). Sekarang state machine menolak transisi kedua; harus ada test yang memastikannya tidak menulis dua movement `consumption`. → Task 4.
2. **Dua order mulai bersamaan menumpuk di bahan yang sama.** Balance harus di-lock; satu menang, satu 422, saldo tidak pernah negatif. → Task 4 (fork test, pola `StockConcurrencyTest`).
3. **Void order yang sudah dikonsumsi (termasuk `completed`).** Stok harus kembali via movement pembalikan, baris `consumption` asli tetap ada. → Task 4.
4. **Qty resep salah satuan (kg vs g).** Baris item UI wajib menampilkan unit dasar bahan; service konversi menolak pasangan tanpa jalur, tidak menebak. → Task 2 + Task 6.
5. **Saldo rusak manual / crash tengah transaksi.** `inventory:reconcile` mendeteksi selisih (exit 1) dan `--fix` menyetel ulang + audit. → Task 5.

---

### Task 1: Skema resep & konversi satuan

**Files:**
- Create: `database/migrations/2026_10_28_000001_create_recipes_table.php`
- Create: `database/migrations/2026_10_28_000002_create_recipe_items_table.php`
- Create: `database/migrations/2026_10_28_000003_create_unit_conversions_table.php`
- Create: `app/Modules/Inventory/Models/Recipe.php`
- Create: `app/Modules/Inventory/Models/RecipeItem.php`
- Create: `app/Modules/Inventory/Models/UnitConversion.php`
- Create: `database/factories/RecipeFactory.php`
- Create: `database/factories/RecipeItemFactory.php`
- Create: `database/factories/UnitConversionFactory.php`
- Modify: `app/Modules/Inventory/Enums/StockMovementType.php`
- Modify: `app/Modules/Inventory/Actions/RecordStockMovement.php` (`validateMovement` saja)
- Test: `tests/Feature/RecipeSchemaTest.php`

**Interfaces:**
- Consumes: `Product`, `ProductVariant`, `Modifier`, `Unit`, `Ingredient` (models eksisting).
- Produces:
  - `Recipe` (morphTo `recipeable()`, hasMany `items()`), `RecipeItem` (`recipe()`, `ingredient()`, cast `quantity` => integer), `UnitConversion` (`fromUnit()`, `toUnit()`).
  - Enum case `StockMovementType::Consumption` (value `consumption`) dan `StockMovementType::ConsumptionReversal` (value `consumption_reversal`).
  - Factory: `Recipe::factory()->for($model, 'recipeable')`, `RecipeItem::factory()->for($recipe)`, `UnitConversion::factory()->for($unit, 'fromUnit')->for($unit2, 'toUnit')`.

- [ ] **Step 1: Tulis test skema yang gagal**

`tests/Feature/RecipeSchemaTest.php`: buat via `Recipe::factory()` → `RecipeItem::factory()` → `UnitConversion::factory()` (ikuti idiom `tests/Feature/ShiftSchemaTest.php`). Assert: membuat recipe kedua untuk target yang sama (ulang factory ke model target yang sama) melempar `QueryException` (unique `23505`); `UNIQUE(from_unit_id, to_unit_id)` menolak konversi ganda arah sama; `StockMovementType::Consumption` dan `::ConsumptionReversal` ada dan `requiresOwner()` dua-duanya `false`.

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=RecipeSchemaTest`
Expected: FAIL (model/migrasi belum ada).

- [ ] **Step 3: Tulis 3 migrasi**

- `recipes`: `id`, `string recipeable_type`, `unsignedBigInteger recipeable_id`, `unique(['recipeable_type','recipeable_id'])`, `timestamps`.
- `recipe_items`: `id`, `foreignId('recipe_id')->constrained()->cascadeOnDelete()`, `foreignId('ingredient_id')->constrained()->restrictOnDelete()`, `bigInteger('quantity')`, `timestamps`.
- `unit_conversions`: `id`, `foreignId('from_unit_id')->constrained('units')->restrictOnDelete()`, `foreignId('to_unit_id')->constrained('units')->restrictOnDelete()`, `bigInteger('from_quantity')->default(1)`, `bigInteger('to_quantity')->default(1)`, `unique(['from_unit_id','to_unit_id'])`, `timestamps`.

- [ ] **Step 4: Model + factory + enum + `validateMovement`**

- Model mengikuti idiom repo (attribute `#[Fillable(...)]`, method `casts()`): `Recipe` fillable `['recipeable_type','recipeable_id']`; `RecipeItem` fillable `['recipe_id','ingredient_id','quantity']`, cast quantity integer; `UnitConversion` fillable `['from_unit_id','to_unit_id','from_quantity','to_quantity']`, cast ketiganya integer.
- `StockMovementType`: tambah dua case + label Bahasa Indonesia (`'Konsumsi pesanan'`, `'Pembalikan konsumsi'`); jangan ubah `requiresOwner()`.
- `RecordStockMovement::validateMovement`: tambah aturan sign per tipe baru: `Consumption` harus qty `< 0`, `ConsumptionReversal` harus qty `> 0` (pola pesan sama dengan aturan `Waste` yang ada).

- [ ] **Step 5: Jalankan test**

Run: `php artisan test --filter=RecipeSchemaTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Modules/Inventory database/factories tests/Feature/RecipeSchemaTest.php
git commit -m "feat: add recipe and unit conversion schema"
```

---

### Task 2: Service konversi satuan

**Files:**
- Create: `app/Modules/Inventory/Actions/ConvertUnit.php`
- Test: `tests/Feature/UnitConversionTest.php`

**Interfaces:**
- Consumes: `UnitConversion` (Task 1), `Unit`.
- Produces: `ConvertUnit::__invoke(int $fromUnitId, int $quantity, int $toUnitId): int` — jumlah integer di unit tujuan.

- [ ] **Step 1: Tulis test gagal**

`tests/Feature/UnitConversionTest.php` (DatabaseMigrations). Skenario: buat unit `g`, `kg`, `pcs` + konversi `1 kg = 1000 g`.
- `convert(1 kg, 3) → g = 3000`.
- `convert(500, g → kg) = 0` (integer, floor).
- `convert(x, u → u) = x` (unit sama).
- `convert(x, g → pcs)` tanpa jalur → `ValidationException`.
- Shortcut (catat di kode): `// shortcut: pembulatan floor pada hasil non-eksak; upgrade saat lot/expiry butuh qty fraksional.`

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=UnitConversionTest`
Expected: FAIL (class belum ada).

- [ ] **Step 3: Implementasikan `ConvertUnit`**

BFS dari `$fromUnitId` ke `$toUnitId` di atas `UnitConversion` (sisi): faktor = `to_quantity / from_quantity`. Akumulasi sebagai bilangan bulat: `result = intdiv(result * to_quantity, from_quantity)` per sisi. Jalur sama unit → return apa adanya. Tidak ada jalur → `ValidationException::withMessages(['unit' => 'Unit tidak dapat dikonversi.'])`.

- [ ] **Step 4: Jalankan test**

Run: `php artisan test --filter=UnitConversionTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Inventory/Actions/ConvertUnit.php tests/Feature/UnitConversionTest.php
git commit -m "feat: add unit conversion service"
```

---

### Task 3: Resolver kebutuhan bahan (BOM)

**Files:**
- Create: `app/Modules/Inventory/Actions/ResolveOrderRequirements.php`
- Test: `tests/Feature/RecipeRequirementTest.php`

**Interfaces:**
- Consumes: `Order` dengan relasi `items.modifiers` (Eksisting: `OrderItem.product_id`, `variant_id`, `quantity`; `OrderItemModifier.modifier_id`), `Recipe::query()->where('recipeable_type', ...)->with('items.ingredient')`.
- Produces: `ResolveOrderRequirements::__invoke(Order $order): Collection<int, int>` — kunci = `ingredient_id`, nilai = total qty unit dasar. Item tanpa resep tidak termasuk.

- [ ] **Step 1: Tulis test gagal**

`tests/Feature/RecipeRequirementTest.php` (DatabaseMigrations, gunakan `Order::factory()` + `OrderItem::factory()` + factory modifier yang ada, pola `tests/Feature/OrderCreationTest.php`). Skenario produk `P`, varian `V`, bahan `A`(kg), `B`(unit dasar), modifier `M`:
- Order 1 × `P` (tanpa varian), resep product `P`: 2 A → hasil `A => 2`.
- Order qty 3, resep varian `V`: 1 A + 2 B, resep modifier `M`: 1 B, item memakai `M` qty 3 → `A => 3, B => 9` (1×3 + 2×3).
- Variant `V` memiliki resep, resep product `P` juga ada → varian menang (2 A + 4 B per unit).
- Produk tanpa resep apa pun → `Collection` kosong.
- Modifier dengan resep tapi produk tanpa resep varian/product → modifier tetap dihitung (2 B per unit qty 2 → 4 B).

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=RecipeRequirementTest`
Expected: FAIL.

- [ ] **Step 3: Implementasikan `ResolveOrderRequirements`**

Kumpulkan key target per item: `Product[id]` (dan `ProductVariant[id]` jika ada), `Modifier[id]` dari `order->items->modifiers`. Satu query `Recipe` dengan `whereIn` pada tuple type+id, `with('items')`. Per item baris: `base = recipe(variant) ?? recipe(product)`; total per bahan = `base.quantity × item.quantity + Σ modifier.recipe.items.quantity × item.quantity`. Akumulasi ke `Collection<int,int>`; buang nilai 0.

- [ ] **Step 4: Jalankan test**

Run: `php artisan test --filter=RecipeRequirementTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Inventory/Actions/ResolveOrderRequirements.php tests/Feature/RecipeRequirementTest.php
git commit -m "feat: resolve order ingredient requirements from recipes"
```

---

### Task 4: Konsumsi saat `preparing` + pembalikan saat void

**Files:**
- Create: `config/inventory.php`
- Create: `app/Modules/Inventory/Actions/ConsumeOrderIngredients.php`
- Create: `app/Modules/Inventory/Actions/ReverseOrderConsumption.php`
- Modify: `app/Modules/Kitchen/Actions/TransitionOrderStatus.php`
- Modify: `app/Modules/Ordering/Actions/VoidOrder.php`
- Test: `tests/Feature/OrderConsumptionTest.php` (baru)
- Modify: `tests/Feature/KitchenTest.php` (jika asumsi stok lama bergantung pada tidak ada konsumsi)

**Interfaces:**
- Consumes: `ResolveOrderRequirements` (Task 3); `Order.lockForUpdate` (Eksisting di kedua caller); `StockBalance` (lock `lockForUpdate()` + cek `< 0`, pola `RecordStockMovement`).
- Produces:
  - `config/inventory.php` => `['deduct_on' => 'preparing']`.
  - `ConsumeOrderIngredients::__invoke(Order $order, User $actor): void` — menulis 1 movement `consumption` (qty negatif) per bahan yang dibutuhkan; `ValidationException` jika saldo habis (nama bahan di pesan).
  - `ReverseOrderConsumption::__invoke(Order $order, User $actor): void` — per movement `consumption` milik order, tulis `consumption_reversal` qty positif + saldo naik.

- [ ] **Step 1: Tulis test gagal** (`tests/Feature/OrderConsumptionTest.php`)

Skenario utama (buat branch, owner, cashier, kitchen user, order paid, setiap skenario):
1. **Start consumes**: PATCH `/kitchen/orders/{id}/status` `{status: preparing}` → status `preparing`; 1 movement `consumption` per bahan (qty negatif, `reference_type/id` = order, `actor_id` = kitchen user); saldo turun; balance `lockForUpdate` tidak boleh negatif.
2. **No recipe** → status `preparing` OK, nol movement baru.
3. **Cancel queued unpaid** → status `cancelled`, nol movement `consumption`.
4. **Void setelah preparing** (order paid+status preparing/ready/completed di-void via POS route) → movement `consumption_reversal` baru, saldo kembali ke sebelum, movement `consumption` asli tetap ada, order `cancelled` + payment `voided`.
5. **Void paid queued** (belum preparing) → nol movement pembalikan.
6. **Stok kurang**: saldo = kebutuhan − 1; PATCH preparing → 422, pesan menyebut nama bahan; order tetap `accepted`; saldo dan movement tidak berubah.
7. **Dobel**: setelah preparing, PATCH `preparing` lagi → 422; jumlah movement `consumption` tetap 1.
8. **Konkurensi** (pola `StockConcurrencyTest`, `pcntl_fork`): saldo bahan = 8, 2 order berbeda masing-masing butuh 5; fork 2 proses, masing-masing memanggil `TransitionOrderStatus` (aksi langsung, bukan HTTP) untuk satu order; assert tepat 1 proses sukses, saldo akhir = 3, `consumption` count = 1, satu proses gagal dengan `ValidationException` (exit non-zero).

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=OrderConsumptionTest`
Expected: FAIL (konsumsi belum dipanggil).

- [ ] **Step 3: Implementasikan**

- `config/inventory.php`: `return ['deduct_on' => 'preparing'];`
- `ConsumeOrderIngredients`: `requirements = ($this->resolve)($order->loadMissing(['items.modifiers']))`; per bahan: `insertOrIgnore` baris balance (pola `RecordStockMovement`), `lockForUpdate`, `after = before − qty`, jika `after < 0` → `ValidationException::withMessages(['stock' => "Stok {$ingredient->name} tidak cukup untuk memulai pesanan."])`; update balance; `StockMovement::create` type `Consumption`, qty `−qty`, `unit_id = ingredient->base_unit_id`, `reason = "Konsumsi pesanan {$order->order_number}"`, `reference_type = Order::class`, `reference_id = order->id`, `actor_id = actor->id`.
- `ReverseOrderConsumption`: ambil `StockMovement` dimana `reference_type = Order::class`, `reference_id = order->id`, `type = consumption`; per bahan: balance `lockForUpdate`, tambah qty kembali, `StockMovement::create` type `ConsumptionReversal` qty positif, `reason = "Pembalikan konsumsi pesanan {$order->order_number} (void)"`, reference order, `actor_id = actor->id`.
- `TransitionOrderStatus`: constructor-inject `ConsumeOrderIngredients` (ikuti pola injeksi action lain di repo); setelah `$locked->save()`, jika `config('inventory.deduct_on', 'preparing') === 'preparing' && $next === OrderStatus::Preparing` → panggil action (di dalam transaksi yang sama).
- `VoidOrder`: constructor-inject `ReverseOrderConsumption`; setelah update status, jika `in_array($from, [OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Completed], true) && config('inventory.deduct_on', 'preparing') === 'preparing'` → panggil action.
- `KitchenTest`: perbaiki asumsi bila ada (sekarang preparing tidak mengubah stok); test gagal di step 8 ikut memverifikasi.

- [ ] **Step 4: Jalankan test**

Run: `php artisan test --filter="OrderConsumptionTest|KitchenTest|VoidOrderTest"`
Expected: PASS.

- [ ] **Step 5: Verifikasi UI kitchen menampilkan error stok kurang**

Buka `resources/js/pages/kitchen/Index.vue`: pastikan fail transaksi 422 sudah memunculkan pesan server (`form.errors` / toast). Jika belum, sambungkan pesan tersebut ke pola error yang sudah ada di halaman (bukan library baru). Commit terpisah bila ada perubahan UI.

- [ ] **Step 6: Commit**

```bash
git add config/inventory.php app/Modules/Inventory/Actions app/Modules/Kitchen/Actions app/Modules/Ordering/Actions tests
git commit -m "feat: consume stock on preparing, reverse on void"
```

---

### Task 5: `inventory:reconcile`

**Files:**
- Create: `app/Console/Commands/InventoryReconcile.php`
- Test: `tests/Feature/InventoryReconcileTest.php`

**Interfaces:**
- Consumes: `stock_balances`, `stock_movements` (ledger), `WriteAuditLog` (actor boleh NULL, mensikapi `audit_logs` migration `000005`).
- Produces: artisan `inventory:reconcile {--fix}`. Exit 0 jika bersih (atau sudah diperbaiki), exit 1 jika masih ada selisih.

- [ ] **Step 1: Tulis test gagal**

`tests/Feature/InventoryReconcileTest.php`:
- State bersih (movement konsisten dengan saldo) → `artisan('inventory:reconcile')` exit 0, output menyebut "tidak ada selisih".
- Rusak saldo manual: `DB::table('stock_balances')->whereKey(...)->update(['quantity' => qty + 5])` → exit 1, output menyebut branch + bahan + selisih.
- Dengan `--fix` → exit 0, saldo kembali ke jumlah ledger, 1 `audit_logs` baris `stock.reconciled` dengan `actor_id` NULL.

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=InventoryReconcileTest`
Expected: FAIL (command belum terdaftar).

- [ ] **Step 3: Implementasikan `InventoryReconcile`**

Signature `inventory:reconcile`, option `--fix`. Hitung `SUM(quantity)` per `(branch_id, ingredient_id)` di `stock_movements`; gabung dengan `stock_balances` (baris saldo tanpa movement = 0). Salisih → tabel console (branch, bahan, saldo, ledger, delta). `--fix`: `balance->update(['quantity' => ledger])` + `app(WriteAuditLog::class)(null, 'stock.reconciled', $balance, ['quantity' => saldo], ['quantity' => ledger], 'inventory:reconcile --fix')`. Return `Command::FAILURE` jika selisih sisa, tidak `Command::SUCCESS`.

- [ ] **Step 4: Jalankan test**

Run: `php artisan test --filter=InventoryReconcileTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/InventoryReconcile.php tests/Feature/InventoryReconcileTest.php
git commit -m "feat: add inventory reconcile command"
```

---

### Task 6: UI admin — Resep

**Files:**
- Create: `app/Modules/Inventory/Http/Controllers/RecipeController.php`
- Create: `app/Http/Requests/StoreRecipeRequest.php`
- Create: `app/Http/Requests/UpdateRecipeRequest.php`
- Create: `resources/js/pages/admin/stock/Recipes.vue`
- Modify: `routes/web.php` (grup `admin/stock`, middleware owner)
- Test: `tests/Feature/RecipeAdminTest.php`

**Interfaces:**
- Consumes: `Recipe`/`RecipeItem` (Task 1), `Product`/`ProductVariant`/`Modifier` (Eksisting), `EmptyState.vue`, `ConfirmDialog.vue`, `Button/Input/Select` (Eksisting), `WriteAuditLog`.
- Produces (route, mengikuti bentuk `/admin/stock/*` yang ada):
  - `GET  /admin/stock/recipes` → Inertia `admin/stock/Recipes`
  - `POST /admin/stock/recipes` — body: `recipeable_type` (`product|product_variant|modifier`), `recipeable_id`, `items[] {ingredient_id, quantity}`
  - `PATCH /admin/stock/recipes/{recipe}` — body `items[]` yang sama (pengganti total isi item)
  - `DELETE /admin/stock/recipes/{recipe}`
- Props halaman: `products` (id, name, variants), `modifiers` (id, name), `recipes` (id, target label, items dengan label `ingredient.name + unit.code`).

- [ ] **Step 1: Tulis test gagal**

`tests/Feature/RecipeAdminTest.php` (DatabaseMigrations, pola `CatalogTest.php`):
- Cashier → `GET /admin/stock/recipes` 403.
- Owner → 200 + halaman `admin/stock/Recipes` dengan 0 `recipes` menghasilkan empty state (test Inertia `assertInertia`).
- Store payload valid (produk + 2 item) → 302, `recipes` ada 1, `recipe_items` ada 2, audit `recipe.created` ada.
- Store duplikat target → 422 (pesan "sudah memiliki resep").
- Patch `items` → item lama hilang, baru masuk, audit `recipe.updated` before/after berisi item.
- Delete → recipe + items hilang, audit `recipe.deleted`.
- Validation: `quantity` 0 → 422; `ingredient_id` tidak ada → 422.

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=RecipeAdminTest`
Expected: FAIL.

- [ ] **Step 3: Implementasikan controller + requests**

- Route di grup owner `admin/stock`; tambahkan untuk recipe: `GET <index>`, `POST <store>`, `PATCH {recipe} <update>`, `DELETE {recipe} <destroy>`.
- `StoreRecipeRequest`/`UpdateRecipeRequest`: `recipeable_type` in:`product,product_variant,modifier`, `recipeable_id` required integer, `items` required array min:1, `items.*.ingredient_id` exists, `items.*.quantity` integer min:1. validasi target sesuai type (belongs to product/variant/modifier). Nilai `product|product_variant|modifier` hanya untuk request; di DB disimpan model class FQCN (`Product::class`, `ProductVariant::class`, `Modifier::class`) — pemetaan dilakukan di controller.
- `RecipeController`:
  - `index`: props sesuai Interfaces. (label unit bahan dari `baseUnit.code` + `name`).
  - `store`: checkpoint duplikat `Recipe::where('recipeable_type',...)->where('recipeable_id',...)` → `ValidationException` "Target sudah memiliki resep."; buat recipe + items (transaction); audit `recipe.created` (after: daftar item `nama+qty`).
  - `update`: simpan snapshot before, hapus `recipe->items`, buat ulang dari `items[]`; audit `recipe.updated` before/after.
  - `destroy`: simpan after snapshot, hapus; audit `recipe.deleted`.

- [ ] **Step 4: Implementasikan `Recipes.vue`**

Struktur mengikuti `admin/stock/Ingredients.vue` (kartu `rounded-2xl border border-line bg-white`, form `useForm`, tabel daftar):
- Section 1 (form "Tambah resep"): Select target jenis (Produk/Varian/Modifier) + Select target + dynamic rows item (Select bahan + number qty + label unit bahan tampil di samping input — anti salah satuan) + tombol "Tambah baris" + CTA "Simpan resep". Validation error per field (`form.errors.items_0_ingredient_id` dst. mapping ke row).
- Section 2 (daftar resep): tabel per resep (target label, ringkasan item), aksi Ubah (populate form) + Hapus (panggil `ConfirmDialog` dengan pesan "Resep akan dihapus; pesanan lama tidak berubah, pesanan baru akan berhenti menguras bahan."). Empty state bila kosong.
- State: loading via `form.processing`, success flash, error server dari Inertia (`usePage().props.error`) sudah ada di StaffLayout, pastikan tampil.
- Anti-slop (DESIGN staff register): tanpa ikon dekoratif, warna semantik untuk error saja, label Bahasa Indonesia, semua CTA spesifik.

- [ ] **Step 5: Jalankan test + verifikasi manual minimal**

Run: `php artisan test --filter=RecipeAdminTest && npm run typecheck && npm run lint && npm run build`
Expected: semua PASS. Verifikasi: di browser (`npm run dev`) buka `/admin/stock/recipes` sebagai owner: empty state → tambah resep produk 2 item → muncul di tabel → edit qty → hapus (konfirmasi muncul).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Inventory/Http/Controllers/RecipeController.php app/Http/Requests resources/js/pages/admin/stock/Recipes.vue routes/web.php tests/Feature/RecipeAdminTest.php
git commit -m "feat: add recipe admin UI"
```

---

### Task 7: UI admin — Satuan & konversi

**Files:**
- Create: `app/Modules/Inventory/Http/Controllers/UnitConversionController.php`
- Create: `app/Http/Requests/StoreUnitRequest.php`
- Create: `app/Http/Requests/StoreUnitConversionRequest.php`
- Create: `resources/js/pages/admin/stock/Units.vue`
- Modify: `routes/web.php` (grup `admin/stock`)
- Test: `tests/Feature/UnitAdminTest.php`

**Interfaces:**
- Consumes: `Unit`/`UnitConversion` (Task 1). `ConvertUnit` (Task 2) tetap tersedia di kode untuk verifikasi manual, tidak dipakai controller.
- Produces (route, grup owner `admin/stock`):
  - `GET    /admin/stock/units`
  - `POST   /admin/stock/units` (body: `name`, `code`)
  - `PATCH  /admin/stock/units/{unit}` (body: `name` only)
  - `DELETE /admin/stock/units/{unit}` — 422 jika dipakai bahan/movement/konversi ("Satuan sudah terpakai.")
  - `POST   /admin/stock/unit-conversions` (body: `from_unit_id`, `to_unit_id`, `from_quantity`, `to_quantity`)
  - `DELETE /admin/stock/unit-conversions/{unitConversion}`

- [ ] **Step 1: Tulis test gagal**

`tests/Feature/UnitAdminTest.php`:
- Cashier → 403; owner → 200 props `units` + `conversions`.
- Store unit valid → 302, baris ada. Store `code` duplikat → 422.
- Store konversi valid → 302. `from == to` → 422. `to→from` yang sudah ada (mis. ada `g→kg` lalu kirim `kg→g`) → 422 "Konversi arah ini sudah ada." qty 0/negatif → 422.
- Delete unit terpakai (dipakai `ingredients`, `stock_movements`, atau `unit_conversions`) → 422 "Satuan sudah terpakai."; delete unit bersih → 302.
- Delete konversi → 302.

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=UnitAdminTest`
Expected: FAIL.

- [ ] **Step 3: Implementasikan controller + requests**

- `StoreUnitRequest`: `name` required string, `code` required string unique:units,code.
- `StoreUnitConversionRequest`: dua `exists:units,id`, `from_unit_id != to_unit_id` (custom rule), `from_quantity`/`to_quantity` integer min:1, cek duplikat arah `unique:unit_conversions,from_unit_id WHERE to_unit_id=...` & arah balik juga 422.
- `UnitConversionController`: `index` (props: `units` id/code/name, `conversions` with unit label); `store/destroy` unit dengan guard pemakaian (cek `ingredients.base_unit_id`, `stock_movements.unit_id`, `unit_conversions.from/to`); `store/destroy` konversi.

- [ ] **Step 4: Implementasikan `Units.vue`**

Dua kartu berurutan (pola `Ingredients.vue`): (1) "Tambah satuan" (name + code) + tabel satuan; (2) "Tambah konversi" (from, to, qty from, qty to, contoh hasil "1 kg = 1000 g") + tabel konversi dengan aksi hapus. Empty state, `form.processing` disable, error Inertia tampil. CTA spesifik: "Simpan satuan", "Simpan konversi".

- [ ] **Step 5: Jalankan test + build**

Run: `php artisan test --filter=UnitAdminTest && npm run typecheck && npm run lint && npm run build`
Expected: semua PASS. Verifikasi manual: tambah unit `ml` + konversi `g↔ml` placeholder (bila bahan cair), hapus satuan terpakai → pesan guard.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Inventory/Http/Controllers/UnitConversionController.php app/Http/Requests resources/js/pages/admin/stock/Units.vue routes/web.php tests/Feature/UnitAdminTest.php
git commit -m "feat: add unit and conversion admin UI"
```

---

## Catatan penutup (bukan task)

- Update `AGENTS.md` bagian "Repository layout / Commands" bila command baru perlu didokumentasikan (`inventory:reconcile`); lakukan di commit Task 5 bila file itu mention command lain.
- Open item untuk owner (catat di spec, jangan implementasi): override negative stock dengan permission + audit; diskon/pajak (fitur #4); event potong untuk scheduled pickup bila alur itu diverifikasi.
- Spec mengizinkan `deduct_on` nilai lain di masa depan (`paid`, `completed`): untuk nilai selain `preparing`, konsumsi/pembalikan tidak dilakukan (documented no-op sampai hook ditambahkan).
