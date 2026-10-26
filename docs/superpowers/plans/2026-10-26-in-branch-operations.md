# Pizza Fruzta In-Branch Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the first release: phone-friendly POS, kitchen display, manual stock ledger, daily reports, branch-scoped roles, and audit for two Pizza Fruzta branches.

**Architecture:** Laravel 13 modular monolith with Inertia and Vue 3. Business logic lives in module actions under `app/Modules`. Controllers validate, authorize, call one action, and return an Inertia or JSON response. PostgreSQL is the only database. Kitchen updates poll every 5 seconds. No Redis, no WebSockets, no API version in this release.

**Tech Stack:** PHP 8.5, Laravel 13.x, PostgreSQL 18, Vue 3, TypeScript, Inertia.js, Tailwind CSS, Vite, PHPUnit, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-26-in-branch-operations-design.md`

## Global Constraints

- Money is integer rupiah. No floats anywhere in prices, totals, payments, or reports.
- The server recalculates every price and total. Client-supplied prices are ignored.
- Order status and payment status are separate state machines.
- Order creation is idempotent per branch. Payment confirmation is idempotent per order.
- Stock changes are append-only `stock_movements` rows. Balances are cached and reconcilable from the ledger. Negative balances are rejected.
- Every protected action checks role and branch on the server. UI hiding is never the control.
- Sensitive actions write an audit entry: actor, action, target, safe before/after, reason, timestamp.
- Timestamps are stored in UTC and displayed in `Asia/Jakarta`.
- UI copy is Bahasa Indonesia. Currency format is `Rp 85.000`.
- Brand green is `#056A4D`. Yellow and orange are provisional from the supplied logo image. Do not invent a logo, mascot, or photography.
- Do not add a major dependency without approval. Allowed dev tools: PHPUnit, Vitest.
- Run `php artisan test`, `npm run typecheck`, `npm run lint`, and `npm run build` before declaring a task done.
- Commit after every task with a small, focused message.

## Review Focus

1. **Duplicate checkout on retry or double tap.** The user expects one order. Task 4 pins this with a same-key retry test that asserts one row and the same order ID.
2. **Client price or branch manipulation.** The user expects the server to win. Task 4 pins this with tests that send a fake price and a foreign branch ID and assert the server total and branch.
3. **Unpaid or voided order reaching the kitchen.** Kitchen staff must only work on paid, active orders. Task 6 pins this with branch and payment-state queue tests.
4. **Concurrent stock movements losing an update.** Two simultaneous waste entries must both land. Task 7 pins this with a forked-process test that asserts the final balance and movement count.
5. **Sensitive action with no audit or cross-branch access.** Void, cancel, stock adjustment, and staff changes must be attributable. Tasks 2, 5, and 7 pin this with audit assertions and foreign-branch rejection tests.

---

## Phase 1: Foundation

### Task 1: Scaffold the Laravel app, PostgreSQL, and health check

**Files:**
- Create: `composer.json`, `package.json`, `vite.config.ts`, `resources/js/app.ts`, `resources/views/app.blade.php`, `routes/web.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `phpunit.xml`, `.env.example`, `.env.testing`, `tests/Feature/HealthTest.php`
- Modify: `.gitignore`, `bootstrap/app.php`
- Test: `tests/Feature/HealthTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: a running Laravel 13 app with Inertia, Vue, TypeScript, Tailwind, PostgreSQL, and a `GET /health` JSON endpoint returning `{"status":"ok"}`.

- [ ] **Step 1: Initialize git and commit the existing docs**

```bash
cd /home/chizmoonn/Downloads/coding/pizza-fruzta
git init
git add AGENTS.md PRD.md DESIGN.md docs/
git commit -m "docs: add product, agent, design, and first-release design docs"
```

- [ ] **Step 2: Scaffold Laravel 13 with the Vue starter kit and no auth pages**

```bash
laravel new /tmp/pizza-fruzta-scaffold --vue --no-authentication --database=pgsql --phpunit --no-boost --no-interaction
```

If the installer fails, use `composer create-project laravel/laravel:^13.0 /tmp/pizza-fruzta-scaffold` and add Inertia, Vue, TypeScript, and Tailwind manually. Record the fallback in the commit message.

- [ ] **Step 3: Copy the scaffold into the repository without overwriting project docs**

```bash
rsync -a --exclude .git --exclude .env /tmp/pizza-fruzta-scaffold/ ./
composer install
npm install
```

Keep the starter kit's `lint` and `typecheck` scripts. If either is missing, add ESLint with the Vue TypeScript config and `vue-tsc --noEmit` before continuing.

- [ ] **Step 4: Create the databases and configure the environment**

Run these once with a PostgreSQL superuser, then fill `.env` and `.env.testing` with the same credentials:

```sql
CREATE ROLE pizza_fruzta WITH LOGIN PASSWORD 'change-me';
CREATE DATABASE pizza_fruzta OWNER pizza_fruzta;
CREATE DATABASE pizza_fruzta_test OWNER pizza_fruzta;
```

`.env` and `.env.testing` must set:

```dotenv
APP_TIMEZONE=Asia/Jakarta
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pizza_fruzta
DB_USERNAME=pizza_fruzta
DB_PASSWORD=change-me
```

`.env.testing` sets `DB_DATABASE=pizza_fruzta_test` and `APP_ENV=testing`.

- [ ] **Step 5: Write the failing health test**

```php
// tests/Feature/HealthTest.php
namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }
}
```

- [ ] **Step 6: Run the test to verify it fails**

Run: `php artisan test --filter=HealthTest`
Expected: FAIL with 404.

- [ ] **Step 7: Add the health route**

```php
// routes/web.php
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));
```

- [ ] **Step 8: Run the test and the frontend build**

Run: `php artisan test --filter=HealthTest && npm run build`
Expected: PASS and a successful Vite build.

- [ ] **Step 9: Commit**

```bash
git add .
git commit -m "chore: scaffold Laravel, Inertia, Vue, TypeScript, and PostgreSQL"
```

---

### Task 2: Identity, branches, roles, audit, and base UI

**Files:**
- Create: `app/Modules/Identity/Enums/Role.php`, `app/Modules/Identity/Actions/WriteAuditLog.php`, `app/Modules/Audit/Models/AuditLog.php`
- Create: `app/Modules/Branches/Models/Branch.php`, `app/Modules/Branches/Http/Controllers/BranchController.php`
- Create: `app/Http/Controllers/Auth/LoginController.php`, `app/Http/Controllers/Admin/StaffController.php`
- Create: `app/Http/Requests/LoginRequest.php`, `app/Http/Requests/StoreStaffRequest.php`, `app/Http/Requests/UpdateStaffRequest.php`
- Create: `app/Http/Middleware/EnsureRole.php`
- Create: `app/Policies/UserPolicy.php`, `app/Policies/BranchPolicy.php`
- Create: migrations for `branches`, `audit_logs`, and the `users` role/branch/active columns
- Create: `database/factories/BranchFactory.php`, modify `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php`
- Create: `resources/js/layouts/StaffLayout.vue`, `resources/js/components/Button.vue`, `Input.vue`, `Select.vue`, `Badge.vue`, `Money.vue`, `EmptyState.vue`, `ConfirmDialog.vue`
- Create: `resources/js/pages/auth/Login.vue`, `resources/js/pages/admin/staff/Index.vue`, `resources/js/pages/admin/branches/Index.vue`
- Modify: `app/Models/User.php`, `bootstrap/app.php`, `routes/web.php`, `resources/css/app.css`
- Test: `tests/Feature/AuthTest.php`, `tests/Feature/StaffAccessTest.php`, `tests/Feature/AuditLogTest.php`

