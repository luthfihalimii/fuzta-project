<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

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
        $exitCodes = [];

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
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        $this->assertSame([0, 0], $exitCodes);
        $this->assertDatabaseHas('stock_balances', ['quantity' => 40]);
        $this->assertSame(2, StockMovement::where('type', 'waste')->count());
    }
}
