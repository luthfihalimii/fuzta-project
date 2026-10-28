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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_clean_ledger(): void
    {
        $this->seedMovements();

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('tidak ada selisih')
            ->assertExitCode(0);
    }

    public function test_reports_discrepancy_without_fixing(): void
    {
        [$branch, $ingredient, $balance] = $this->seedMovements();

        DB::table('stock_balances')->where('id', $balance->id)->update(['quantity' => $balance->quantity + 5]);

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain("{$branch->name} | {$ingredient->name} | saldo 1305 | ledger 1300 | delta -5")
            ->assertExitCode(1);

        $this->assertSame($balance->quantity + 5, (int) $balance->fresh()->quantity);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'stock.reconciled']);
    }

    public function test_fix_rewrites_balance_and_audits_with_null_actor(): void
    {
        [$branch, $ingredient, $balance] = $this->seedMovements();

        DB::table('stock_balances')->where('id', $balance->id)->update(['quantity' => $balance->quantity + 5]);

        $this->artisan('inventory:reconcile', ['--fix' => true])
            ->assertExitCode(0);

        $this->assertSame(1300, (int) $balance->fresh()->quantity);

        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => null,
            'action' => 'stock.reconciled',
            'target_type' => StockBalance::class,
            'target_id' => $balance->id,
            'branch_id' => $branch->id,
            'reason' => 'inventory:reconcile --fix',
            'before' => json_encode(['quantity' => 1305]),
            'after' => json_encode(['quantity' => 1300]),
        ]);

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('tidak ada selisih')
            ->assertExitCode(0);
    }

    public function test_balance_without_movements_counts_as_zero_ledger(): void
    {
        $branch = Branch::factory()->create();
        $unit = Unit::factory()->create(['code' => 'g']);
        $ingredient = Ingredient::factory()->create(['base_unit_id' => $unit->id]);
        $balance = StockBalance::factory()->create([
            'branch_id' => $branch->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 40,
        ]);

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain("{$ingredient->name} | saldo 40 | ledger 0 | delta -40")
            ->assertExitCode(1);

        $this->artisan('inventory:reconcile', ['--fix' => true])->assertExitCode(0);

        $this->assertSame(0, (int) $balance->fresh()->quantity);
    }

    public function test_movements_without_balance_row_count_balance_as_zero(): void
    {
        $branch = Branch::factory()->create();
        $unit = Unit::factory()->create(['code' => 'g']);
        $ingredient = Ingredient::factory()->create(['base_unit_id' => $unit->id]);

        StockMovement::create([
            'branch_id' => $branch->id,
            'ingredient_id' => $ingredient->id,
            'unit_id' => $unit->id,
            'quantity' => 25,
            'type' => StockMovementType::Opening,
            'reason' => 'Stok awal tanpa baris saldo',
            'actor_id' => User::factory()->owner()->create()->id,
        ]);

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain("{$branch->name} | {$ingredient->name} | saldo 0 | ledger 25 | delta 25")
            ->assertExitCode(1);

        $this->artisan('inventory:reconcile', ['--fix' => true])->assertExitCode(0);

        $this->assertDatabaseHas('stock_balances', [
            'branch_id' => $branch->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 25,
        ]);
    }

    /**
     * @return array{0: Branch, 1: Ingredient, 2: StockBalance}
     */
    private function seedMovements(): array
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create(['branch_id' => $branch->id]);
        $unit = Unit::factory()->create(['code' => 'g']);
        $ingredient = Ingredient::factory()->create(['base_unit_id' => $unit->id]);

        app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, 1000, StockMovementType::Opening, 'Stok awal');
        app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, 500, StockMovementType::Receipt, 'Terima supplier');
        app(RecordStockMovement::class)($owner, $branch->id, $ingredient->id, -200, StockMovementType::Waste, 'Rusak');

        $this->assertSame(1300, (int) StockMovement::query()->sum('quantity'));
        $this->assertDatabaseCount('audit_logs', 0);

        $balance = StockBalance::query()
            ->where('branch_id', $branch->id)
            ->where('ingredient_id', $ingredient->id)
            ->firstOrFail();

        return [$branch, $ingredient, $balance];
    }
}
