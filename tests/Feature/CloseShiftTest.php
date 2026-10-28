<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Ordering\Models\Order;
use App\Modules\Shifts\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseShiftTest extends TestCase
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

    public function test_close_freezes_totals_and_variance(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 15:30', 'Asia/Jakarta'));
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 200000]);
        $this->cashOrder($shift, 150000);

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 345000,
            'cash_deposited' => 145000,
            'closing_note' => 'Selisih kurang 5000',
        ])->assertRedirect();

        CarbonImmutable::setTestNow();

        $shift->refresh();
        $this->assertSame(150000, $shift->cash_sales_total);
        $this->assertSame(350000, $shift->expected_cash);
        $this->assertSame(-5000, $shift->variance);
        $this->assertSame(145000, $shift->cash_deposited);
        $this->assertSame('manual', $shift->close_type->value);
        $this->assertNotNull($shift->closed_at);
        $this->assertNotNull($shift->reconciled_at);
    }

    public function test_variance_requires_note(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 15:30', 'Asia/Jakarta'));
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 100000]);

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 90000,
            'cash_deposited' => 0,
        ])->assertSessionHasErrors('closing_note');

        CarbonImmutable::setTestNow();

        $this->assertNull($shift->refresh()->closed_at);
    }

    public function test_unpaid_order_blocks_close(): void
    {
        $shift = CashierShift::factory()->open()->create();
        Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id, 'shift_id' => $shift->id,
        ]);

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 0, 'cash_deposited' => 0,
        ])->assertSessionHasErrors('shift');
    }

    public function test_cancelled_unpaid_order_does_not_block(): void
    {
        $shift = CashierShift::factory()->open()->create();
        Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $shift->id,
            'status' => 'cancelled',
        ]);

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 0, 'cash_deposited' => 0,
        ])->assertRedirect();
    }

    public function test_cashier_early_close_is_rejected(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 12:00', 'Asia/Jakarta'));
        $shift = CashierShift::factory()->open()->create();

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 0, 'cash_deposited' => 0, 'closing_note' => 'pulang',
        ])->assertSessionHasErrors('shift');

        CarbonImmutable::setTestNow();
    }

    public function test_owner_early_close_requires_note(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 12:00', 'Asia/Jakarta'));
        $shift = CashierShift::factory()->open()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/close", [
            'counted_cash' => 0, 'cash_deposited' => 0,
        ])->assertSessionHasErrors('closing_note');

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/close", [
            'counted_cash' => 0, 'cash_deposited' => 0, 'closing_note' => 'Tutup awal',
        ])->assertRedirect();

        CarbonImmutable::setTestNow();
    }

    public function test_deposit_cannot_exceed_counted(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 15:30', 'Asia/Jakarta'));
        $shift = CashierShift::factory()->open()->create();

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 10000, 'cash_deposited' => 20000,
        ])->assertSessionHasErrors('cash_deposited');

        CarbonImmutable::setTestNow();
    }

    public function test_second_close_is_noop(): void
    {
        $shift = CashierShift::factory()->closed()->create();
        $closedAt = $shift->closed_at;

        $this->actingAs($shift->user)->post('/pos/shift/close', [
            'counted_cash' => 1, 'cash_deposited' => 0,
        ])->assertRedirect();

        $this->assertTrue($closedAt->equalTo($shift->refresh()->closed_at));
        $this->assertSame(1, CashierShift::count());
    }
}
