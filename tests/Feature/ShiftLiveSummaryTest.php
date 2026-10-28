<?php

namespace Tests\Feature;

use App\Modules\Ordering\Models\Order;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftLiveSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_shows_live_expected_cash_for_open_shift(): void
    {
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 100000]);
        $order = Order::factory()->paid()->for($shift->branch)->create([
            'shift_id' => $shift->id, 'total' => 50000,
        ]);
        $order->payment->forceFill([
            'shift_id' => $shift->id, 'method' => 'cash', 'amount' => 50000,
        ])->save();

        $this->actingAs($shift->user)->get('/pos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shift.cash_sales_total', 50000)
                ->where('shift.expected_cash', 150000));
    }
}
