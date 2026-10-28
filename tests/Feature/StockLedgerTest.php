<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_positive_consumption_movement_is_rejected(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Jumlah konsumsi harus mengurangi stok.');

        app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, 10, StockMovementType::Consumption, 'Tes');
    }

    public function test_negative_consumption_reversal_movement_is_rejected(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Jumlah pembalikan konsumsi harus menambah stok.');

        app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, -10, StockMovementType::ConsumptionReversal, 'Tes');
    }

    public function test_balance_equals_sum_of_ledger(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();

        app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, 1000, StockMovementType::Opening, 'Stok awal');
        app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, -250, StockMovementType::Waste, 'Rusak');

        $ledgerSum = (int) StockMovement::where('branch_id', $branch->id)
            ->where('ingredient_id', $ingredient->id)
            ->sum('quantity');
        $balance = (int) StockBalance::where('branch_id', $branch->id)
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

    public function test_movement_update_is_blocked(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();
        $movement = app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, 10, StockMovementType::Opening, 'Stok awal');

        $this->expectException(LogicException::class);

        $movement->update(['reason' => 'Diubah']);
    }

    public function test_movement_delete_is_blocked(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();
        $movement = app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, 10, StockMovementType::Opening, 'Stok awal');

        $this->expectException(LogicException::class);

        $movement->delete();
    }

    public function test_owner_can_record_receipt_via_admin_route(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();

        $this->actingAs($owner)
            ->post('/admin/stock/movements', [
                'branch_id' => $branch->id,
                'ingredient_id' => $ingredient->id,
                'quantity' => 100,
                'type' => 'receipt',
                'reason' => 'Terima supplier',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('stock_balances', [
            'branch_id' => $branch->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 100,
        ]);
    }

    public function test_kitchen_cannot_record_receipt_via_admin_route(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();
        $ingredient = Ingredient::factory()->create();

        $this->actingAs($kitchen)
            ->post('/admin/stock/movements', [
                'branch_id' => $branch->id,
                'ingredient_id' => $ingredient->id,
                'quantity' => 100,
                'type' => 'receipt',
                'reason' => 'Terima supplier',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_kitchen_can_record_waste_for_own_branch(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();
        $ingredient = Ingredient::factory()->create();
        StockBalance::factory()->for($branch)->for($ingredient, 'ingredient')->create(['quantity' => 100]);

        $this->actingAs($kitchen)
            ->post('/kitchen/stock/waste', [
                'ingredient_id' => $ingredient->id,
                'quantity' => 10,
                'reason' => 'Rusak',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('stock_balances', [
            'branch_id' => $branch->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 90,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'branch_id' => $branch->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => -10,
            'type' => 'waste',
            'actor_id' => $kitchen->id,
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
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_kitchen_stock_page_lists_low_stock_for_own_branch(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();
        $unit = Unit::factory()->create(['code' => 'g']);
        $low = Ingredient::factory()->create([
            'name' => 'Mozzarella',
            'base_unit_id' => $unit->id,
            'low_stock_threshold' => 100,
        ]);
        $healthy = Ingredient::factory()->create([
            'name' => 'Tepung',
            'base_unit_id' => $unit->id,
            'low_stock_threshold' => 100,
        ]);
        StockBalance::factory()->for($branch)->for($low, 'ingredient')->create(['quantity' => 50]);
        StockBalance::factory()->for($otherBranch)->for($low, 'ingredient')->create(['quantity' => 900]);
        StockBalance::factory()->for($branch)->for($healthy, 'ingredient')->create(['quantity' => 500]);

        $this->actingAs($kitchen)
            ->get('/kitchen/stock')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('kitchen/Stock')
                ->where('branch.id', $branch->id)
                ->has('low_stock', 1)
                ->where('low_stock.0.ingredient_id', $low->id)
                ->where('low_stock.0.quantity', 50)
                ->where('low_stock.0.low_stock_threshold', 100)
                ->has('ingredients', 2)
            );
    }

    public function test_owner_dashboard_lists_low_stock(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $unit = Unit::factory()->create(['code' => 'g']);
        $ingredient = Ingredient::factory()->create([
            'name' => 'Mozzarella',
            'base_unit_id' => $unit->id,
            'low_stock_threshold' => 100,
        ]);
        StockBalance::factory()->for($branch)->for($ingredient, 'ingredient')->create(['quantity' => 25]);

        $this->actingAs($owner)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/Dashboard')
                ->has('low_stock', 1)
                ->where('low_stock.0.ingredient_name', 'Mozzarella')
                ->where('low_stock.0.quantity', 25)
            );
    }
}
