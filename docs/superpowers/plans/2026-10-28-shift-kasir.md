# Shift Kasir Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate all POS ordering and payments behind a per-cashier cash shift, record cash in/out, close and auto-close shifts with frozen cash snapshots, reconcile auto-closed shifts, and give the owner per-shift and daily cash reports.

**Architecture:** New `app/Modules/Shifts` module in the existing Laravel 13 modular monolith. Shifts are `cashier_shifts` rows with an append-only `cash_movements` child, linked from `orders.shift_id` and `payments.shift_id`. `CreateOrder` and `MarkOrderPaid` resolve and share-lock the actor's open shift; `CloseShift`/`AutoCloseShift` take an exclusive lock and freeze totals. Inertia/Vue 3 pages reuse existing staff layout and components.

**Tech Stack:** PHP 8.3+, Laravel 13, PostgreSQL, PHPUnit 12, Vue 3 + TypeScript + Inertia, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-28-shift-kasir-design.md`

## Global Constraints

- Money is integer rupiah. No floats in cash amounts, totals, variance, or reports.
- Timestamps stored UTC; shift windows, report dates, and all display use `Asia/Jakarta`.
- Server recalculates all totals; client-submitted money is only the physical count/deposit input.
- `expected_cash = opening_cash + cash_sales_total + cash_in_total - cash_out_total`; `variance = counted_cash - expected_cash`.
- QRIS never affects physical cash; it is reported separately.
- One open shift per `(user_id, branch_id)`; one shift per `(user_id, branch_id, window_key, scheduled_start_at, scheduled_end_at)`.
- Closed shifts are immutable except owner/cashier reconciliation of `close_type = auto`.
- Every sensitive action writes an audit entry.
- UI copy is Bahasa Indonesia; currency format is `Rp 85.000`.
- Run `composer ci:check` before declaring the branch done. Never run `npm run build` concurrently with `php artisan test`.
- Do not add a dependency without approval.
- Commit after every task with a small, focused message.

## Review Focus

1. **Order or payment slipping past the shift gate.** A POS request during closing must never create an order or payment after the close snapshot. Tasks 5 and 6 pin this with share-lock/exclusive-lock tests.
2. **Shift snapshot mutating after close.** Voiding a payment from a closed shift must not change historical cash. Task 6 pins this with a rejection test.
3. **Cash leaving the drawer beyond available cash.** Owner cash-out must not make expected cash negative. Task 7 pins this with an over-limit test.
4. **Auto-close inventing cash.** Auto-close must never fill counted cash or variance. Task 9 pins this with null-column assertions plus a reconciliation test.
5. **Stale shift trapping a branch.** An old shift with an unpaid order must still be payable from the shift order list, and must not auto-close. Tasks 6 and 9 pin this.

---

### Task 1: Schema and module scaffolding

**Files:**

- Create: `database/migrations/2026_10_28_000001_add_cash_float_to_branches.php`
- Create: `database/migrations/2026_10_28_000002_create_cashier_shifts_table.php`
- Create: `database/migrations/2026_10_28_000003_create_cash_movements_table.php`
- Create: `database/migrations/2026_10_28_000004_add_shift_id_to_orders_and_payments.php`
- Create: `database/migrations/2026_10_28_000005_make_audit_actor_nullable.php`
- Create: `app/Modules/Shifts/Enums/CashMovementDirection.php`
- Create: `app/Modules/Shifts/Enums/ShiftCloseType.php`
- Create: `app/Modules/Shifts/Models/CashierShift.php`
- Create: `app/Modules/Shifts/Models/CashMovement.php`
- Create: `database/factories/CashierShiftFactory.php`
- Create: `database/factories/CashMovementFactory.php`
- Modify: `app/Modules/Branches/Models/Branch.php`
- Modify: `app/Modules/Ordering/Models/Order.php`
- Modify: `app/Modules/Payments/Models/Payment.php`
- Modify: `database/factories/BranchFactory.php`
- Test: `tests/Feature/ShiftSchemaTest.php`

**Interfaces:**

- Consumes: existing `branches`, `users`, `orders`, `payments`, `audit_logs` tables.
- Produces: `CashierShift` model with relations `branch()`, `user()`, `closedBy()`, `reconciledBy()`, `cashMovements()`, `orders()`, `payments()`; `CashMovement` with `shift()`, `actor()`; enums `CashMovementDirection::In/Out` and `ShiftCloseType::Manual/Auto`; `CashierShiftFactory` states `open()`, `closed()`, `autoClosed()`; `CashMovementFactory`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Ordering\Models\Order;
use App\Modules\Shifts\Models\CashierShift;
use App\Modules\Shifts\Models\CashMovement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_stores_cash_and_snapshot_columns(): void
    {
        $shift = CashierShift::factory()->open()->create([
            'opening_cash' => 200000,
        ]);

        $this->assertNull($shift->closed_at);
        $this->assertSame(200000, $shift->opening_cash);
        $this->assertNull($shift->expected_cash);
        $this->assertDatabaseHas('branches', ['id' => $shift->branch_id, 'cash_float' => 0]);
    }

    public function test_one_open_shift_per_user_and_branch(): void
    {
        $shift = CashierShift::factory()->open()->create();

        $this->expectException(QueryException::class);

        CashierShift::factory()->open()->create([
            'user_id' => $shift->user_id,
            'branch_id' => $shift->branch_id,
        ]);
    }

    public function test_duplicate_window_is_rejected(): void
    {
        $shift = CashierShift::factory()->closed()->create();

        $this->expectException(QueryException::class);

        CashierShift::factory()->open()->create([
            'user_id' => $shift->user_id,
            'branch_id' => $shift->branch_id,
            'window_key' => $shift->window_key,
            'scheduled_start_at' => $shift->scheduled_start_at,
            'scheduled_end_at' => $shift->scheduled_end_at,
        ]);
    }

    public function test_cash_movement_is_append_only_and_idempotent_per_shift(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $movement = CashMovement::factory()->for($shift, 'shift')->create([
            'idempotency_key' => 'cash-1',
        ]);

        $this->assertSame('in', $movement->direction->value);

        $this->expectException(QueryException::class);

        CashMovement::factory()->for($shift, 'shift')->create([
            'idempotency_key' => 'cash-1',
        ]);
    }

    public function test_orders_and_payments_link_to_shifts(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $order = Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $shift->id,
        ]);

        $this->assertSame($shift->id, $order->shift->id);
        $this->assertSame($shift->id, $order->shift_id);
    }

    public function test_audit_actor_can_be_null(): void
    {
        $this->assertNull(
            \App\Modules\Audit\Models\AuditLog::create([
                'actor_id' => null,
                'branch_id' => null,
                'action' => 'shift.auto_closed',
                'target_type' => CashierShift::class,
                'target_id' => 1,
            ])->actor_id,
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShiftSchemaTest`
Expected: FAIL — `cashier_shifts` / model classes do not exist.

