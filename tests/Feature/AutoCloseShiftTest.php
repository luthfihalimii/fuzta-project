<?php

namespace Tests\Feature;

use App\Modules\Ordering\Models\Order;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoCloseShiftTest extends TestCase
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
}
