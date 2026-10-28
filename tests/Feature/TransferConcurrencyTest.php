<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransferConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_dispatch_and_consumption_do_not_lose_updates(): void
    {
        $origin = Branch::factory()->create();
        $destination = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();
        StockBalance::factory()->for($origin)->for($ingredient, 'ingredient')->create(['quantity' => 100]);

        $transfer = StockTransfer::factory()->create([
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'created_by' => $owner->id,
        ]);
        StockTransferItem::factory()->for($transfer, 'transfer')->for($ingredient, 'ingredient')->create(['quantity' => 60]);

        $pids = [];
        $exitCodes = [];

        $pidA = pcntl_fork();

        if ($pidA === 0) {
            try {
                DB::purge();
                DB::reconnect();
                app(DispatchStockTransfer::class)(User::find($owner->id), $transfer->id);
            } catch (\Throwable) {
                exit(1);
            }
            exit(0);
        }

        $pids[] = $pidA;

        $pidB = pcntl_fork();

        if ($pidB === 0) {
            try {
                DB::purge();
                DB::reconnect();
                app(RecordStockMovement::class)(
                    User::find($owner->id),
                    $origin->id,
                    $ingredient->id,
                    -30,
                    StockMovementType::Consumption,
                    'Konsumsi pesanan TEST'
                );
            } catch (\Throwable) {
                exit(1);
            }
            exit(0);
        }

        $pids[] = $pidB;

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        $this->assertSame([0, 0], $exitCodes);

        $balance = StockBalance::query()
            ->where('branch_id', $origin->id)
            ->where('ingredient_id', $ingredient->id)
            ->firstOrFail();
        $this->assertSame(10, (int) $balance->quantity);

        $this->assertSame(1, StockMovement::where('type', StockMovementType::Consumption->value)->count());
        $this->assertSame(1, StockMovement::where('type', StockMovementType::TransferOut->value)->count());
    }
}
