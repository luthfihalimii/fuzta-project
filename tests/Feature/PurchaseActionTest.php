<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\UnitConversion;
use App\Modules\Purchasing\Actions\RecordPurchase;
use App\Modules\Purchasing\Data\RecordPurchaseData;
use App\Modules\Purchasing\Models\Purchase;
use App\Modules\Purchasing\Models\Supplier;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseActionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private User $kitchen;

    private User $cashier;

    private User $owner;

    private Supplier $supplier;

    private Unit $kg;

    private Unit $g;

    private Ingredient $ingredient;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->branchA, $this->branchB] = Branch::factory()->count(2)->create();
        $this->kitchen = User::factory()->kitchen()->create(['branch_id' => $this->branchA->id]);
        $this->cashier = User::factory()->cashier()->create(['branch_id' => $this->branchA->id]);
        $this->owner = User::factory()->owner()->create();
        $this->supplier = Supplier::factory()->create(['name' => 'CV Sumber Pangan']);
        $this->kg = Unit::factory()->create(['code' => 'kg', 'name' => 'Kilogram']);
        $this->g = Unit::factory()->create(['code' => 'g', 'name' => 'Gram']);
        UnitConversion::factory()->create([
            'from_unit_id' => $this->kg->id,
            'to_unit_id' => $this->g->id,
            'from_quantity' => 1,
            'to_quantity' => 1000,
        ]);
        $this->ingredient = Ingredient::factory()->create([
            'base_unit_id' => $this->g->id,
            'name' => 'Tepung',
        ]);
    }

    public function test_kitchen_records_own_branch_purchase_with_ledger_and_audit(): void
    {
        $purchase = app(RecordPurchase::class)($this->kitchen, $this->makeData([
            $this->line($this->ingredient->id, $this->kg->id, 25, 12000),
        ]));

        $this->assertSame($this->branchA->id, $purchase->branch_id);
        $this->assertSame($this->supplier->id, $purchase->supplier_id);
        $this->assertSame($this->kitchen->id, $purchase->recorded_by);
        $this->assertSame(300000, $purchase->total);

        $item = $purchase->items->sole();
        $this->assertSame($this->kg->id, $item->unit_id);
        $this->assertSame(25, $item->quantity);
        $this->assertSame(12000, $item->unit_price);
        $this->assertSame(300000, $item->line_total);

        $movement = StockMovement::query()->sole();
        $this->assertSame(StockMovementType::Receipt, $movement->type);
        $this->assertSame($this->g->id, $movement->unit_id);
        $this->assertSame(25000, $movement->quantity);
        $this->assertSame(Purchase::class, $movement->reference_type);
        $this->assertSame($purchase->id, $movement->reference_id);
        $this->assertSame("Penerimaan pembelian #{$purchase->id} — CV Sumber Pangan", $movement->reason);
        $this->assertSame($this->kitchen->id, $movement->actor_id);

        $balance = StockBalance::query()
            ->where('branch_id', $this->branchA->id)
            ->where('ingredient_id', $this->ingredient->id)
            ->sole();
        $this->assertSame(25000, $balance->quantity);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'purchase.received',
            'target_type' => Purchase::class,
            'target_id' => $purchase->id,
            'actor_id' => $this->kitchen->id,
            'branch_id' => $this->branchA->id,
        ]);
    }

    public function test_cashier_and_owner_can_record_purchase(): void
    {
        $items = [$this->line($this->ingredient->id, $this->kg->id, 1, 1000)];

        $cashierPurchase = app(RecordPurchase::class)($this->cashier, $this->makeData($items));
        $ownerPurchase = app(RecordPurchase::class)($this->owner, $this->makeData($items));

        $this->assertSame($this->cashier->id, $cashierPurchase->recorded_by);
        $this->assertSame($this->owner->id, $ownerPurchase->recorded_by);
        $this->assertSame(2, Purchase::query()->count());
    }

    public function test_kitchen_cannot_record_for_other_branch(): void
    {
        $this->expectException(AuthorizationException::class);

        app(RecordPurchase::class)($this->kitchen, $this->makeData(
            [$this->line($this->ingredient->id, $this->kg->id, 1, 1000)],
            branchId: $this->branchB->id,
        ));
    }

    public function test_missing_supplier_returns_404(): void
    {
        $this->expectException(ModelNotFoundException::class);

        app(RecordPurchase::class)($this->owner, $this->makeData(
            [$this->line($this->ingredient->id, $this->kg->id, 1, 1000)],
            supplierId: 999999,
        ));
    }

    public function test_inactive_supplier_rejected(): void
    {
        $this->supplier->update(['is_active' => false]);

        $this->assertValidationError('supplier_id', fn () => app(RecordPurchase::class)($this->owner, $this->makeData([
            $this->line($this->ingredient->id, $this->kg->id, 1, 1000),
        ])));
    }

    public function test_inactive_branch_rejected(): void
    {
        $this->branchB->update(['is_active' => false]);

        $this->assertValidationError('branch_id', fn () => app(RecordPurchase::class)($this->owner, $this->makeData(
            [$this->line($this->ingredient->id, $this->kg->id, 1, 1000)],
            branchId: $this->branchB->id,
        )));
    }

    public function test_empty_items_rejected(): void
    {
        $this->assertValidationError('items', fn () => app(RecordPurchase::class)($this->owner, $this->makeData([])));
    }

    public function test_duplicate_ingredient_rejected(): void
    {
        $this->assertValidationError('items', fn () => app(RecordPurchase::class)($this->owner, $this->makeData([
            $this->line($this->ingredient->id, $this->kg->id, 1, 1000),
            $this->line($this->ingredient->id, $this->kg->id, 2, 1000),
        ])));
    }

    public function test_missing_conversion_rejected(): void
    {
        $sak = Unit::factory()->create(['code' => 'sak', 'name' => 'Sak']);

        $this->assertValidationError('items.0.unit_id', fn () => app(RecordPurchase::class)($this->owner, $this->makeData([
            $this->line($this->ingredient->id, $sak->id, 1, 1000),
        ])));
    }

    public function test_non_integer_conversion_result_rejected(): void
    {
        $kgIngredient = Ingredient::factory()->create([
            'base_unit_id' => $this->kg->id,
            'name' => 'Gula',
        ]);

        $this->assertValidationError('items.0.quantity', fn () => app(RecordPurchase::class)($this->owner, $this->makeData([
            $this->line($kgIngredient->id, $this->g->id, 750, 20),
        ])));
    }

    public function test_idempotent_replay_returns_first_purchase(): void
    {
        $data = $this->makeData(
            [$this->line($this->ingredient->id, $this->kg->id, 25, 12000)],
            idempotencyKey: 'purchase-key-1',
        );

        $first = app(RecordPurchase::class)($this->kitchen, $data);
        $second = app(RecordPurchase::class)($this->kitchen, $data);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Purchase::query()->count());
        $this->assertSame(1, StockMovement::query()->count());

        $balance = StockBalance::query()
            ->where('branch_id', $this->branchA->id)
            ->where('ingredient_id', $this->ingredient->id)
            ->sole();
        $this->assertSame(25000, $balance->quantity);
    }

    public function test_money_total_is_sum_of_lines_with_large_values(): void
    {
        $purchase = app(RecordPurchase::class)($this->owner, $this->makeData([
            $this->line($this->ingredient->id, $this->kg->id, 5_000_000, 888_888),
        ]));

        $this->assertSame(4_444_440_000_000, $purchase->total);
        $this->assertSame(4_444_440_000_000, $purchase->items->sole()->line_total);
    }

    /**
     * @param  list<array{ingredient_id: int, unit_id: int, quantity: int, unit_price: int}>  $items
     */
    private function makeData(
        array $items,
        ?string $idempotencyKey = null,
        ?int $branchId = null,
        ?int $supplierId = null,
    ): RecordPurchaseData {
        return new RecordPurchaseData(
            branchId: $branchId ?? $this->branchA->id,
            supplierId: $supplierId ?? $this->supplier->id,
            receivedAt: '2026-10-30',
            reference: 'INV-2026-001',
            idempotencyKey: $idempotencyKey ?? Str::uuid()->toString(),
            items: $items,
        );
    }

    /**
     * @return array{ingredient_id: int, unit_id: int, quantity: int, unit_price: int}
     */
    private function line(int $ingredientId, int $unitId, int $quantity, int $unitPrice): array
    {
        return [
            'ingredient_id' => $ingredientId,
            'unit_id' => $unitId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ];
    }

    private function assertValidationError(string $key, Closure $callback): void
    {
        try {
            $callback();
            $this->fail("Expected a validation error for {$key}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