- [ ] **Step 3: Add the migrations and models**

Migrations, in order:

`add_cash_float_to_branches`: `bigInteger('cash_float')->default(0)` on `branches`; down drops the column.

`create_cashier_shifts`: columns exactly as the spec's data model, including `qris_sales_total`, `close_type`, `reconciled_at`, `reconciled_by_id`, `reconciliation_note`. Add indexes and the partial unique index:

```php
DB::statement('CREATE UNIQUE INDEX cashier_shifts_open_unique ON cashier_shifts (user_id, branch_id) WHERE closed_at IS NULL');
```

Add `unique(['user_id', 'branch_id', 'window_key', 'scheduled_start_at', 'scheduled_end_at'])`.

`create_cash_movements`: `direction`, `amount`, `reason`, `actor_id`, `idempotency_key`, unique `(cashier_shift_id, idempotency_key)`, index `(cashier_shift_id, direction)`.

`add_shift_id_to_orders_and_payments`: nullable `foreignId('shift_id')->constrained('cashier_shifts')->restrictOnDelete()` on both, with the spec's indexes.

`make_audit_actor_nullable`: drop the foreign key, change `actor_id` to nullable, re-add with `nullOnDelete`.

Models use the project's `#[Fillable([...])]` attribute pattern. Casts:

- `CashierShift`: `opened_at`, `closed_at`, `scheduled_start_at`, `scheduled_end_at`, `reconciled_at` as `datetime`; all money columns `integer`; `close_type` as `ShiftCloseType::class`.
- `CashMovement`: `direction` as `CashMovementDirection::class`; `amount` omitted from casts (integer column).
- Add `shift_id` to `Order` and `Payment` fillable arrays and a `shift(): BelongsTo` relation.
- Add `cash_float` to `Branch` fillable and integer cast.

Factories: `CashierShiftFactory` defaults to a cashier + branch, `window_key = morning`, scheduled 09:00–15:00 Jakarta for today, `opened_at` now, `opening_cash` 0, `close_type` null. States `closed()` (sets closed_at, close_type manual, snapshot columns, reconciled at/by) and `autoClosed()` (closed_at, close_type auto, snapshot columns only). `CashMovementFactory` defaults direction In, amount 10000, reason `Tambahan kas`, random idempotency key.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ShiftSchemaTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regression.

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Modules/Shifts database/factories app/Modules/Branches/Models/Branch.php app/Modules/Ordering/Models/Order.php app/Modules/Payments/Models/Payment.php tests/Feature/ShiftSchemaTest.php
git commit -m "feat: add cashier shift schema and models"
```

---

### Task 2: Shift window config and resolver

**Files:**

- Create: `config/shifts.php`
- Create: `app/Modules/Shifts/Data/ShiftWindowData.php`
- Create: `app/Modules/Shifts/Actions/ResolveShiftWindow.php`
- Test: `tests/Feature/ShiftWindowTest.php`

**Interfaces:**

- Produces: `ShiftWindowData` readonly class with `string $key, string $label, CarbonImmutable $startUtc, CarbonImmutable $endUtc`. `ResolveShiftWindow::__invoke(?CarbonImmutable $now = null): ?ShiftWindowData` returns null outside windows.
- Config keys: `timezone`, `early_open_minutes`, `reminder_minutes`, `auto_close_grace_minutes`, `windows`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Modules\Shifts\Actions\ResolveShiftWindow;
use App\Modules\Shifts\Data\ShiftWindowData;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ShiftWindowTest extends TestCase
{
    private function at(string $jakarta): ?ShiftWindowData
    {
        return app(ResolveShiftWindow::class)(
            CarbonImmutable::parse($jakarta, 'Asia/Jakarta'),
        );
    }

    public function test_morning_window_is_active_between_start_and_end(): void
    {
        $window = $this->at('2026-10-28 09:00:00');

        $this->assertSame('morning', $window?->key);
        $this->assertSame('2026-10-28 02:00:00', $window?->startUtc->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-28 08:00:00', $window?->endUtc->format('Y-m-d H:i:s'));
    }

    public function test_morning_end_is_exclusive_and_evening_starts(): void
    {
        $this->assertSame('evening', $this->at('2026-10-28 15:00:00')?->key);
    }

    public function test_fourteen_fifty_is_still_morning(): void
    {
        $this->assertSame('morning', $this->at('2026-10-28 14:50:00')?->key);
    }

    public function test_early_open_fifteen_minutes_is_allowed(): void
    {
        $this->assertSame('morning', $this->at('2026-10-28 08:45:00')?->key);
    }

    public function test_twenty_minutes_early_is_rejected(): void
    {
        $this->assertNull($this->at('2026-10-28 08:40:00')?->key);
    }

    public function test_afternoon_early_open_still_resolves_to_morning(): void
    {
        $this->assertSame('morning', $this->at('2026-10-28 14:45:00')?->key);
    }

    public function test_after_last_window_is_null(): void
    {
        $this->assertNull($this->at('2026-10-28 21:00:00'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShiftWindowTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement config and resolver**

`config/shifts.php` per spec with `Asia/Jakarta`, 15 early-open, 30 reminder, 15 auto-close grace, and the two windows.

`ShiftWindowData` is a `final readonly class` with the four public properties.

`ResolveShiftWindow` builds each window's Jakarta start/end for the date of `$now`, converts to UTC, skips windows whose end is not after `$now`, picks the first window containing `$now` (start <= now < end), otherwise the first window whose pre-open period contains `$now`, otherwise null. Convert via `->utc()` and keep `CarbonImmutable`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ShiftWindowTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add config/shifts.php app/Modules/Shifts/Data app/Modules/Shifts/Actions/ResolveShiftWindow.php tests/Feature/ShiftWindowTest.php
git commit -m "feat: resolve cashier shift windows"
```

---

### Task 3: Shift totals and open shift resolution

**Files:**

- Create: `app/Modules/Shifts/Actions/CalculateShiftTotals.php`
- Create: `app/Modules/Shifts/Actions/ResolveOpenShift.php`
- Test: `tests/Feature/ShiftTotalsTest.php`

**Interfaces:**

