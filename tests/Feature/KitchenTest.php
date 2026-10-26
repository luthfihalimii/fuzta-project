<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class KitchenTest extends TestCase
{
    use RefreshDatabase;

    public function test_kitchen_sees_only_paid_queued_orders_for_own_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $paidA = Order::factory()->paid()->for($branchA)->create();
        Order::factory()->unpaid()->for($branchA)->create();
        Order::factory()->paid()->for($branchB)->create();
        Order::factory()->voided()->for($branchA)->create();
        Order::factory()->paid()->for($branchA)->create(['status' => OrderStatus::Accepted]);
        $kitchen = User::factory()->kitchen()->for($branchA)->create();

        $ids = $this->actingAs($kitchen)
            ->getJson('/kitchen/orders?status=queued')
            ->assertOk()
            ->json('orders.*.id');

        $this->assertSame([$paidA->id], $ids);
    }

    public function test_kitchen_list_excludes_unpaid_and_voided_orders_without_status_filter(): void
    {
        $branch = Branch::factory()->create();
        $paid = Order::factory()->paid()->for($branch)->create();
        Order::factory()->unpaid()->for($branch)->create();
        Order::factory()->voided()->for($branch)->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();

        $ids = $this->actingAs($kitchen)
            ->getJson('/kitchen/orders')
            ->assertOk()
            ->json('orders.*.id');

        $this->assertSame([$paid->id], $ids);
    }

    public function test_kitchen_cannot_switch_branch_with_query(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $paidA = Order::factory()->paid()->for($branchA)->create();
        Order::factory()->paid()->for($branchB)->create();
        $kitchen = User::factory()->kitchen()->for($branchA)->create();

        $ids = $this->actingAs($kitchen)
            ->getJson("/kitchen/orders?branch={$branchB->id}&status=queued")
            ->assertOk()
            ->json('orders.*.id');

        $this->assertSame([$paidA->id], $ids);
    }

    public function test_status_transition_records_actor_and_timestamp(): void
    {
        $order = Order::factory()->paid()->create();
        $kitchen = User::factory()->kitchen()->for($order->branch)->create();

        $this->actingAs($kitchen)
            ->patch("/kitchen/orders/{$order->id}/status", ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('order.status', 'accepted');

        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);

        $event = $order->statusEvents()->firstOrFail();

        $this->assertSame(OrderStatus::Queued, $event->from_status);
        $this->assertSame(OrderStatus::Accepted, $event->to_status);
        $this->assertSame($kitchen->id, $event->actor_id);
        $this->assertNotNull($event->created_at);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $order = Order::factory()->paid()->create(['status' => OrderStatus::Queued]);
        $kitchen = User::factory()->kitchen()->for($order->branch)->create();

        $this->actingAs($kitchen)
            ->patch("/kitchen/orders/{$order->id}/status", ['status' => 'ready'])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::Queued, $order->fresh()->status);
        $this->assertDatabaseCount('order_status_events', 0);
    }

    public function test_unpaid_order_cannot_be_transitioned(): void
    {
        $order = Order::factory()->unpaid()->create(['status' => OrderStatus::Queued]);
        $kitchen = User::factory()->kitchen()->for($order->branch)->create();

        $this->actingAs($kitchen)
            ->patch("/kitchen/orders/{$order->id}/status", ['status' => 'accepted'])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::Queued, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_status_events', 0);
    }

    public function test_kitchen_cannot_transition_another_branch_order(): void
    {
        $order = Order::factory()->paid()->create();
        $kitchen = User::factory()->kitchen()->for(Branch::factory())->create();

        $this->actingAs($kitchen)
            ->patch("/kitchen/orders/{$order->id}/status", ['status' => 'accepted'])
            ->assertForbidden();

        $this->assertSame(OrderStatus::Queued, $order->fresh()->status);
    }

    public function test_cashier_cannot_transition_order(): void
    {
        $order = Order::factory()->paid()->create();
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->patch("/kitchen/orders/{$order->id}/status", ['status' => 'accepted'])
            ->assertForbidden();

        $this->assertSame(OrderStatus::Queued, $order->fresh()->status);
    }

    public function test_owner_can_transition_any_branch_order(): void
    {
        $order = Order::factory()->paid()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->patch("/kitchen/orders/{$order->id}/status", ['status' => 'accepted'])
            ->assertOk();

        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
    }

    public function test_kitchen_page_loads_for_kitchen_user(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();

        $this->actingAs($kitchen)
            ->get('/kitchen')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('kitchen/Index')
                ->where('branch.id', $branch->id)
                ->where('canChooseBranch', false)
                ->has('branches', 0)
            );
    }

    public function test_owner_can_choose_kitchen_branch(): void
    {
        Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get("/kitchen?branch={$branchB->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('kitchen/Index')
                ->where('branch.id', $branchB->id)
                ->where('canChooseBranch', true)
                ->has('branches', 2)
            );
    }

    public function test_owner_kitchen_orders_can_choose_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        Order::factory()->paid()->for($branchA)->create();
        $paidB = Order::factory()->paid()->for($branchB)->create();
        $owner = User::factory()->owner()->create();

        $ids = $this->actingAs($owner)
            ->getJson("/kitchen/orders?branch={$branchB->id}&status=queued")
            ->assertOk()
            ->json('orders.*.id');

        $this->assertSame([$paidB->id], $ids);
    }
}
