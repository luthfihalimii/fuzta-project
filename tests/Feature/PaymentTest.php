<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_marking_paid_records_payment_and_calculates_change(): void
    {
        $order = Order::factory()->unpaid()->create(['total' => 170000]);
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/pay", [
                'method' => 'cash',
                'cash_received' => 200000,
            ])->assertRedirect();

        $order->refresh();

        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => 'cash',
            'amount' => 170000,
            'cash_received' => 200000,
            'change_amount' => 30000,
            'status' => 'paid',
        ]);
        $this->assertNotNull($order->payment?->paid_at);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $order->payment->id,
            'type' => 'paid',
            'amount' => 170000,
            'actor_id' => $cashier->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order.paid',
            'target_id' => $order->id,
            'actor_id' => $cashier->id,
        ]);
    }

    public function test_marking_paid_twice_does_not_create_second_payment(): void
    {
        $order = Order::factory()->unpaid()->create(['total' => 170000]);
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/pay", ['method' => 'cash'])
            ->assertRedirect();
        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/pay", ['method' => 'cash'])
            ->assertRedirect();

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_events', 1);
    }

    public function test_cashier_cannot_pay_another_branch_order(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $order = Order::factory()->unpaid()->for($branchB)->create(['total' => 170000]);
        $cashier = User::factory()->cashier()->for($branchA)->create();

        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/pay", ['method' => 'cash'])
            ->assertForbidden();

        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_owner_can_pay_another_branch_order(): void
    {
        $order = Order::factory()->unpaid()->create(['total' => 170000]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post("/orders/{$order->id}/pay", [
                'method' => 'cash',
                'cash_received' => 200000,
            ])->assertRedirect();

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_cashier_cannot_pay_cancelled_order(): void
    {
        $order = Order::factory()->unpaid()->create(['status' => OrderStatus::Cancelled]);
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/pay", ['method' => 'cash'])
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cash_received_must_cover_order_total(): void
    {
        $order = Order::factory()->unpaid()->create(['total' => 170000]);
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->postJson("/orders/{$order->id}/pay", [
                'method' => 'cash',
                'cash_received' => 100000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_received');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_qris_payment_records_method_without_cash(): void
    {
        $order = Order::factory()->unpaid()->create(['total' => 170000]);
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/pay", ['method' => 'qris_static'])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => 'qris_static',
            'amount' => 170000,
            'cash_received' => null,
            'change_amount' => null,
            'status' => 'paid',
        ]);
    }

    public function test_qris_payment_rejects_cash_amount(): void
    {
        $order = Order::factory()->unpaid()->create(['total' => 170000]);
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->postJson("/orders/{$order->id}/pay", [
                'method' => 'qris_static',
                'cash_received' => 200000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_received');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_pos_page_lists_only_products_available_at_the_cashier_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branchA)->create();
        $category = Category::factory()->create(['name' => 'Pizza']);

        $available = Product::factory()->for($category)->create([
            'name' => 'Pizza Tersedia',
            'base_price' => 50000,
        ]);
        $available->branches()->attach($branchA, ['is_available' => true]);
        $available->variants()->create(['name' => 'Large', 'price' => 75000]);
        $group = ModifierGroup::factory()->create([
            'name' => 'Topping',
            'min_select' => 0,
            'max_select' => 2,
        ]);
        $group->modifiers()->create(['name' => 'Extra Cheese', 'price_delta' => 10000]);
        $available->modifierGroups()->attach($group);

        $otherBranch = Product::factory()->for($category)->create(['name' => 'Pizza Cabang Lain']);
        $otherBranch->branches()->attach($branchB, ['is_available' => true]);

        $inactive = Product::factory()->for($category)->create([
            'name' => 'Pizza Nonaktif',
            'is_active' => false,
        ]);
        $inactive->branches()->attach($branchA, ['is_available' => true]);

        $unavailable = Product::factory()->for($category)->create(['name' => 'Pizza Habis']);
        $unavailable->branches()->attach($branchA, ['is_available' => false]);

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('pos/Index')
                ->where('branch.id', $branchA->id)
                ->has('products', 1)
                ->where('products.0.id', $available->id)
                ->where('products.0.category_name', 'Pizza')
                ->where('products.0.variants.0.name', 'Large')
                ->where('products.0.modifier_groups.0.modifiers.0.name', 'Extra Cheese')
                ->has('categories', 1)
            );
    }

    public function test_owner_can_choose_pos_branch(): void
    {
        Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get("/pos?branch={$branchB->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('branch.id', $branchB->id)
                ->where('canChooseBranch', true)
                ->has('branches', 2)
            );
    }

    public function test_orders_page_lists_only_own_branch_today_with_action_flags(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branchA)->create();

        $today = Order::factory()->unpaid()->for($branchA)->create(['total' => 170000]);
        Order::factory()->unpaid()->for($branchB)->create();
        $old = Order::factory()->unpaid()->for($branchA)->create();
        $old->forceFill(['created_at' => now('Asia/Jakarta')->subDay()->utc()])->save();

        $this->actingAs($cashier)
            ->get('/pos/orders')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('pos/Orders')
                ->where('branch.id', $branchA->id)
                ->has('orders', 1)
                ->where('orders.0.id', $today->id)
                ->where('orders.0.can.pay', true)
                ->where('orders.0.can.cancel', true)
                ->where('orders.0.can.void', false)
            );
    }

    public function test_kitchen_cannot_access_pos_orders(): void
    {
        $kitchen = User::factory()->kitchen()->for(Branch::factory())->create();

        $this->actingAs($kitchen)->get('/pos/orders')->assertForbidden();
    }
}