- Produces: `CalculateShiftTotals::__invoke(CashierShift $shift): array{cash_sales_total:int,qris_sales_total:int,cash_in_total:int,cash_out_total:int,expected_cash:int}`. `ResolveOpenShift::__invoke(User $actor, int $branchId, bool $lockForShare = false): CashierShift`; throws `ValidationException` with key `shift` and message `Buka shift terlebih dahulu.` when absent.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Shifts\Actions\CalculateShiftTotals;
use App\Modules\Shifts\Actions\ResolveOpenShift;
use App\Modules\Shifts\Models\CashMovement;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftTotalsTest extends TestCase
{
    use RefreshDatabase;

    private function paidPayment(CashierShift $shift, int $amount, string $method, ?Payment $overrides = null): Payment
    {
        $order = Order::factory()->paid()->for($shift->branch)->create([
            'shift_id' => $shift->id,
            'total' => $amount,
        ]);
        $payment = $order->payment;
        $payment->forceFill(['shift_id' => $shift->id, 'amount' => $amount, 'method' => $method])->save();

        return $payment->refresh();
    }

    public function test_totals_include_cash_sales_and_exclude_qris(): void
    {
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 200000]);
        $this->paidPayment($shift, 100000, 'cash');
        $this->paidPayment($shift, 75000, 'qris_static');

        CashMovement::factory()->for($shift, 'shift')->create([
            'direction' => 'in', 'amount' => 50000,
        ]);
        CashMovement::factory()->for($shift, 'shift')->create([
            'direction' => 'out', 'amount' => 20000,
        ]);

        $totals = app(CalculateShiftTotals::class)($shift);

        $this->assertSame(100000, $totals['cash_sales_total']);
        $this->assertSame(75000, $totals['qris_sales_total']);
        $this->assertSame(50000, $totals['cash_in_total']);
        $this->assertSame(20000, $totals['cash_out_total']);
        $this->assertSame(330000, $totals['expected_cash']);
    }

    public function test_voided_payment_is_excluded(): void
    {
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 0]);
        $payment = $this->paidPayment($shift, 100000, 'cash');
        $payment->forceFill(['status' => 'voided', 'voided_at' => now()])->save();

        $totals = app(CalculateShiftTotals::class)($shift);

        $this->assertSame(0, $totals['cash_sales_total']);
    }

    public function test_resolve_open_shift_returns_actor_shift(): void
    {
        $shift = CashierShift::factory()->open()->create();

        $found = app(ResolveOpenShift::class)($shift->user, $shift->branch_id);

        $this->assertSame($shift->id, $found->id);
    }

    public function test_resolve_open_shift_rejects_without_shift(): void
    {
        $user = User::factory()->cashier()->for(Branch::factory())->create();

        $this->expectException(ValidationException::class);

        app(ResolveOpenShift::class)($user, $user->branch_id);
    }

    public function test_resolve_open_shift_is_scoped_to_branch(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $otherBranch = Branch::factory()->create();

        $this->expectException(ValidationException::class);

        app(ResolveOpenShift::class)($shift->user, $otherBranch->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShiftTotalsTest`
Expected: FAIL — classes do not exist.

- [ ] **Step 3: Implement both actions**

`CalculateShiftTotals` sums `payments` where `shift_id` matches, `method = cash`, `status = paid`; sums the same for `qris_static`; sums movements by direction; returns the five ints with `expected_cash` computed from the spec formula.

`ResolveOpenShift` queries `CashierShift` where `user_id`, `branch_id`, `closed_at` null; applies `lockForShare()` when requested; `first()`; throws `ValidationException::withMessages(['shift' => 'Buka shift terlebih dahulu.'])` when null.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ShiftTotalsTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Shifts/Actions tests/Feature/ShiftTotalsTest.php
git commit -m "feat: calculate shift totals and resolve open shift"
```

---

### Task 4: Open shift endpoint and POS gate

**Files:**

- Create: `app/Modules/Shifts/Http/Requests/OpenShiftRequest.php`
- Create: `app/Modules/Shifts/Actions/OpenShift.php`
- Create: `app/Modules/Shifts/Policies/CashierShiftPolicy.php`
- Create: `app/Modules/Shifts/Http/Controllers/PosShiftController.php`
- Create: `app/Modules/Shifts/Support/ShiftPresenter.php`
- Modify: `routes/web.php`
- Modify: `app/Providers/AppServiceProvider.php` (add `shifts` limiter)
- Modify: `app/Modules/Branches/Http/Controllers/BranchController.php` (branch `cash_float`)
- Modify: `app/Modules/Branches/Support/BranchPresenter.php`
- Modify: `resources/js/pages/admin/branches/Index.vue`
- Test: `tests/Feature/OpenShiftTest.php`

**Interfaces:**

- Produces: `OpenShift::__invoke(User $actor, int $branchId): CashierShift`; route `POST /pos/shift/open` name `pos.shift.open`; `ShiftPresenter::summary(CashierShift $shift): array`; branch payload gains `cash_float`.
- Policy abilities: `open`, `view`, `close`, `reconcile`, `recordCashMovement`, `viewAny`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shifts\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenShiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 09:30:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_cashier_opens_shift_with_branch_float(): void
    {
        $branch = Branch::factory()->create(['cash_float' => 250000]);
        $cashier = User::factory()->cashier()->for($branch)->create();

        $this->actingAs($cashier)->post('/pos/shift/open', [
            'opening_cash_confirmed' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('cashier_shifts', [
            'user_id' => $cashier->id,
            'branch_id' => $branch->id,
            'window_key' => 'morning',
            'opening_cash' => 250000,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shift.opened']);
    }

    public function test_opening_twice_returns_the_same_shift(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $shift->update([
            'scheduled_start_at' => CarbonImmutable::parse('2026-10-28 09:00', 'Asia/Jakarta')->utc(),
            'scheduled_end_at' => CarbonImmutable::parse('2026-10-28 15:00', 'Asia/Jakarta')->utc(),
        ]);

        $this->actingAs($shift->user)->post('/pos/shift/open', [
            'opening_cash_confirmed' => true,
        ])->assertRedirect();

        $this->assertSame(1, CashierShift::count());
    }

    public function test_outside_window_is_rejected(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 22:00:00', 'Asia/Jakarta'));
        $cashier = User::factory()->cashier()->for(Branch::factory())->create();

        $this->actingAs($cashier)->post('/pos/shift/open', [
            'opening_cash_confirmed' => true,
        ])->assertSessionHasErrors('shift');

        $this->assertSame(0, CashierShift::count());
    }

    public function test_confirmation_is_required(): void
    {
        $cashier = User::factory()->cashier()->for(Branch::factory())->create();

        $this->actingAs($cashier)->post('/pos/shift/open', [])
            ->assertSessionHasErrors('opening_cash_confirmed');
    }

    public function test_cashier_cannot_open_for_another_branch(): void
    {
        $cashier = User::factory()->cashier()->for(Branch::factory())->create();
        $other = Branch::factory()->create();

        $this->actingAs($cashier)->post('/pos/shift/open', [
            'branch_id' => $other->id,
            'opening_cash_confirmed' => true,
        ])->assertForbidden();
    }

    public function test_stale_shift_blocks_new_window(): void
    {
        $shift = CashierShift::factory()->open()->create([
            'window_key' => 'evening',
            'scheduled_start_at' => CarbonImmutable::parse('2026-10-27 15:00', 'Asia/Jakarta')->utc(),
            'scheduled_end_at' => CarbonImmutable::parse('2026-10-27 21:00', 'Asia/Jakarta')->utc(),
        ]);

        $this->actingAs($shift->user)->post('/pos/shift/open', [
            'opening_cash_confirmed' => true,
        ])->assertSessionHasErrors('shift');
    }

    public function test_closed_window_cannot_be_reopened(): void
    {
        $shift = CashierShift::factory()->closed()->create([
            'window_key' => 'morning',
            'scheduled_start_at' => CarbonImmutable::parse('2026-10-28 09:00', 'Asia/Jakarta')->utc(),
            'scheduled_end_at' => CarbonImmutable::parse('2026-10-28 15:00', 'Asia/Jakarta')->utc(),
        ]);

        $this->actingAs($shift->user)->post('/pos/shift/open', [
            'opening_cash_confirmed' => true,
        ])->assertSessionHasErrors('shift');
    }

    public function test_branch_float_is_validated(): void
    {
        $owner = User::factory()->owner()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($owner)->patch("/admin/branches/{$branch->id}", [
            'cash_float' => -1,
        ])->assertSessionHasErrors('cash_float');

        $this->actingAs($owner)->patch("/admin/branches/{$branch->id}", [
            'cash_float' => 300000,
        ])->assertRedirect();

        $this->assertSame(300000, $branch->refresh()->cash_float);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OpenShiftTest`
Expected: FAIL — route missing.

- [ ] **Step 3: Implement opening, policy, presenter, route, and branch float**

`OpenShift` in a transaction: validate branch active; resolve window with `ResolveShiftWindow`; reject null with key `shift`; reject any open shift for user+branch with a different window (message `Tutup shift sebelumnya terlebih dahulu.`), returning the existing shift when all window fields match; reject closed shift with same window triple (`Shift untuk jendela ini sudah ditutup.`); create with `opening_cash = branch.cash_float`, `opened_at = now()`; audit `shift.opened`; return shift.

`PosShiftController@open` authorizes `open` on `CashierShift`, resolves branch (cashier's own branch, owner's `branch_id` required), calls action, redirects back with success.

`CashierShiftPolicy`: `open` = active user with role owner/cashier and branch access; `view` = owner or `shift->user_id === user->id`; `close` = owner or own shift; `reconcile` = owner or own shift; `recordCashMovement` = owner; `viewAny` = owner.

`ShiftPresenter::summary` returns id, branch, user, window, scheduled/opened/closed times, all money fields, close_type, reconciled flags, and derived `status` per spec.

Branch changes: validation `cash_float => sometimes|integer|min:0|max:1000000000`, snapshot includes it, presenter includes it, Vue form field labelled `Float kas`.

Add limiter `shifts` = 10/min by user id.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OpenShiftTest`
Expected: PASS, 8 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Shifts routes/web.php app/Providers/AppServiceProvider.php app/Modules/Branches resources/js/pages/admin/branches/Index.vue tests/Feature/OpenShiftTest.php
git commit -m "feat: add shift opening and branch cash float"
```

---

### Task 5: Gate order creation on an open shift

**Files:**

- Modify: `app/Modules/Ordering/Actions/CreateOrder.php`
- Modify: `app/Modules/Ordering/Http/Controllers/PosController.php`
- Modify: `app/Modules/Ordering/Http/Controllers/OrderController.php`
- Modify: `app/Models/User.php` (no change expected; shift relation lives on model only)
- Test: `tests/Feature/OrderShiftGateTest.php`
- Modify: `tests/Feature/OrderCreationTest.php` (open shift in setup)
- Modify: `tests/Feature/PaymentTest.php` (open shift before paying)

**Interfaces:**

- Consumes: `ResolveOpenShift`, `CashierShift`.
- Produces: every new POS order has `shift_id`; orders list payload includes `shift` id and orders from the active shift across dates; query `shift=<id>` for owner.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Ordering\Models\Order;
use App\Modules\Shifts\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderShiftGateTest extends TestCase
{
    use RefreshDatabase;

    private function productFor(Branch $branch): Product
    {
        $product = Product::factory()->for(Category::factory())->create(['base_price' => 50000]);
        $product->branches()->attach($branch, ['is_available' => true]);

        return $product;
    }

    private function payload(int $productId, string $key): array
    {
        return [
            'channel' => 'takeaway',
            'items' => [['product_id' => $productId, 'quantity' => 1]],
            'idempotency_key' => $key,
        ];
    }

    public function test_order_without_shift_is_rejected(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        $product = $this->productFor($branch);

        $this->actingAs($cashier)->postJson('/pos/orders', $this->payload($product->id, 'key-1'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift');

        $this->assertSame(0, Order::count());
    }

    public function test_order_gets_shift_id(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $product = $this->productFor($shift->branch);

        $this->actingAs($shift->user)->postJson('/pos/orders', $this->payload($product->id, 'key-2'))
            ->assertCreated();

        $this->assertSame($shift->id, Order::firstOrFail()->shift_id);
    }

    public function test_order_after_cutoff_is_rejected(): void
    {
        $shift = CashierShift::factory()->open()->create([
            'scheduled_end_at' => CarbonImmutable::now()->subMinutes(16),
        ]);
        $product = $this->productFor($shift->branch);

        $this->actingAs($shift->user)->postJson('/pos/orders', $this->payload($product->id, 'key-3'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift');
    }

    public function test_owner_needs_shift_in_target_branch(): void
    {
        $owner = User::factory()->owner()->create();
        $branch = Branch::factory()->create();
        $product = $this->productFor($branch);

        $this->actingAs($owner)->postJson('/pos/orders', [
            ...$this->payload($product->id, 'key-4'),
            'branch_id' => $branch->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('shift');
    }

    public function test_orders_page_lists_active_shift_orders_across_dates(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $old = Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $shift->id,
            'placed_by_id' => $shift->user_id,
        ]);
        $old->forceFill(['created_at' => now()->subDay()])->save();

        $this->actingAs($shift->user)->get('/pos/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos/Orders')
                ->where('shift.id', $shift->id)
                ->has('orders', 1)
                ->where('orders.0.id', $old->id));
    }

    public function test_owner_can_view_orders_for_a_specific_shift(): void
    {
        $shift = CashierShift::factory()->closed()->create();
        $order = Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $shift->id,
        ]);
        $order->forceFill(['created_at' => now()->subDays(3)])->save();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get("/pos/orders?branch={$shift->branch_id}&shift={$shift->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('orders', 1)
                ->where('orders.0.id', $order->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OrderShiftGateTest`
Expected: FAIL — orders are created without a shift (or route payload lacks shift).

- [ ] **Step 3: Wire the gate**

`CreateOrder`: after the idempotency lookup and branch validation, inside the transaction call `ResolveOpenShift($actor, $data->branchId, lockForShare: true)`; reject when `now >= scheduled_end_at + config('shifts.auto_close_grace_minutes')` with `ValidationException` key `shift`, message `Shift sudah melewati batas pesanan. Tutup shift.`; store `shift_id`.

`OrderController@index`: resolve branch; if `shift` query id is present and actor is owner, list orders with that `shift_id` (no date bound); else list today's orders plus all orders from the actor's open shift. Pass `shift`, `orders_cutoff_at`, `can_create_orders`, and `posUrl`.

`PosController@index`: pass `shift` and cutoff data; products stay hidden when no shift only in Vue (Task 10).

Existing `OrderCreationTest`: add `CashierShift::factory()->open()->for($cashier, 'user')->for($branch)->create()` in a helper or setUp before posting. `PaymentTest`: same before `POST /orders/{id}/pay`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OrderShiftGateTest`
Expected: PASS, 6 tests.

Run: `php artisan test --filter="OrderCreationTest|PaymentTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Ordering tests/Feature/OrderShiftGateTest.php tests/Feature/OrderCreationTest.php tests/Feature/PaymentTest.php
git commit -m "feat: require an open shift for POS orders"
```

---

### Task 6: Gate payments and protect closed-shift payments

**Files:**

- Modify: `app/Modules/Payments/Actions/MarkOrderPaid.php`
- Modify: `app/Modules/Ordering/Actions/VoidOrder.php`
- Test: `tests/Feature/PaymentShiftGateTest.php`

**Interfaces:**

- Produces: `payments.shift_id` on every new payment; void rejected when the payment's shift is closed with the spec message.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Ordering\Models\Order;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentShiftGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_without_shift_is_rejected(): void
    {
        $order = Order::factory()->unpaid()->create();
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)->post("/orders/{$order->id}/pay", ['method' => 'cash'])
            ->assertSessionHasErrors('shift');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cash_and_qris_payments_store_shift_id(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $cash = Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id, 'total' => 50000,
        ]);
        $qris = Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id, 'total' => 60000,
        ]);

        $this->actingAs($shift->user)->post("/orders/{$cash->id}/pay", [
            'method' => 'cash', 'cash_received' => 100000,
        ])->assertRedirect();
        $this->actingAs($shift->user)->post("/orders/{$qris->id}/pay", [
            'method' => 'qris_static',
        ])->assertRedirect();

        $this->assertDatabaseHas('payments', ['order_id' => $cash->id, 'shift_id' => $shift->id]);
        $this->assertDatabaseHas('payments', ['order_id' => $qris->id, 'shift_id' => $shift->id]);
    }

    public function test_void_on_closed_shift_is_rejected(): void
    {
        $shift = CashierShift::factory()->closed()->create();
        $order = Order::factory()->paid()->create([
            'branch_id' => $shift->branch_id, 'shift_id' => $shift->id,
        ]);
        $order->payment->forceFill(['shift_id' => $shift->id])->save();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/orders/{$order->id}/void", ['reason' => 'salah'])
            ->assertSessionHasErrors('order');

        $this->assertSame('paid', $order->payment->refresh()->status->value);
    }

    public function test_void_on_open_shift_is_allowed(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $order = Order::factory()->paid()->create([
            'branch_id' => $shift->branch_id, 'shift_id' => $shift->id,
        ]);
        $order->payment->forceFill(['shift_id' => $shift->id])->save();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/orders/{$order->id}/void", ['reason' => 'salah'])
            ->assertRedirect();

        $this->assertSame('voided', $order->payment->refresh()->status->value);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PaymentShiftGateTest`
Expected: FAIL — payments succeed without shift.

- [ ] **Step 3: Implement the gate and void guard**

`MarkOrderPaid`: existing-payment early return stays first; inside the transaction after the early return, resolve the open shift with `lockForShare: true` and store `shift_id` on the payment. QRIS and cash both.

`VoidOrder`: load the payment; if `shift_id` is not null and its shift has `closed_at`, throw `ValidationException` key `order` with the spec message.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PaymentShiftGateTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Payments app/Modules/Ordering/Actions/VoidOrder.php tests/Feature/PaymentShiftGateTest.php
git commit -m "feat: require an open shift for payments"
```

---

### Task 7: Cash movements

**Files:**

- Create: `app/Modules/Shifts/Http/Requests/RecordCashMovementRequest.php`
- Create: `app/Modules/Shifts/Actions/RecordCashMovement.php`
- Create: `app/Modules/Shifts/Http/Controllers/AdminShiftController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CashMovementTest.php`

**Interfaces:**

- Produces: `RecordCashMovement::__invoke(CashierShift $shift, User $owner, string $direction, int $amount, string $reason, string $key): CashMovement`; route `POST /admin/shifts/{shift}/cash-movements`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Shifts\Models\CashMovement;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_records_cash_in_and_audit(): void
    {
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 100000]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/cash-movements", [
            'direction' => 'in',
            'amount' => 50000,
            'reason' => 'Tambahan kembalian',
            'idempotency_key' => 'cm-1',
        ])->assertRedirect();

        $this->assertDatabaseHas('cash_movements', [
            'cashier_shift_id' => $shift->id,
            'direction' => 'in',
            'amount' => 50000,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shift.cash_movement']);
    }

    public function test_cashier_is_forbidden(): void
    {
        $shift = CashierShift::factory()->open()->create();

        $this->actingAs($shift->user)->post("/admin/shifts/{$shift->id}/cash-movements", [
            'direction' => 'in', 'amount' => 1000, 'reason' => 'x', 'idempotency_key' => 'cm-2',
        ])->assertForbidden();
    }

    public function test_closed_shift_is_rejected(): void
    {
        $shift = CashierShift::factory()->closed()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/cash-movements", [
            'direction' => 'in', 'amount' => 1000, 'reason' => 'x', 'idempotency_key' => 'cm-3',
        ])->assertSessionHasErrors('shift');
    }

    public function test_cash_out_above_available_is_rejected(): void
    {
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 100000]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/cash-movements", [
            'direction' => 'out', 'amount' => 100001, 'reason' => 'Kas kecil', 'idempotency_key' => 'cm-4',
        ])->assertSessionHasErrors('amount');
    }

    public function test_same_key_returns_existing_movement(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $owner = User::factory()->owner()->create();
        $payload = [
            'direction' => 'in', 'amount' => 25000, 'reason' => 'Koreksi', 'idempotency_key' => 'cm-5',
        ];

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/cash-movements", $payload);
        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/cash-movements", $payload);

        $this->assertSame(1, CashMovement::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CashMovementTest`
Expected: FAIL — route missing.

- [ ] **Step 3: Implement movement recording**

`RecordCashMovement` in a transaction locks the shift `FOR UPDATE`; if a movement with the key exists, return it before any open check; reject closed shift with `shift` error; for `out`, compute current totals with `CalculateShiftTotals` and reject when `amount > expected_cash` with `amount` error; create; audit `shift.cash_movement` with direction/amount/reason.

Request rules: direction in `in,out`; amount integer 1..1,000,000,000; reason required max 500; idempotency_key required max 100. Controller authorizes `recordCashMovement`, calls action, redirects back.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CashMovementTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Shifts routes/web.php tests/Feature/CashMovementTest.php
git commit -m "feat: record owner cash movements per shift"
```

---

### Task 8: Manual close shift

**Files:**

- Create: `app/Modules/Shifts/Http/Requests/CloseShiftRequest.php`
- Create: `app/Modules/Shifts/Actions/ResolveShiftForClose.php`
- Create: `app/Modules/Shifts/Actions/CloseShift.php`
- Modify: `app/Modules/Shifts/Http/Controllers/PosShiftController.php`
- Modify: `app/Modules/Shifts/Http/Controllers/AdminShiftController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CloseShiftTest.php`

**Interfaces:**

- Produces: `CloseShift::__invoke(CashierShift $shift, User $actor, int $countedCash, int $cashDeposited, ?string $note): CashierShift`; routes `POST /pos/shift/close` and `POST /admin/shifts/{shift}/close`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Ordering\Models\Order;
use App\Modules\Shifts\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseShiftTest extends TestCase
{
    use RefreshDatabase;

    private function cashOrder(CashierShift $shift, int $amount): void
    {
        $order = Order::factory()->paid()->for($shift->branch)->create([
            'shift_id' => $shift->id, 'total' => $amount,
        ]);
        $order->payment->forceFill([
            'shift_id' => $shift->id, 'method' => 'cash', 'amount' => $amount,
        ])->save();
    }

    public function test_close_freezes_totals_and_variance(): void
    {
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 200000]);
        $this->cashOrder($shift, 150000);

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 345000,
            'cash_deposited' => 145000,
            'closing_note' => 'Selisih kurang 5000',
        ])->assertRedirect();

        $shift->refresh();
        $this->assertSame(150000, $shift->cash_sales_total);
        $this->assertSame(350000, $shift->expected_cash);
        $this->assertSame(-5000, $shift->variance);
        $this->assertSame(145000, $shift->cash_deposited);
        $this->assertSame('manual', $shift->close_type->value);
        $this->assertNotNull($shift->closed_at);
        $this->assertNotNull($shift->reconciled_at);
    }

    public function test_variance_requires_note(): void
    {
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 100000]);

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 90000,
            'cash_deposited' => 0,
        ])->assertSessionHasErrors('closing_note');

        $this->assertNull($shift->refresh()->closed_at);
    }

    public function test_unpaid_order_blocks_close(): void
    {
        $shift = CashierShift::factory()->open()->create();
        Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id, 'shift_id' => $shift->id,
        ]);

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 0, 'cash_deposited' => 0,
        ])->assertSessionHasErrors('shift');
    }

    public function test_cancelled_unpaid_order_does_not_block(): void
    {
        $shift = CashierShift::factory()->open()->create();
        Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $shift->id,
            'status' => 'cancelled',
        ]);

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 0, 'cash_deposited' => 0,
        ])->assertRedirect();
    }

    public function test_cashier_early_close_is_rejected(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 12:00', 'Asia/Jakarta'));
        $shift = CashierShift::factory()->open()->create();

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 0, 'cash_deposited' => 0, 'closing_note' => 'pulang',
        ])->assertSessionHasErrors('shift');

        CarbonImmutable::setTestNow();
    }

    public function test_owner_early_close_requires_note(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 12:00', 'Asia/Jakarta'));
        $shift = CashierShift::factory()->open()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/close", [
            'counted_cash' => 0, 'cash_deposited' => 0,
        ])->assertSessionHasErrors('closing_note');

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/close", [
            'counted_cash' => 0, 'cash_deposited' => 0, 'closing_note' => 'Tutup awal',
        ])->assertRedirect();

        CarbonImmutable::setTestNow();
    }

    public function test_deposit_cannot_exceed_counted(): void
    {
        $shift = CashierShift::factory()->open()->create();

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 10000, 'cash_deposited' => 20000,
        ])->assertSessionHasErrors('cash_deposited');
    }

    public function test_second_close_is_noop(): void
    {
        $shift = CashierShift::factory()->closed()->create();
        $closedAt = $shift->closed_at;

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 1, 'cash_deposited' => 0,
        ])->assertRedirect();

        $this->assertTrue($closedAt->equalTo($shift->refresh()->closed_at));
        $this->assertSame(1, CashierShift::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CloseShiftTest`
Expected: FAIL — route missing.

- [ ] **Step 3: Implement close**

`ResolveShiftForClose`: for cashier, own open shift in their branch; for owner via POS, own open shift in the posted branch; for admin, the route shift. Throw `shift` validation errors when missing.

`CloseShift` in a transaction: lock shift `FOR UPDATE`; if closed and auto-unreconciled reject `shift` with `Shift ditutup otomatis. Gunakan rekonsiliasi.`; if closed otherwise return unchanged; early close rules (cashier forbidden, owner requires note); unpaid check (`payment_status = unpaid`, `status != cancelled`) with count in message; totals; variance; note required when variance != 0; store snapshot + manual close fields; audit `shift.closed`.

Requests: counted_cash integer 0..1,000,000,000; cash_deposited integer 0..counted_cash; closing_note nullable max 1000; branch_id nullable for cashier, required for owner on POS.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CloseShiftTest`
Expected: PASS, 8 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Shifts routes/web.php tests/Feature/CloseShiftTest.php
git commit -m "feat: close shift with cash reconciliation"
```

---

### Task 9: Auto-close, reminder payload, and reconciliation

**Files:**

- Create: `app/Modules/Shifts/Actions/AutoCloseShift.php`
- Create: `app/Modules/Shifts/Actions/ReconcileShift.php`
- Create: `app/Modules/Shifts/Actions/BuildShiftReminder.php`
- Create: `app/Modules/Shifts/Http/Requests/ReconcileShiftRequest.php`
- Create: `app/Console/Commands/AutoCloseStaleShifts.php`
- Modify: `routes/console.php`
- Modify: `app/Modules/Shifts/Http/Controllers/AdminShiftController.php`
- Modify: `app/Modules/Shifts/Http/Controllers/PosShiftController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AutoCloseShiftTest.php`
- Test: `tests/Feature/ReconcileShiftTest.php`

**Interfaces:**

- Produces: `AutoCloseShift::__invoke(): int` returns number closed; `ReconcileShift::__invoke(CashierShift $shift, User $actor, int $counted, int $deposited, ?string $note): CashierShift`; `BuildShiftReminder::__invoke(?CashierShift $shift, ?CarbonImmutable $now = null): ?array{level:string,message:string}`; command `shifts:auto-close`; routes `POST /admin/shifts/{shift}/reconcile` and `POST /pos/shifts/{shift}/reconcile`.

- [ ] **Step 1: Write the failing tests**

`AutoCloseShiftTest`:

```php
public function test_closes_shift_past_cutoff_without_touching_cash_fields(): void
{
    $shift = CashierShift::factory()->open()->create([
        'opening_cash' => 100000,
        'scheduled_end_at' => now()->subMinutes(20),
    ]);
    $this->cashOrder($shift, 50000);

    $this->artisan('shifts:auto-close')->assertSuccessful();

    $shift->refresh();
    $this->assertNotNull($shift->closed_at);
    $this->assertSame('auto', $shift->close_type->value);
    $this->assertSame(150000, $shift->expected_cash);
    $this->assertNull($shift->counted_cash);
    $this->assertNull($shift->variance);
    $this->assertNull($shift->cash_deposited);
    $this->assertNull($shift->reconciled_at);
    $this->assertNull($shift->closed_by_id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'shift.auto_closed', 'actor_id' => null]);
}

public function test_skips_shift_with_unpaid_order(): void
{
    $shift = CashierShift::factory()->open()->create([
        'scheduled_end_at' => now()->subMinutes(20),
    ]);
    Order::factory()->unpaid()->create([
        'branch_id' => $shift->branch_id, 'shift_id' => $shift->id,
    ]);

    $this->artisan('shifts:auto-close')->assertSuccessful();

    $this->assertNull($shift->refresh()->closed_at);
}

public function test_does_not_close_before_cutoff(): void
{
    $shift = CashierShift::factory()->open()->create([
        'scheduled_end_at' => now()->subMinutes(10),
    ]);

    $this->artisan('shifts:auto-close')->assertSuccessful();

    $this->assertNull($shift->refresh()->closed_at);
}

public function test_is_idempotent(): void
{
    $shift = CashierShift::factory()->open()->create([
        'scheduled_end_at' => now()->subMinutes(20),
    ]);

    $this->artisan('shifts:auto-close');
    $closedAt = $shift->refresh()->closed_at;
    $this->artisan('shifts:auto-close');

    $this->assertTrue($closedAt->equalTo($shift->refresh()->closed_at));
}
```

`ReconcileShiftTest`:

```php
public function test_owner_reconciles_auto_closed_shift(): void
{
    $shift = CashierShift::factory()->autoClosed()->create(['expected_cash' => 200000]);
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/reconcile", [
        'counted_cash' => 195000,
        'cash_deposited' => 95000,
        'reconciliation_note' => 'Kurang 5000',
    ])->assertRedirect();

    $shift->refresh();
    $this->assertSame(-5000, $shift->variance);
    $this->assertNotNull($shift->reconciled_at);
    $this->assertSame($owner->id, $shift->reconciled_by_id);
    $this->assertSame('auto', $shift->close_type->value);
}

public function test_note_required_on_variance(): void
{
    $shift = CashierShift::factory()->autoClosed()->create(['expected_cash' => 200000]);
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/reconcile", [
        'counted_cash' => 195000, 'cash_deposited' => 95000,
    ])->assertSessionHasErrors('reconciliation_note');
}

public function test_cashier_reconciles_own_shift_only(): void
{
    $shift = CashierShift::factory()->autoClosed()->create(['expected_cash' => 0]);
    $other = User::factory()->cashier()->for($shift->branch)->create();

    $this->actingAs($other)->post("/pos/shifts/{$shift->id}/reconcile", [
        'counted_cash' => 0, 'cash_deposited' => 0,
    ])->assertForbidden();

    $this->actingAs($shift->user)->post("/pos/shifts/{$shift->id}/reconcile", [
        'counted_cash' => 0, 'cash_deposited' => 0, 'reconciliation_note' => 'Beres',
    ])->assertRedirect();
}

public function test_manual_shift_cannot_be_reconciled(): void
{
    $shift = CashierShift::factory()->closed()->create();
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/reconcile", [
        'counted_cash' => 0, 'cash_deposited' => 0,
    ])->assertSessionHasErrors('shift');
}

public function test_reminder_levels(): void
{
    $shift = CashierShift::factory()->open()->create([
        'scheduled_end_at' => now()->addMinutes(20),
    ]);
    $this->assertSame('ending', app(BuildShiftReminder::class)($shift)['level']);

    $shift->update(['scheduled_end_at' => now()->subMinutes(1)]);
    $this->assertSame('overdue', app(BuildShiftReminder::class)($shift)['level']);

    $shift->update([
        'scheduled_end_at' => now()->subMinutes(16),
    ]);
    $this->assertSame('cutoff', app(BuildShiftReminder::class)($shift)['level']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="AutoCloseShiftTest|ReconcileShiftTest"`
Expected: FAIL — command and routes missing.

- [ ] **Step 3: Implement auto-close, reminder, reconciliation**

`AutoCloseShift`: select open shifts with `scheduled_end_at <= now - grace`; for each in a transaction lock `FOR UPDATE`; skip when unpaid non-cancelled order exists; otherwise compute totals, set closed_at now, close_type auto, expected, cash_sales/qris/in/out, closing_note system text, closed_by null; audit `shift.auto_closed` with null actor. Return count.

`AutoCloseStaleShifts` command signature `shifts:auto-close`, calls action, outputs count.

`routes/console.php`: schedule every five minutes, timezone Jakarta, withoutOverlapping.

`BuildShiftReminder`: null shift -> null; now < end - 30min -> null; now < end -> `ending`; now < end + grace -> `overdue`; else `cutoff`, with Indonesian messages from the spec.

`ReconcileShift`: lock shift; require close_type auto and reconciled_at null and `reconciled_by` authorized; counted/deposited; variance; note required when variance != 0; set fields; audit `shift.reconciled`.

`WriteAuditLog` must accept `?User` and null request IP/UA when actor is null; update `AuditLogController`/Vue actor name fallback `Sistem`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="AutoCloseShiftTest|ReconcileShiftTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Shifts app/Console/Commands routes app/Modules/Identity app/Modules/Audit resources/js/pages/admin/audit tests/Feature
git commit -m "feat: auto-close stale shifts and reconcile cash"
```

---

### Task 10: POS shift UI

**Files:**

- Create: `resources/js/components/shifts/ShiftGate.vue`
- Create: `resources/js/components/shifts/ShiftSummary.vue`
- Create: `resources/js/components/shifts/CloseShiftDialog.vue`
- Create: `resources/js/components/shifts/ReconcileShiftDialog.vue`
- Create: `resources/js/components/shifts/ShiftStatusBadge.vue`
- Create: `resources/js/lib/shift.ts`
- Modify: `resources/js/pages/pos/Index.vue`
- Modify: `resources/js/pages/pos/Orders.vue`
- Modify: `resources/js/lib/order.ts`
- Test: `tests/js/shift.test.ts`

**Interfaces:**

- Consumes: POS payload `shift`, `shift_window`, `shift_reminder`, `unreconciled_shift`, `orders_cutoff_at`, `can_create_orders`.
- Produces: typed `ShiftSummaryData`, `ShiftReminder`, helper `shiftStatusLabel`.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, it } from 'vitest';
import { shiftStatusLabel, suggestedDeposit } from '@/lib/shift';

describe('shift helpers', () => {
    it('suggests counted cash minus float, never below zero', () => {
        expect(suggestedDeposit(345000, 200000)).toBe(145000);
        expect(suggestedDeposit(150000, 200000)).toBe(0);
    });

    it('labels shift status', () => {
        expect(
            shiftStatusLabel({
                closed_at: null,
                close_type: null,
                reconciled_at: null,
            }),
        ).toBe('Terbuka');
        expect(
            shiftStatusLabel({
                closed_at: 'x',
                close_type: 'auto',
                reconciled_at: null,
            }),
        ).toBe('Perlu rekonsiliasi');
        expect(
            shiftStatusLabel({
                closed_at: 'x',
                close_type: 'auto',
                reconciled_at: 'y',
            }),
        ).toBe('Ditutup');
        expect(
            shiftStatusLabel({
                closed_at: 'x',
                close_type: 'manual',
                reconciled_at: 'y',
            }),
        ).toBe('Ditutup');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:js -- tests/js/shift.test.ts`
Expected: FAIL — module missing.

- [ ] **Step 3: Implement helpers and components**

`resources/js/lib/shift.ts`: types + `suggestedDeposit(counted, opening)` + `shiftStatusLabel`.

`ShiftGate`: branch summary, window, float, checkbox, submit `router.post('/pos/shift/open')`, error display.

`ShiftSummary`: money rows; reuse `Money`; expected/counted/variance/deposit; warning on nonzero variance.

`CloseShiftDialog`: counted input, deposit input auto-set from `suggestedDeposit` on counted blur, variance display, note required when variance != 0, early-close reason when owner, submit.

`ReconcileShiftDialog`: counted, deposit, note; variance; submit to admin or POS route.

`ShiftStatusBadge`: open/auto-unreconciled/closed tones.

`pos/Index.vue`: if `unreconciled_shift` show reconcile panel (and still allow open shift form? no — reconcile first, then open); if no `shift` show `ShiftGate` instead of catalog/cart; if shift show badge + summary + close button; reminder banner; disable create after cutoff.

`pos/Orders.vue`: show shift badge, cutoff warning, filter display for `shift=<id>`, keep pay/cancel.

- [ ] **Step 4: Run test and typecheck**

Run: `npm run test:js -- tests/js/shift.test.ts`
Expected: PASS.
Run: `npm run typecheck`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js tests/js/shift.test.ts
git commit -m "feat: add shift gate and cash dialogs to POS"
```

---

### Task 11: Admin shift reports and daily summary

**Files:**

- Create: `app/Modules/Shifts/Queries/ShiftReportQuery.php`
- Create: `app/Modules/Shifts/Queries/ShiftDailySummaryQuery.php`
- Modify: `app/Modules/Shifts/Http/Controllers/AdminShiftController.php`
- Modify: `routes/web.php`
- Create: `resources/js/pages/admin/shifts/Index.vue`
- Create: `resources/js/pages/admin/shifts/Show.vue`
- Create: `resources/js/components/shifts/CashMovementForm.vue`
- Create: `resources/js/components/shifts/ShiftDailySummaryTable.vue`
- Modify: `resources/js/layouts/StaffLayout.vue`
- Modify: `app/Http/Controllers/Admin/DashboardController.php`
- Modify: `resources/js/pages/admin/Dashboard.vue`
- Test: `tests/Feature/ShiftReportTest.php`
- Test: `tests/Feature/ShiftDailySummaryTest.php`

**Interfaces:**

- Produces: owner routes `GET /admin/shifts` (`view=shifts|daily`), `GET /admin/shifts/{shift}`; dashboard prop `shift_attention` with `overdue`, `unreconciled`, `unpaid`.

- [ ] **Step 1: Write the failing tests**

`ShiftReportTest`: owner sees list and detail with shift/cash columns; cashier and kitchen get 403; filters by branch, cashier, date; order count uses `orders.shift_id` and cash total uses `payments.shift_id` in a cross-cashier case.

`ShiftDailySummaryTest`: owner sees grouped rows per date+branch+cashier; only closed shifts counted; open shifts in `open_count`; expected cash includes auto-unreconciled but counted/variance/deposit exclude them; `unreconciled_count` correct; cashier 403.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="ShiftReportTest|ShiftDailySummaryTest"`
Expected: FAIL — routes missing.

- [ ] **Step 3: Implement queries, controllers, pages**

`ShiftReportQuery`: list with filters (Jakarta `opened_at` date range, branch, user, status); per-row totals and derived status; detail includes movements and related orders.

`ShiftDailySummaryQuery`: group by Jakarta date of `opened_at`, branch, cashier; sums the spec's columns; separate unreconciled and open counts.

`AdminShiftController`: `index`, `show`, `close`, `reconcile`, `storeCashMovement`; authorize owner.

`StaffLayout`: owner nav adds `Shift` -> `/admin/shifts`.

`DashboardController`: add `shift_attention` counts; Dashboard cards link with filters.

Vue pages/components per spec, reusing `Money`, `Badge`, `EmptyState`, `Button`, `Input`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="ShiftReportTest|ShiftDailySummaryTest"`
Expected: PASS.

- [ ] **Step 5: Run full CI**

Run: `composer ci:check`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Shifts app/Http/Controllers/Admin/DashboardController.php resources/js tests/Feature routes/web.php
git commit -m "feat: add owner shift reports and daily summary"
```

---

## Self-Review

**Spec coverage:** schema, windows, open, gate orders, gate payments, void guard, cash movements, manual close, auto-close, reminder, reconciliation, POS UI, admin list/detail, daily summary, dashboard attention, audit, rate limit, stale unpaid orders, cross-cashier attribution, cash-out guard, tests. Deferred items (notifications, branch-specific schedules, refunds) remain out of scope per spec.

**Type consistency:** `ShiftWindowData` fields, `CalculateShiftTotals` keys, `ResolveOpenShift` exception key, `CloseShift` signature, `ReconcileShift` signature, and route names match across tasks.

**Open execution decisions:** none that change behavior. Task 9 introduces `AutoCloseShift` and `ReconcileShift`; Task 8 must not depend on them.