**Interfaces:**
- Consumes: `GET /health`, Inertia root, base layout.
- Produces:
  - `Role` enum values `Owner`, `Cashier`, `Kitchen`, with `label(): string` returning `Pemilik`, `Kasir`, `Dapur`.
  - `User::role(): Role`, `User::isOwner(): bool`, `User::hasBranchAccess(int $branchId): bool`.
  - `WriteAuditLog::__invoke(User $actor, string $action, Model $target, array $before = [], array $after = [], ?string $reason = null): AuditLog`.
  - `EnsureRole` middleware registered as `role`.
  - Routes: `GET/POST /login`, `POST /logout`, owner-only `/admin/staff` and `/admin/branches`.
  - Seeded owner, one cashier and one kitchen user per branch, two branches `PF-01` and `PF-02`.
  - Base components and tokens.

- [ ] **Step 1: Write the failing auth and access tests**

```php
// tests/Feature/AuthTest.php
public function test_active_owner_can_login(): void
{
    $owner = User::factory()->owner()->create(['password' => bcrypt('secret123')]);
    $this->post('/login', ['email' => $owner->email, 'password' => 'secret123'])
        ->assertRedirect('/admin');
    $this->assertAuthenticatedAs($owner);
}

public function test_inactive_user_cannot_login(): void
{
    $user = User::factory()->cashier()->create([
        'password' => bcrypt('secret123'),
        'is_active' => false,
    ]);
    $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
}
```

```php
// tests/Feature/StaffAccessTest.php
public function test_cashier_cannot_open_staff_admin(): void
{
    $cashier = User::factory()->cashier()->create();
    $this->actingAs($cashier)->get('/admin/staff')->assertForbidden();
}

public function test_owner_can_deactivate_staff_without_deleting(): void
{
    $owner = User::factory()->owner()->create();
    $cashier = User::factory()->cashier()->create();
    $this->actingAs($owner)
        ->patch("/admin/staff/{$cashier->id}", ['is_active' => false])
        ->assertRedirect();
    $this->assertDatabaseHas('users', ['id' => $cashier->id, 'is_active' => false]);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter='AuthTest|StaffAccessTest'`
Expected: FAIL, classes or routes missing.

- [ ] **Step 3: Implement the schema, models, middleware, and login redirect**

Migrations:

```php
Schema::create('branches', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('code')->unique();
    $table->text('address')->nullable();
    $table->string('phone')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});

Schema::table('users', function (Blueprint $table) {
    $table->string('role')->default('cashier')->index();
    $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
    $table->boolean('is_active')->default(true)->index();
});

Schema::create('audit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
    $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
    $table->string('action');
    $table->string('target_type');
    $table->unsignedBigInteger('target_id');
    $table->jsonb('before')->nullable();
    $table->jsonb('after')->nullable();
    $table->text('reason')->nullable();
    $table->string('ip')->nullable();
    $table->text('user_agent')->nullable();
    $table->timestamp('created_at')->useCurrent();
});
```

`User` casts `role` to `Role`, `is_active` to boolean. `isOwner()` returns `role === Role::Owner`. `hasBranchAccess()` returns `isOwner() || branch_id === $branchId`.

Login redirect: owner to `/admin`, cashier to `/pos`, kitchen to `/kitchen`.

`EnsureRole`:

```php
public function handle(Request $request, Closure $next, string ...$roles): Response
{
    abort_unless($request->user() && in_array($request->user()->role->value, $roles, true), 403);
    return $next($request);
}
```

Register in `bootstrap/app.php` as `role`.

- [ ] **Step 4: Write the failing audit test**

```php
// tests/Feature/AuditLogTest.php
public function test_audit_entry_records_actor_action_target_and_reason(): void
{
    $owner = User::factory()->owner()->create();
    $cashier = User::factory()->cashier()->create();
    $log = app(WriteAuditLog::class)(
        $owner,
        'staff.deactivated',
        $cashier,
        ['is_active' => true],
        ['is_active' => false],
        'Tidak masuk tanpa kabar'
    );
    $this->assertDatabaseHas('audit_logs', [
        'id' => $log->id,
        'actor_id' => $owner->id,
        'action' => 'staff.deactivated',
        'target_id' => $cashier->id,
        'reason' => 'Tidak masuk tanpa kabar',
    ]);
}
```

- [ ] **Step 5: Run the audit test to verify it fails**

Run: `php artisan test --filter=AuditLogTest`
Expected: FAIL.

- [ ] **Step 6: Implement `WriteAuditLog` and the base components**

`WriteAuditLog` stores `target_type` as `$target::class`, never stores passwords or tokens, and filters `before`/`after` through the caller. Staff create, update, deactivate, and password reset call it.

Base tokens in `resources/css/app.css`:

```css
@theme {
  --color-brand: #056A4D;
  --color-brand-yellow: #FFC72C;
  --color-brand-orange: #F47B20;
  --color-ink: #1F2421;
  --color-muted: #5F6B65;
  --color-line: #D8DED9;
  --color-canvas: #F4F6F4;
  --color-danger: #B3261E;
  --color-warning: #9A5B00;
  --color-success: #1E6B3A;
  --color-info: #1F5FA8;
}
```

Components consume tokens only. `Button` supports `primary`, `secondary`, `danger`, and `disabled` states with a 48px minimum height. `Money` formats integer rupiah with `Intl.NumberFormat('id-ID')`. `Badge` always renders text plus color.

- [ ] **Step 7: Run the tests and build**

Run: `php artisan test --filter='AuthTest|StaffAccessTest|AuditLogTest' && npm run build`
Expected: PASS and build success.

- [ ] **Step 8: Commit**

```bash
git add .
git commit -m "feat: add staff roles, branch access, audit log, and base UI"
```

---

## Phase 2: Catalog and ordering

### Task 3: Catalog schema and admin

**Files:**
- Create: migrations for `categories`, `products`, `product_variants`, `modifier_groups`, `modifiers`, `product_modifier_groups`, `product_branch_availability`
- Create: `app/Modules/Catalog/Models/Category.php`, `Product.php`, `ProductVariant.php`, `ModifierGroup.php`, `Modifier.php`
- Create: `app/Modules/Catalog/Http/Controllers/CategoryController.php`, `ProductController.php`, `ModifierGroupController.php`
- Create: requests for store and update of each resource
- Create: `database/factories/CategoryFactory.php`, `ProductFactory.php`, `ProductVariantFactory.php`, `ModifierGroupFactory.php`, `ModifierFactory.php`
- Create: `resources/js/pages/admin/catalog/Categories.vue`, `Products.vue`, `ProductEdit.vue`, `ModifierGroups.vue`
- Modify: `routes/web.php`, `resources/js/layouts/StaffLayout.vue`
- Test: `tests/Feature/CatalogTest.php`

