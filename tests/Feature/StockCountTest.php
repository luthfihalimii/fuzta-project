<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Actions\SubmitStockCount;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCountTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertDatabaseHas('stock_counts', [
            'id' => $count->id,
            'branch_id' => $branch->id,
            'submitted_by_id' => $kitchen->id,
            'note' => 'Opname tutup',
        ]);
        $this->assertDatabaseHas('stock_count_items', [
            'stock_count_id' => $count->id,
            'ingredient_id' => $mozzarella->id,
            'system_quantity' => 1000,
            'counted_quantity' => 900,
            'difference' => -100,
        ]);
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

    public function test_count_without_difference_records_item_but_no_movement(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();
        StockBalance::factory()->for($branch)->for($ingredient, 'ingredient')->create(['quantity' => 1000]);

        $count = app(SubmitStockCount::class)(
            $owner,
            $branch->id,
            [['ingredient_id' => $ingredient->id, 'counted_quantity' => 1000]],
        );

        $this->assertSame(1, $count->items()->count());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stock_count_writes_audit(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();
        $ingredient = Ingredient::factory()->create();
        StockBalance::factory()->for($branch)->for($ingredient, 'ingredient')->create(['quantity' => 100]);

        $count = app(SubmitStockCount::class)(
            $kitchen,
            $branch->id,
            [['ingredient_id' => $ingredient->id, 'counted_quantity' => 90]],
            'Opname tutup',
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stock.count_submitted',
            'actor_id' => $kitchen->id,
            'branch_id' => $branch->id,
            'target_type' => $count::class,
            'target_id' => $count->id,
            'reason' => 'Opname tutup',
        ]);
    }

    public function test_kitchen_cannot_submit_count_for_another_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branchA)->create();
        $ingredient = Ingredient::factory()->create();

        $this->actingAs($kitchen)
            ->post('/kitchen/stock/counts', [
                'branch_id' => $branchB->id,
                'counted_items' => [
                    ['ingredient_id' => $ingredient->id, 'counted_quantity' => 10],
                ],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('stock_counts', 0);
        $this->assertDatabaseCount('stock_count_items', 0);
    }

    public function test_owner_can_submit_count_for_any_branch(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();
        StockBalance::factory()->for($branch)->for($ingredient, 'ingredient')->create(['quantity' => 100]);

        $this->actingAs($owner)
            ->post('/admin/stock/counts', [
                'branch_id' => $branch->id,
                'counted_items' => [
                    ['ingredient_id' => $ingredient->id, 'counted_quantity' => 80],
                ],
                'note' => 'Opname pemilik',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('stock_balances', [
            'branch_id' => $branch->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 80,
        ]);
        $this->assertDatabaseHas('stock_counts', [
            'branch_id' => $branch->id,
            'note' => 'Opname pemilik',
        ]);
    }

    public function test_negative_counted_quantity_is_rejected(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();

        $this->actingAs($owner)
            ->post('/admin/stock/counts', [
                'branch_id' => $branch->id,
                'counted_items' => [
                    ['ingredient_id' => $ingredient->id, 'counted_quantity' => -1],
                ],
            ])
            ->assertSessionHasErrors('counted_items.0.counted_quantity');

        $this->assertDatabaseCount('stock_counts', 0);
    }
}
