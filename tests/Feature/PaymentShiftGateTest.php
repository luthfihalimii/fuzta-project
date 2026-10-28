<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Ordering\Models\Order;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentShiftGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_without_shift_is_rejected(): void
    {
        $order = Order::factory()->unpaid()->create();
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)->post("/orders/{$order->id}/pay", ['method' => 'cash'])
            ->assertSessionHasErrors('shift');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cash_and_qris_payments_store_shift_id(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $cash = Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id, 'total' => 50000,
        ]);
        $qris = Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id, 'total' => 60000,
        ]);

        $this->actingAs($shift->user)->post("/orders/{$cash->id}/pay", [
            'method' => 'cash', 'cash_received' => 100000,
        ])->assertRedirect();
        $this->actingAs($shift->user)->post("/orders/{$qris->id}/pay", [
            'method' => 'qris_static',
        ])->assertRedirect();

        $this->assertDatabaseHas('payments', ['order_id' => $cash->id, 'shift_id' => $shift->id]);
        $this->assertDatabaseHas('payments', ['order_id' => $qris->id, 'shift_id' => $shift->id]);
    }

    public function test_void_on_closed_shift_is_rejected(): void
    {
        $shift = CashierShift::factory()->closed()->create();
        $order = Order::factory()->paid()->create([
            'branch_id' => $shift->branch_id, 'shift_id' => $shift->id,
        ]);
        $order->payment->forceFill(['shift_id' => $shift->id])->save();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/orders/{$order->id}/void", ['reason' => 'salah'])
            ->assertSessionHasErrors('order');

        $this->assertSame('paid', $order->payment->refresh()->status->value);
    }

    public function test_void_on_open_shift_is_allowed(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $order = Order::factory()->paid()->create([
            'branch_id' => $shift->branch_id, 'shift_id' => $shift->id,
        ]);
        $order->payment->forceFill(['shift_id' => $shift->id])->save();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/orders/{$order->id}/void", ['reason' => 'salah'])
            ->assertRedirect();

        $this->assertSame('voided', $order->payment->refresh()->status->value);
    }
}