**Interfaces:**
- Consumes: `Role`, `Branch`, base components, `WriteAuditLog`.
- Produces:
  - `Product` relationships: `category()`, `variants()`, `modifierGroups()`, `branches()`.
  - `Product::isAvailableAt(int $branchId): bool`.
  - `ProductVariant` fields: `name`, `price` integer, `sort_order`, `is_active`.
  - `Modifier` fields: `name`, `price_delta` integer, `sort_order`, `is_active`.
  - Admin CRUD routes under `/admin/catalog`: categories, products, and modifier groups.

- [ ] **Step 1: Write the failing catalog tests**

```php
// tests/Feature/CatalogTest.php
public function test_owner_can_create_product_with_variant_and_modifier_group(): void
{
    $owner = User::factory()->owner()->create();
    $category = Category::factory()->create();
    $group = ModifierGroup::factory()->create(['name' => 'Crust', 'min_select' => 1, 'max_select' => 1]);

    $this->actingAs($owner)->post('/admin/catalog/products', [
        'category_id' => $category->id,
        'name' => 'Pizza Margherita',
        'base_price' => 50000,
        'variants' => [
            ['name' => 'Regular', 'price' => 50000],
            ['name' => 'Large', 'price' => 75000],
        ],
        'modifier_group_ids' => [$group->id],
    ])->assertRedirect();

    $product = Product::where('name', 'Pizza Margherita')->firstOrFail();
    $this->assertSame(2, $product->variants()->count());
    $this->assertTrue($product->modifierGroups->contains($group));
}

public function test_cashier_cannot_manage_catalog(): void
{
    $cashier = User::factory()->cashier()->create();
    $this->actingAs($cashier)->get('/admin/catalog/products')->assertForbidden();
}

public function test_product_availability_is_branch_specific(): void
{
    $product = Product::factory()->create();
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    $product->branches()->attach($branchA, ['is_available' => true]);

    $this->assertTrue($product->isAvailableAt($branchA->id));
    $this->assertFalse($product->isAvailableAt($branchB->id));
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=CatalogTest`
Expected: FAIL.

- [ ] **Step 3: Implement the catalog migrations and models**

Required columns:

```text
categories: id, name, sort_order, is_active, timestamps
products: id, category_id, name, description, base_price integer, is_active, timestamps
product_variants: id, product_id, name, price integer, sort_order, is_active, timestamps
modifier_groups: id, name, min_select, max_select, is_required, sort_order, timestamps
modifiers: id, modifier_group_id, name, price_delta integer, sort_order, is_active, timestamps
product_modifier_groups: id, product_id, modifier_group_id, sort_order, unique(product_id, modifier_group_id)
product_branch_availability: id, product_id, branch_id, is_available, unique(product_id, branch_id)
```

All money columns are integers. Validate `base_price >= 0`, `price >= 0`, and modifier selection bounds.

- [ ] **Step 4: Implement the admin pages and routes**

Routes are owner-only. The product edit page handles variants and modifier group attachment in one form. The catalog list shows branch availability toggles.

- [ ] **Step 5: Run the tests and build**

Run: `php artisan test --filter=CatalogTest && npm run build`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "feat: add catalog, variants, modifiers, and branch availability"
```

---

### Task 4: Server-priced order creation with idempotency

**Files:**
- Create: migrations for `order_sequences`, `orders`, `order_items`, `order_item_modifiers`, `order_status_events`
- Create: `app/Modules/Ordering/Enums/OrderStatus.php`, `OrderChannel.php`, `app/Modules/Payments/Enums/PaymentStatus.php`
- Create: `app/Modules/Ordering/Models/Order.php`, `OrderItem.php`, `OrderItemModifier.php`, `OrderStatusEvent.php`
- Create: `app/Modules/Ordering/Data/CreateOrderData.php`
- Create: `app/Modules/Ordering/Actions/GenerateOrderNumber.php`, `CreateOrder.php`
- Create: `app/Modules/Catalog/Actions/PriceOrderItems.php`
- Create: `app/Modules/Ordering/Http/Controllers/PosController.php`
- Create: `app/Http/Requests/StoreOrderRequest.php`
- Create: `database/factories/OrderFactory.php`, `OrderItemFactory.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/OrderCreationTest.php`

**Interfaces:**
- Consumes: catalog models, branch access, audit.
- Produces:
  - `OrderStatus` cases `Queued`, `Accepted`, `Preparing`, `Ready`, `Completed`, `Cancelled`; `label(): string`; `allowedNext(): array`.
  - `OrderChannel` cases `DineIn`, `Takeaway`.
  - `PaymentStatus` cases `Unpaid`, `Paid`, `Voided`.
  - `CreateOrder::__invoke(User $actor, CreateOrderData $data): Order`.
  - `CreateOrderData` constructor: `int $branchId, OrderChannel $channel, ?string $tableNumber, ?string $customerName, ?string $note, array $items, string $idempotencyKey`.
  - Item input shape: `array{product_id:int, variant_id:?int, modifier_ids:list<int>, quantity:int, note:?string}`.
  - `Order` fields: `order_number`, `branch_id`, `channel`, `table_number`, `status`, `payment_status`, `subtotal`, `total`, `idempotency_key`, `placed_by_id`.
  - `OrderFactory` states: `unpaid()`, `paid()`, `voided()`, and `for(Branch $branch)`. The `paid()` state sets `payment_status = paid` and creates one payment in `afterCreating`; `voided()` sets both statuses to their voided values and creates a voided payment.
  - Routes: `GET /pos` and `POST /pos/orders`. The POST always returns JSON `{"order": {...}}` because the POS screen submits with fetch and needs the order ID for payment.

- [ ] **Step 1: Write the failing pricing and idempotency tests**

```php
// tests/Feature/OrderCreationTest.php
public function test_order_total_uses_server_prices_and_ignores_client_price(): void
{
    $branch = Branch::factory()->create();
    $cashier = User::factory()->cashier()->for($branch)->create();
    [$product, $large, $cheese] = $this->pizzaWithLargeAndCheese();

    $response = $this->actingAs($cashier)->post('/pos/orders', [
        'branch_id' => $branch->id,
        'channel' => 'dine_in',
        'table_number' => 'A-12',
        'items' => [[
            'product_id' => $product->id,
            'variant_id' => $large->id,
            'modifier_ids' => [$cheese->id],
            'quantity' => 2,
            'note' => 'tanpa bawang',
            'unit_price' => 1,
        ]],
        'idempotency_key' => 'checkout-001',
    ])->assertCreated();

    $order = Order::firstOrFail();
    $this->assertSame(170000, $order->total);
    $this->assertSame(170000, $order->subtotal);
    $this->assertSame(1, $order->items()->count());
    $this->assertSame(2, $order->items()->first()->quantity);
    $this->assertSame(85000, $order->items()->first()->unit_price);
}

public function test_cashier_cannot_create_order_for_another_branch(): void
{
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    $cashier = User::factory()->cashier()->for($branchA)->create();
    [$product, $large, $cheese] = $this->pizzaWithLargeAndCheese();

    $this->actingAs($cashier)->post('/pos/orders', [
        'branch_id' => $branchB->id,
        'channel' => 'takeaway',
        'items' => [[
            'product_id' => $product->id,
            'variant_id' => $large->id,
            'modifier_ids' => [$cheese->id],
            'quantity' => 1,
        ]],
        'idempotency_key' => 'checkout-003',
    ])->assertForbidden();

    $this->assertDatabaseCount('orders', 0);
}

