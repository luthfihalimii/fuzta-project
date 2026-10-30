<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Actions\RecordPurchase;
use App\Modules\Purchasing\Data\RecordPurchaseData;
use App\Modules\Purchasing\Models\Purchase;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_receipts_accrue_stock_without_lost_updates(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->create(['branch_id' => $branch->id]);
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $pids = [];
        $exitCodes = [];

        foreach ([1, 2] as $_) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                try {
                    DB::purge();
                    DB::reconnect();

                    app(RecordPurchase::class)(
                        User::find($kitchen->id),
                        new RecordPurchaseData(
                            branchId: $branch->id,
                            supplierId: $supplier->id,
                            receivedAt: now()->toDateString(),
                            reference: null,
                            idempotencyKey: Str::uuid()->toString(),
                            items: [[
                                'ingredient_id' => $ingredient->id,
                                'unit_id' => $ingredient->base_unit_id,
                                'quantity' => 100,
                                'unit_price' => 1000,
                            ]],
                        ),
                    );
                } catch (\Throwable) {
                    exit(1);
                }

                exit(0);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        $this->assertSame([0, 0], $exitCodes);
        $this->assertDatabaseHas('stock_balances', [
            'branch_id' => $branch->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 200,
        ]);
        $this->assertSame(2, StockMovement::where('type', StockMovementType::Receipt->value)->count());
        $this->assertSame(
            2,
            StockMovement::where('type', StockMovementType::Receipt->value)
                ->distinct()
                ->count('reference_id'),
        );
        $this->assertSame(2, Purchase::count());
    }
}
