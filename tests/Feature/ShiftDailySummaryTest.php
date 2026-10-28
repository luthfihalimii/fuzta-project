<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftDailySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_summary_groups_closed_shifts_and_separates_open_and_unreconciled(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();

        CashierShift::factory()->closed()->create([
            'branch_id' => $branch->id,
            'user_id' => $cashier->id,
            'cash_sales_total' => 100000,
            'expected_cash' => 100000,
            'counted_cash' => 100000,
            'variance' => 0,
            'cash_deposited' => 100000,
        ]);

        CashierShift::factory()->autoClosed()->create([
            'branch_id' => $branch->id,
            'user_id' => $cashier->id,
            'window_key' => 'evening',
            'scheduled_start_at' => now('Asia/Jakarta')->startOfDay()->addHours(15)->utc(),
            'scheduled_end_at' => now('Asia/Jakarta')->startOfDay()->addHours(21)->utc(),
            'cash_sales_total' => 50000,
            'expected_cash' => 50000,
        ]);

        $otherCashier = User::factory()->cashier()->for($branch)->create();
        CashierShift::factory()->open()->create([
            'branch_id' => $branch->id,
            'user_id' => $otherCashier->id,
            'window_key' => 'morning',
            'scheduled_start_at' => now('Asia/Jakarta')->startOfDay()->addHours(9)->utc(),
            'scheduled_end_at' => now('Asia/Jakarta')->startOfDay()->addHours(15)->utc(),
        ]);

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/admin/shifts?view=daily')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/shifts/Index')
                ->where('daily.rows.0.cash_sales_total', 150000)
                ->where('daily.rows.0.counted_cash', 100000)
                ->where('daily.rows.0.variance', 0)
                ->where('daily.rows.0.unreconciled_count', 1)
                ->where('daily.rows.0.open_count', 0)
                ->where('daily.rows.1.open_count', 1)
            );
    }

    public function test_cashier_cannot_view_daily_summary(): void
    {
        $cashier = User::factory()->cashier()->for(Branch::factory())->create();

        $this->actingAs($cashier)->get('/admin/shifts?view=daily')->assertForbidden();
    }
}