public function test_same_idempotency_key_returns_same_order(): void
{
    $branch = Branch::factory()->create();
    $cashier = User::factory()->cashier()->for($branch)->create();
    [$product, $large, $cheese] = $this->pizzaWithLargeAndCheese();
    $payload = [
        'branch_id' => $branch->id,
        'channel' => 'takeaway',
        'items' => [[
            'product_id' => $product->id,
            'variant_id' => $large->id,
            'modifier_ids' => [$cheese->id],
            'quantity' => 1,
        ]],
        'idempotency_key' => 'checkout-002',
    ];

    $first = $this->actingAs($cashier)->post('/pos/orders', $payload)->json('order.id');
    $second = $this->actingAs($cashier)->post('/pos/orders', $payload)->json('order.id');

    $this->assertSame($first, $second);
    $this->assertDatabaseCount('orders', 1);
}
```

Helper values: product base 50000, large variant 75000, extra cheese 10000. Total for two large pizzas with cheese is `(75000 + 10000) * 2 = 170000`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=OrderCreationTest`
Expected: FAIL.

- [ ] **Step 3: Implement the schema and order number generator**

`order_sequences` has `branch_id`, `sequence_date`, `last_number`, unique `(branch_id, sequence_date)`. Generate atomically:

```sql
INSERT INTO order_sequences (branch_id, sequence_date, last_number)
VALUES (?, CURRENT_DATE, 1)
ON CONFLICT (branch_id, sequence_date)
DO UPDATE SET last_number = order_sequences.last_number + 1
RETURNING last_number;
```

Format: `PF-{branch code}-{Ymd}-{last_number padded to 4}`.

- [ ] **Step 4: Implement `PriceOrderItems` and `CreateOrder`**

`PriceOrderItems` must verify:

- Product is active and available at the branch.
- Variant belongs to the product and is active.
- Each modifier belongs to a modifier group attached to the product and is active.
- Required groups meet `min_select`.
- No group exceeds `max_select`.
- Quantity is between 1 and 99.
- Unit price is variant price plus modifier deltas. The base price is used only when the product has no active variants.

`CreateOrder` runs in a transaction, resolves the branch (cashier uses own branch, owner may pass one), generates the number, creates the order with `status = queued` and `payment_status = unpaid`, creates item snapshots, and returns the order. On a unique idempotency violation, return the existing order with the same key and branch.

- [ ] **Step 5: Run the tests and build**

Run: `php artisan test --filter=OrderCreationTest && npm run build`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "feat: add server-priced idempotent order creation"
```

---

## Phase 3: Payments, POS, and kitchen

### Task 5: Payment confirmation, cancel, void, and POS screen

**Files:**
- Create: migrations for `payments`, `payment_events`
- Create: `app/Modules/Payments/Enums/PaymentMethod.php`, `app/Modules/Payments/Models/Payment.php`, `PaymentEvent.php`
- Create: `app/Modules/Payments/Actions/MarkOrderPaid.php`
- Create: `app/Modules/Ordering/Actions/CancelOrder.php`, `VoidOrder.php`
- Create: `app/Modules/Ordering/Http/Controllers/OrderController.php`, `app/Modules/Payments/Http/Controllers/PaymentController.php`
- Create: `app/Policies/OrderPolicy.php`
- Create: `resources/js/pages/pos/Index.vue`, `resources/js/pages/pos/Orders.vue`
- Create: `resources/js/lib/money.ts`, `resources/js/lib/status.ts`
- Modify: `routes/web.php`
- Test: `tests/Feature/PaymentTest.php`, `tests/Feature/VoidOrderTest.php`

**Interfaces:**
- Consumes: `Order`, `CreateOrder`, catalog data, base components.
- Produces:
  - `PaymentMethod` cases `Cash`, `QrisStatic`.
  - `MarkOrderPaid::__invoke(Order $order, User $actor, PaymentMethod $method, ?int $cashReceived = null): Payment`.
  - `CancelOrder::__invoke(Order $order, User $actor, string $reason): void`.
  - `VoidOrder::__invoke(Order $order, User $actor, string $reason): void`.
  - `OrderPolicy` methods `view`, `pay`, `cancel`, `void`.
  - Routes: `POST /orders/{order}/pay`, `POST /orders/{order}/cancel`, `POST /orders/{order}/void`, `GET /orders/{order}/receipt`.
  - `formatRupiah(int $value): string` and status label maps.

- [ ] **Step 1: Write the failing payment and void tests**

```php
// tests/Feature/PaymentTest.php
public function test_marking_paid_records_payment_and_calculates_change(): void
{
    $order = Order::factory()->unpaid()->create(['total' => 170000]);
    $cashier = User::factory()->cashier()->for($order->branch)->create();

    $this->actingAs($cashier)
        ->post("/orders/{$order->id}/pay", [
            'method' => 'cash',
            'cash_received' => 200000,
        ])->assertRedirect();

    $order->refresh();
    $this->assertSame(PaymentStatus::Paid, $order->payment_status);
    $this->assertDatabaseHas('payments', [
        'order_id' => $order->id,
        'amount' => 170000,
        'cash_received' => 200000,
        'change_amount' => 30000,
        'status' => 'paid',
    ]);
}

public function test_marking_paid_twice_does_not_create_second_payment(): void
{
    $order = Order::factory()->unpaid()->create(['total' => 170000]);
    $cashier = User::factory()->cashier()->for($order->branch)->create();

    $this->actingAs($cashier)->post("/orders/{$order->id}/pay", ['method' => 'cash']);
    $this->actingAs($cashier)->post("/orders/{$order->id}/pay", ['method' => 'cash']);

    $this->assertDatabaseCount('payments', 1);
}

public function test_cashier_cannot_pay_another_branch_order(): void
{
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    $order = Order::factory()->unpaid()->for($branchB)->create(['total' => 170000]);
    $cashier = User::factory()->cashier()->for($branchA)->create();

    $this->actingAs($cashier)
        ->post("/orders/{$order->id}/pay", ['method' => 'cash'])
        ->assertForbidden();

    $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
}
```

```php
// tests/Feature/VoidOrderTest.php
public function test_owner_can_void_paid_order_with_reason_and_audit(): void
{
    $order = Order::factory()->paid()->create(['total' => 170000]);
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post("/orders/{$order->id}/void", ['reason' => 'Salah input item'])
        ->assertRedirect();

    $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    $this->assertSame(PaymentStatus::Voided, $order->fresh()->payment_status);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'order.voided',
        'target_id' => $order->id,
        'reason' => 'Salah input item',
    ]);
}

public function test_cashier_cannot_void_paid_order(): void
{
    $order = Order::factory()->paid()->create();
    $cashier = User::factory()->cashier()->for($order->branch)->create();
    $this->actingAs($cashier)
        ->post("/orders/{$order->id}/void", ['reason' => 'Coba-coba'])
        ->assertForbidden();
}

public function test_cashier_can_cancel_unpaid_order_with_audit(): void
{
    $order = Order::factory()->unpaid()->create();
    $cashier = User::factory()->cashier()->for($order->branch)->create();

    $this->actingAs($cashier)
        ->post("/orders/{$order->id}/cancel", ['reason' => 'Customer batal'])
        ->assertRedirect();

    $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'order.cancelled',
        'target_id' => $order->id,
        'actor_id' => $cashier->id,
        'reason' => 'Customer batal',
    ]);
}

