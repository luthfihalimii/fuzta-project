<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoidOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_void_paid_order_with_reason_and_audit(): void
    {
        $order = Order::factory()->paid()->create(['total' => 170000]);
        $payment = $order->payment;
        $owner = User::factory()->owner()->create();

        $this->assertNotNull($payment);

        $this->actingAs($owner)
            ->post("/orders/{$order->id}/void", ['reason' => 'Salah input item'])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'voided',
        ]);
        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'type' => 'voided',
            'reason' => 'Salah input item',
            'actor_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->id,
            'to_status' => 'cancelled',
            'actor_id' => $owner->id,
            'note' => 'Salah input item',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order.voided',
            'target_id' => $order->id,
            'reason' => 'Salah input item',
            'actor_id' => $owner->id,
        ]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_events', 1);
    }

    public function test_cashier_cannot_void_paid_order(): void
    {
        $order = Order::factory()->paid()->create();
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/void", ['reason' => 'Coba-coba'])
            ->assertForbidden();

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_cashier_can_cancel_unpaid_order_with_audit(): void
    {
        $order = Order::factory()->unpaid()->create();
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/cancel", ['reason' => 'Customer batal'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->id,
            'to_status' => 'cancelled',
            'actor_id' => $cashier->id,
            'note' => 'Customer batal',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order.cancelled',
            'target_id' => $order->id,
            'actor_id' => $cashier->id,
            'reason' => 'Customer batal',
        ]);
    }

    public function test_cashier_cannot_cancel_after_kitchen_accepts(): void
    {
        $order = Order::factory()->unpaid()->create(['status' => OrderStatus::Accepted]);
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/cancel", ['reason' => 'Terlambat'])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
    }

    public function test_cashier_cannot_cancel_paid_order(): void
    {
        $order = Order::factory()->paid()->create();
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/cancel", ['reason' => 'Salah'])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::Queued, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_cashier_cannot_cancel_another_branch_order(): void
    {
        $order = Order::factory()->unpaid()->create();
        $cashier = User::factory()->cashier()->for(Branch::factory())->create();

        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/cancel", ['reason' => 'Coba-coba'])
            ->assertForbidden();

        $this->assertSame(OrderStatus::Queued, $order->fresh()->status);
    }

    public function test_owner_can_cancel_unpaid_order_from_another_branch(): void
    {
        $order = Order::factory()->unpaid()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post("/orders/{$order->id}/cancel", ['reason' => 'Tutup lebih awal'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_owner_cannot_void_unpaid_order(): void
    {
        $order = Order::factory()->unpaid()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post("/orders/{$order->id}/void", ['reason' => 'Salah input'])
            ->assertForbidden();

        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    public function test_void_requires_a_reason(): void
    {
        $order = Order::factory()->paid()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->postJson("/orders/{$order->id}/void", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }
}