public function test_cashier_cannot_cancel_after_kitchen_accepts(): void
{
    $order = Order::factory()->unpaid()->create(['status' => OrderStatus::Accepted]);
    $cashier = User::factory()->cashier()->for($order->branch)->create();

    $this->actingAs($cashier)
        ->post("/orders/{$order->id}/cancel", ['reason' => 'Terlambat'])
        ->assertStatus(422);

    $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter='PaymentTest|VoidOrderTest'`
Expected: FAIL.

- [ ] **Step 3: Implement payments, state transitions, and policies**

`MarkOrderPaid` runs in a transaction, locks the order row, rejects cancelled or already voided orders, creates one payment with `amount = order.total`, sets `paid_at`, sets order `payment_status = paid`, and writes a payment event. A second call returns the existing paid payment.

`CancelOrder` allows cashier or owner for unpaid orders before kitchen acceptance. Sets order status `cancelled`, records a status event, writes audit. `VoidOrder` is owner-only, requires a paid order, sets `cancelled` and `voided`, records a payment event and audit, and never deletes rows.

`OrderPolicy`:

```php
public function view(User $user, Order $order): bool
{
    return $user->hasBranchAccess($order->branch_id);
}

public function pay(User $user, Order $order): bool
{
    return $user->hasBranchAccess($order->branch_id)
        && in_array($user->role, [Role::Owner, Role::Cashier], true)
        && $order->payment_status === PaymentStatus::Unpaid
        && $order->status !== OrderStatus::Cancelled;
}

public function void(User $user, Order $order): bool
{
    return $user->role === Role::Owner
        && $order->payment_status === PaymentStatus::Paid;
}
```

- [ ] **Step 4: Build the POS screen**

`pos/Index.vue` is phone-first: category tabs, search, product grid, cart sheet, dine-in or takeaway toggle, table number for dine-in, modifier picker, notes, payment sheet with cash or QRIS, cash received and change, and a receipt preview. It posts the order with a generated `crypto.randomUUID()` idempotency key, then posts payment. Loading, empty, error, and success states are required. The cart total is display only; the server response is authoritative.

- [ ] **Step 5: Run the tests and build**

Run: `php artisan test --filter='PaymentTest|VoidOrderTest' && npm run build`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "feat: add payment confirmation, cancel, void, and phone POS"
```

---

### Task 6: Kitchen display with polling and status history

**Files:**
- Create: `app/Modules/Kitchen/Actions/TransitionOrderStatus.php`
- Create: `app/Modules/Kitchen/Http/Controllers/KitchenController.php`
- Create: `resources/js/pages/kitchen/Index.vue`
- Modify: `routes/web.php`, `Order` model with scope `visibleToKitchen()`
- Test: `tests/Feature/KitchenTest.php`

**Interfaces:**
- Consumes: `OrderStatus`, `OrderPolicy`, payments.
- Produces:
  - `TransitionOrderStatus::__invoke(Order $order, User $actor, OrderStatus $next): Order`.
  - `GET /kitchen` Inertia page.
  - `GET /kitchen/orders` JSON list of paid, non-cancelled orders for the user's branch.
  - `PATCH /kitchen/orders/{order}/status` with `status`.

- [ ] **Step 1: Write the failing kitchen tests**

```php
// tests/Feature/KitchenTest.php
public function test_kitchen_sees_only_paid_orders_for_own_branch(): void
{
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    $paidA = Order::factory()->paid()->for($branchA)->create();
    Order::factory()->unpaid()->for($branchA)->create();
    Order::factory()->paid()->for($branchB)->create();
    $kitchen = User::factory()->kitchen()->for($branchA)->create();

    $ids = $this->actingAs($kitchen)
        ->getJson('/kitchen/orders?status=queued')
        ->assertOk()
        ->json('orders.*.id');

    $this->assertSame([$paidA->id], $ids);
}

public function test_status_transition_records_actor_and_timestamp(): void
{
    $order = Order::factory()->paid()->create();
    $kitchen = User::factory()->kitchen()->for($order->branch)->create();

    $this->actingAs($kitchen)
        ->patch("/kitchen/orders/{$order->id}/status", ['status' => 'accepted'])
        ->assertOk();

    $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
    $this->assertDatabaseHas('order_status_events', [
        'order_id' => $order->id,
        'to_status' => 'accepted',
        'actor_id' => $kitchen->id,
    ]);
}

public function test_invalid_transition_is_rejected(): void
{
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Queued]);
    $kitchen = User::factory()->kitchen()->for($order->branch)->create();

    $this->actingAs($kitchen)
        ->patch("/kitchen/orders/{$order->id}/status", ['status' => 'ready'])
        ->assertStatus(422);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=KitchenTest`
Expected: FAIL.

- [ ] **Step 3: Implement the transition action and routes**

Allowed transitions:

```text
queued -> accepted
accepted -> preparing
preparing -> ready
ready -> completed
```

Kitchen and owner may transition. Cashier may not. The action locks the order row, verifies the current status, writes `order_status_events`, and updates the order. Invalid transitions return 422 with a clear Indonesian message.

`Order::scopeVisibleToKitchen($query)` filters `payment_status = paid`, `status != cancelled`, and optionally by branch.

- [ ] **Step 4: Build the kitchen screen**

`kitchen/Index.vue` polls `GET /kitchen/orders?status=...` every 5 seconds. Tabs: Baru, Diterima, Disiapkan, Siap, Selesai. Cards show order number, table number for dine-in, elapsed time, items with modifiers and notes, and one primary next-status button. Dark theme, large type, readable from 1 to 2 meters, status shown with text and color. New order arrival uses one short attention animation, respecting `prefers-reduced-motion`.

- [ ] **Step 5: Run the tests and build**

Run: `php artisan test --filter=KitchenTest && npm run build`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "feat: add kitchen display with paid-order queue and status history"
```

---

## Phase 4: Inventory and reporting

### Task 7: Append-only stock ledger, stock count, and low stock

**Files:**
- Create: migrations for `units`, `ingredients`, `stock_movements`, `stock_balances`, `stock_counts`, `stock_count_items`
- Create: `app/Modules/Inventory/Enums/StockMovementType.php`
- Create: `app/Modules/Inventory/Models/Unit.php`, `Ingredient.php`, `StockMovement.php`, `StockBalance.php`, `StockCount.php`, `StockCountItem.php`
- Create: `app/Modules/Inventory/Actions/RecordStockMovement.php`, `SubmitStockCount.php`
- Create: `app/Modules/Inventory/Http/Controllers/IngredientController.php`, `StockMovementController.php`, `StockCountController.php`
- Create: factories for units, ingredients, stock balances
- Create: `resources/js/pages/admin/stock/Ingredients.vue`, `Movements.vue`, `Count.vue`, `resources/js/pages/kitchen/Stock.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/StockLedgerTest.php`, `tests/Feature/StockCountTest.php`, `tests/Feature/StockConcurrencyTest.php`

**Interfaces:**
- Consumes: branch access, audit.
- Produces:
  - `StockMovementType` cases `Opening`, `Receipt`, `Waste`, `Adjustment`, `CountCorrection`.
  - `RecordStockMovement::__invoke(User $actor, int $branchId, int $ingredientId, int $quantity, StockMovementType $type, string $reason, ?string $referenceType = null, ?int $referenceId = null): StockMovement`.
  - `SubmitStockCount::__invoke(User $actor, int $branchId, array $countedItems, ?string $note = null): StockCount`.
  - `$countedItems` shape: `list<array{ingredient_id:int, counted_quantity:int}>`.
  - `StockBalance` unique per `(branch_id, ingredient_id)`.
  - Routes: owner `GET/POST /admin/stock/ingredients`, `POST /admin/stock/movements`, `GET/POST /admin/stock/counts`; kitchen `GET /kitchen/stock`, `POST /kitchen/stock/waste`, `POST /kitchen/stock/counts`.

- [ ] **Step 1: Write the failing ledger tests**

```php
// tests/Feature/StockLedgerTest.php
public function test_receipt_and_waste_change_balance_and_append_movements(): void
{
    $branch = Branch::factory()->create();
    $owner = User::factory()->owner()->create();
    $unit = Unit::factory()->create(['code' => 'g']);
    $ingredient = Ingredient::factory()->create(['base_unit_id' => $unit->id]);

    app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, 1000, StockMovementType::Opening, 'Stok awal');
    app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, 500, StockMovementType::Receipt, 'Terima supplier');
    app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, -200, StockMovementType::Waste, 'Rusak');

    $this->assertDatabaseHas('stock_balances', [
        'branch_id' => $branch->id,
        'ingredient_id' => $ingredient->id,
        'quantity' => 1300,
    ]);
    $this->assertDatabaseCount('stock_movements', 3);
}

public function test_negative_balance_is_rejected(): void
{
    $branch = Branch::factory()->create();
    $owner = User::factory()->owner()->create();
    $ingredient = Ingredient::factory()->create();

    $this->expectException(ValidationException::class);
    app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, -1, StockMovementType::Waste, 'Tes');
}

public function test_balance_equals_sum_of_ledger(): void
{
    $branch = Branch::factory()->create();
    $owner = User::factory()->owner()->create();
    $ingredient = Ingredient::factory()->create();

    app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, 1000, StockMovementType::Opening, 'Stok awal');
    app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, -250, StockMovementType::Waste, 'Rusak');

    $ledgerSum = StockMovement::where('branch_id', $branch->id)
        ->where('ingredient_id', $ingredient->id)
        ->sum('quantity');
    $balance = StockBalance::where('branch_id', $branch->id)
        ->where('ingredient_id', $ingredient->id)
        ->value('quantity');

    $this->assertSame(750, $ledgerSum);
    $this->assertSame($ledgerSum, $balance);
}

public function test_stock_adjustment_writes_audit(): void
{
    $branch = Branch::factory()->create();
    $owner = User::factory()->owner()->create();
    $ingredient = Ingredient::factory()->create();
    StockBalance::factory()->for($branch)->for($ingredient, 'ingredient')->create(['quantity' => 100]);

    app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, 25, StockMovementType::Adjustment, 'Koreksi hitung');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'stock.adjusted',
        'actor_id' => $owner->id,
        'branch_id' => $branch->id,
        'reason' => 'Koreksi hitung',
    ]);
}

public function test_kitchen_cannot_record_stock_for_another_branch(): void
{
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    $kitchen = User::factory()->kitchen()->for($branchA)->create();
    $ingredient = Ingredient::factory()->create();

    $this->actingAs($kitchen)
        ->post('/kitchen/stock/waste', [
            'branch_id' => $branchB->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 10,
            'reason' => 'Rusak',
        ])->assertForbidden();

    $this->assertDatabaseCount('stock_movements', 0);
}
```

- [ ] **Step 2: Run the ledger tests to verify they fail**

Run: `php artisan test --filter=StockLedgerTest`
Expected: FAIL.

- [ ] **Step 3: Implement the ledger transaction**

For each movement:

1. `INSERT INTO stock_balances ... ON CONFLICT DO NOTHING`.
2. `SELECT ... FOR UPDATE` the balance row.
3. Compute `newQuantity = quantity + delta`.
4. Reject if `newQuantity < 0`.
5. Insert the movement with signed quantity, unit, type, reason, reference, actor, and timestamp.
6. Update the balance and `updated_at`.
7. Commit.

No update or delete route exists for movements. The model blocks updates and deletes in `booted()`.

- [ ] **Step 4: Write the failing stock count tests**

```php
// tests/Feature/StockCountTest.php
public function test_partial_count_only_corrects_entered_ingredients(): void
{
    $branch = Branch::factory()->create();
    $kitchen = User::factory()->kitchen()->for($branch)->create();
    $mozzarella = Ingredient::factory()->create(['name' => 'Mozzarella']);
    $flour = Ingredient::factory()->create(['name' => 'Tepung']);
    StockBalance::factory()->for($branch)->for($mozzarella, 'ingredient')->create(['quantity' => 1000]);
    StockBalance::factory()->for($branch)->for($flour, 'ingredient')->create(['quantity' => 5000]);

    $count = app(SubmitStockCount::class)(
        $kitchen,
        $branch->id,
        [['ingredient_id' => $mozzarella->id, 'counted_quantity' => 900]],
        'Opname tutup'
    );

    $this->assertSame(1, $count->items()->count());
    $this->assertDatabaseHas('stock_balances', [
        'branch_id' => $branch->id,
        'ingredient_id' => $mozzarella->id,
        'quantity' => 900,
    ]);
    $this->assertDatabaseHas('stock_balances', [
        'branch_id' => $branch->id,
        'ingredient_id' => $flour->id,
        'quantity' => 5000,
    ]);
    $this->assertDatabaseHas('stock_movements', [
        'ingredient_id' => $mozzarella->id,
        'quantity' => -100,
        'type' => 'count_correction',
    ]);
}
```

- [ ] **Step 5: Implement stock count, low stock, and kitchen access**

`SubmitStockCount` runs in one transaction. For each entered ingredient, lock the balance, compute `difference = counted - system`, create the stock count item, and if `difference != 0` call the same locked movement logic with type `count_correction` and reason `Opname tutup`. Only entered ingredients are processed.

Kitchen may record waste and closing counts for its branch. Only the owner may record receipts and adjustments. Low stock is `balance <= ingredient.low_stock_threshold`. The UI shows a low-stock list on the kitchen stock page and the owner dashboard.

- [ ] **Step 6: Write the failing concurrency test**

```php
// tests/Feature/StockConcurrencyTest.php
use Illuminate\Foundation\Testing\DatabaseMigrations;

class StockConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_concurrent_deductions_do_not_lose_updates(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();
        StockBalance::factory()->for($branch)->for($ingredient, 'ingredient')->create(['quantity' => 100]);

        $pids = [];
        foreach ([1, 2] as $_) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                DB::reconnect();
                app(RecordStockMovement::class)(
                    User::find($owner->id),
                    $branch->id,
                    $ingredient->id,
                    -30,
                    StockMovementType::Waste,
                    'Concurrent waste'
                );
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $this->assertDatabaseHas('stock_balances', ['quantity' => 40]);
        $this->assertSame(2, StockMovement::where('type', 'waste')->count());
    }
}
```

- [ ] **Step 7: Run the stock tests to verify they pass**

Run: `php artisan test --filter='StockLedgerTest|StockCountTest|StockConcurrencyTest'`
Expected: PASS. The concurrency test requires PostgreSQL and `pcntl`.

- [ ] **Step 8: Commit**

```bash
git add .
git commit -m "feat: add append-only stock ledger, stock counts, and low-stock views"
```

---

### Task 8: Daily sales, payment split, product quantities, and audit views

**Files:**
- Create: `app/Modules/Reporting/Queries/DailySalesQuery.php`, `ProductSalesQuery.php`, `StockReportQuery.php`
- Create: `app/Modules/Reporting/Http/Controllers/ReportController.php`
- Create: `app/Modules/Audit/Http/Controllers/AuditLogController.php`
- Create: `resources/js/pages/admin/reports/Sales.vue`, `Stock.vue`, `resources/js/pages/admin/audit/Index.vue`
- Modify: `routes/web.php`, admin dashboard page
- Test: `tests/Feature/ReportTest.php`, `tests/Feature/AuditViewTest.php`

**Interfaces:**
- Consumes: paid orders, payments, order items, stock balances, audit logs.
- Produces:
  - `DailySalesQuery::__invoke(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): array` with keys `order_count`, `gross_sales`, `average_order_value`, `by_payment_method`.
  - `ProductSalesQuery::__invoke(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): array`.
  - `StockReportQuery::__invoke(?int $branchId): array` with `balances`, `low_stock`, `movements`.
  - Routes: owner-only `GET /admin/reports/sales`, `GET /admin/reports/stock`, `GET /admin/audit`.

- [ ] **Step 1: Write the failing report tests**

```php
// tests/Feature/ReportTest.php
public function test_sales_count_only_paid_non_voided_orders(): void
{
    $branch = Branch::factory()->create();
    $owner = User::factory()->owner()->create();
    Order::factory()->paid()->for($branch)->create(['total' => 100000]);
    Order::factory()->unpaid()->for($branch)->create(['total' => 50000]);
    Order::factory()->voided()->for($branch)->create(['total' => 70000]);

    $result = app(DailySalesQuery::class)(now()->startOfDay(), now()->endOfDay(), $branch->id);

    $this->assertSame(1, $result['order_count']);
    $this->assertSame(100000, $result['gross_sales']);
    $this->assertSame(100000, $result['average_order_value']);
}

public function test_day_boundary_uses_asia_jakarta(): void
{
    $branch = Branch::factory()->create();
    Order::factory()->paid()->for($branch)->create([
        'total' => 100000,
        'created_at' => '2026-10-25 16:00:00', // 23:00 Asia/Jakarta on 25 Oct
    ]);
    Order::factory()->paid()->for($branch)->create([
        'total' => 200000,
        'created_at' => '2026-10-25 17:30:00', // 00:30 Asia/Jakarta on 26 Oct
    ]);

    $result = app(DailySalesQuery::class)(
        CarbonImmutable::parse('2026-10-26', 'Asia/Jakarta')->startOfDay(),
        CarbonImmutable::parse('2026-10-26', 'Asia/Jakarta')->endOfDay(),
        $branch->id
    );

    $this->assertSame(1, $result['order_count']);
    $this->assertSame(200000, $result['gross_sales']);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ReportTest`
Expected: FAIL.

- [ ] **Step 3: Implement the queries with Asia/Jakarta day boundaries**

Convert the requested local dates to UTC before querying. Daily sales are counted by `orders.created_at` for paid, non-voided orders. Group payment methods by `payments.method` where `payments.status = paid`. Product quantities come from `order_items` and `order_item_modifiers`. Reports are owner-only and accept an optional branch filter.

- [ ] **Step 4: Build the report and audit pages**

Sales page: date range, branch filter, totals, payment split, product table. Stock page: branch filter, balances, low-stock list, movement history with date and type filters. Audit page: read-only table with actor, action, target, reason, timestamp, and safe before/after details.

- [ ] **Step 5: Run the tests and build**

Run: `php artisan test --filter='ReportTest|AuditViewTest' && npm run build`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "feat: add daily sales, stock reports, and audit views"
```

---

## Phase 5: Polish, printing, and operations

### Task 9: Design tokens, responsive polish, and accessibility

**Files:**
- Modify: `DESIGN.md`, `resources/css/app.css`, `resources/js/layouts/StaffLayout.vue`, all pages for responsive states
- Create: `resources/js/lib/date.ts`, `tests/js/money.test.ts`, `tests/js/date.test.ts`, `tests/js/status.test.ts`
- Modify: `package.json` scripts: `test:js` runs `vitest run`
- Test: Vitest files above

**Interfaces:**
- Consumes: all screens.
- Produces: consistent tokens, 48px touch targets, phone layouts from 360px, tablet POS, TV kitchen, keyboard focus, reduced motion, and JS tests for formatting.

- [ ] **Step 1: Update the design tokens to the confirmed brand**

In `DESIGN.md`, replace the proposed red primary with the confirmed green `#056A4D`. Add `brand-yellow` `#FFC72C` and `brand-orange` `#F47B20` as provisional, sampled from the supplied logo image, and note that the official hex codes must replace them. Keep semantic danger, warning, success, and info colors separate. State that POS is phone-first, tablet and desktop supported, and kitchen display is responsive from phone to TV with a fixed dark theme. Remove the cashier shift status from the POS header because shifts are deferred.

- [ ] **Step 2: Install and configure Vitest**

Run: `npm install -D vitest`

Add `"test:js": "vitest run"` to `package.json` scripts. No component test library is needed because the tests cover pure formatting functions.

- [ ] **Step 3: Write the failing JS formatting tests**

```ts
// tests/js/money.test.ts
import { describe, expect, it } from 'vitest';
import { formatRupiah } from '../../resources/js/lib/money';

describe('formatRupiah', () => {
  it('formats integer rupiah with dot separators', () => {
    expect(formatRupiah(85000)).toBe('Rp 85.000');
    expect(formatRupiah(0)).toBe('Rp 0');
  });
});
```

```ts
// tests/js/date.test.ts
import { describe, expect, it } from 'vitest';
import { formatJakartaDateTime } from '../../resources/js/lib/date';

describe('formatJakartaDateTime', () => {
  it('renders UTC input in Asia/Jakarta', () => {
    expect(formatJakartaDateTime('2026-10-26T07:30:00Z')).toBe('26 Okt 2026, 14:30');
  });
});
```

- [ ] **Step 4: Run the JS tests to verify they fail**

Run: `npm run test:js`
Expected: FAIL, modules missing.

- [ ] **Step 5: Implement the helpers and polish the screens**

`formatRupiah` uses `Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })` and normalizes the non-breaking space to a regular space. `formatJakartaDateTime` uses `Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', dateStyle: 'medium', timeStyle: 'short' })`.

Polish checklist:

- No horizontal scroll at 360px.
- POS primary actions at least 56px high.
- Visible focus rings on all interactive elements.
- Status always has text, not color alone.
- Loading, empty, error, and success states on every data screen.
- `prefers-reduced-motion` respected.
- Kitchen dark theme checked on a large screen.
- All copy in Bahasa Indonesia.

- [ ] **Step 6: Run tests, typecheck, lint, and build**

Run: `npm run test:js && npm run typecheck && npm run lint && npm run build`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add .
git commit -m "feat: apply brand tokens and polish responsive accessible UI"
```

---

### Task 10: Receipt payload and printing adapter

**Files:**
- Create: `app/Modules/Payments/Receipt/ReceiptPayload.php`
- Create: `resources/js/lib/receipt.ts`
- Create: `resources/js/pages/pos/Receipt.vue`
- Create: `tests/Feature/ReceiptTest.php`, `tests/js/receipt.test.ts`
- Modify: `routes/web.php`, `docs/operations/printing.md`

**Interfaces:**
- Consumes: order snapshots, payments, branch.
- Produces:
  - `ReceiptPayload::fromOrder(Order $order): array` with `branch`, `order_number`, `datetime`, `channel`, `table_number`, `items`, `total`, `payment_method`, `cashier`, `cash_received`, `change`.
  - `toEscPos(payload): string` in TypeScript.
  - `printViaBrowser()` and `printViaRawBt(payload)` in TypeScript.

- [ ] **Step 1: Write the failing receipt tests**

```php
// tests/Feature/ReceiptTest.php
public function test_receipt_payload_uses_order_snapshots_after_catalog_change(): void
{
    $order = Order::factory()->paid()->create(['total' => 170000]);
    $item = $order->items()->create([
        'product_id' => null,
        'product_name' => 'Pizza Margherita',
        'variant_name' => 'Large',
        'unit_price' => 85000,
        'quantity' => 2,
        'line_total' => 170000,
    ]);
    $item->modifiers()->create([
        'modifier_id' => null,
        'modifier_name' => 'Extra Cheese',
        'price_delta' => 0,
    ]);

    Product::query()->update(['name' => 'Renamed']);

    $payload = ReceiptPayload::fromOrder($order->fresh('items.modifiers'));
    $this->assertSame('Pizza Margherita', $payload['items'][0]['product_name']);
    $this->assertSame(170000, $payload['total']);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ReceiptTest`
Expected: FAIL.

- [ ] **Step 3: Implement the payload and receipt page**

The receipt page renders a narrow 58mm or 80mm layout and offers two actions: browser print and RawBT print. The RawBT path builds ESC/POS text and opens the Android intent. The transport is isolated so it can be replaced after the printer model is confirmed.

- [ ] **Step 4: Run tests and build**

Run: `php artisan test --filter=ReceiptTest && npm run test:js && npm run build`
Expected: PASS.

- [ ] **Step 5: Record the hardware check**

`docs/operations/printing.md` lists the printer model as unknown, the three connection options, the RawBT setup, a test print checklist, and the fallback to browser print. Do not mark printing complete until the branch printer is tested.

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "feat: add receipt payload and pluggable print transport"
```

---

### Task 11: Security hardening, deployment, backups, and documentation

**Files:**
- Create: `README.md`, `docs/operations/deployment.md`, `docs/operations/backup-restore.md`, `scripts/backup-database.sh`
- Modify: `.env.example`, `bootstrap/app.php`, `routes/console.php`, `config/session.php`
- Test: `tests/Feature/SecurityTest.php`

**Interfaces:**
- Consumes: all features.
- Produces: rate limits, HTTPS guidance, production environment checklist, nightly backup script, restore procedure, and setup documentation.

- [ ] **Step 1: Write the failing security tests**

```php
// tests/Feature/SecurityTest.php
public function test_login_is_rate_limited_after_five_attempts(): void
{
    $user = User::factory()->cashier()->create();
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    }
    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertStatus(429);
}

public function test_guest_is_redirected_from_pos(): void
{
    $this->get('/pos')->assertRedirect('/login');
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SecurityTest`
Expected: FAIL or 429 behavior not configured.

- [ ] **Step 3: Implement hardening**

- Login throttle: 5 attempts per minute per email and IP.
- Order creation throttle: 30 requests per minute per user.
- Security headers: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`.
- Session cookie: `secure`, `http_only`, `same_site=lax` in production.
- `APP_DEBUG=false` in production.
- `.env` is gitignored. `.env.example` has no real secrets.

- [ ] **Step 4: Write deployment and backup docs**

`deployment.md` covers Ubuntu VPS, Nginx, PHP-FPM, PostgreSQL, HTTPS with Let's Encrypt, queue worker, scheduler, storage permissions, and the release checklist. `backup-restore.md` covers nightly `pg_dump`, S3-compatible upload, retention, and a restore test. `backup-database.sh` uses environment variables and fails loudly on error.

- [ ] **Step 5: Run the full verification**

Run:

```bash
php artisan test
npm run test:js
npm run typecheck
npm run lint
npm run build
```

Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "chore: harden security and add deployment and backup docs"
```

---

### Task 12: End-to-end acceptance run

**Files:**
- Create: `docs/operations/pilot-checklist.md`
- Test: manual, against the seeded two-branch database.

**Interfaces:**
- Consumes: everything.
- Produces: a recorded acceptance run with evidence for both branches.

- [ ] **Step 1: Seed representative data**

Run: `php artisan migrate --seed`
Expected: two branches, owner, cashier and kitchen per branch, sample categories, products, variants, modifiers, units, and ingredients.

- [ ] **Step 2: Run the cashier flow on branch PF-01**

Create a dine-in order with a large pizza, extra cheese, and a note. Pay cash with change. Confirm the order number, total, and receipt. Repeat with QRIS static. Record the results.

- [ ] **Step 3: Run the kitchen flow on branch PF-01**

Confirm the paid order appears. Move it through accepted, preparing, ready, and completed. Confirm each timestamp and actor in the database or audit view. Confirm an unpaid order does not appear.

- [ ] **Step 4: Run the stock flow on branch PF-01**

Record opening stock, a receipt, waste, and a partial closing count. Confirm the ledger rows, the corrected balance, and the low-stock list. Try a negative adjustment and confirm it is rejected.

- [ ] **Step 5: Run the branch isolation and audit checks**

Sign in as cashier PF-02 and confirm PF-01 orders and stock are not readable. Void a paid order as owner and confirm the reversal, reason, and audit entry. Confirm a cashier cannot void.

- [ ] **Step 6: Record the results**

Write the checked flows, screenshots or command output references, and any failures in `docs/operations/pilot-checklist.md`. Do not ship with an open failure in ordering, payments, stock, branch access, or audit.

- [ ] **Step 7: Commit**

```bash
git add .
git commit -m "docs: record first-release acceptance run"
```

---

## Self-Review

- **Spec coverage:** Sections 5 to 17 of the spec map to Tasks 2 to 11. Public website, Flutter, online ordering, shifts, recipes, transfers, discounts, delivery, and gateway integration are non-goals and have no tasks.
- **Step scan:** Every step names one action and one checkable result. Test steps include assertions. Implementation steps name signatures or exact rules. No step says "handle edge cases" or "add validation" without specifics.
- **Type consistency:** `OrderStatus`, `PaymentStatus`, `PaymentMethod`, `StockMovementType`, `CreateOrderData`, `RecordStockMovement`, and `SubmitStockCount` signatures are used the same way in every task.
- **Review focus:** Five high-risk failure modes each have a pinned test in Tasks 4, 6, and 7, plus audit and branch-scope tests in Tasks 2, 5, and 7.
- **Proportion:** The plan is longer than the spec because it carries exact paths, interfaces, test assertions, and commands. Code blocks are signatures and tests, not full implementations.
