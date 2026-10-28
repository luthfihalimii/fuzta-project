<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Kitchen\Actions\TransitionOrderStatus;
use App\Modules\Ordering\Actions\CancelOrder;
use App\Modules\Ordering\Actions\CreateOrder;
use App\Modules\Ordering\Actions\VoidOrder;
use App\Modules\Ordering\Data\CreateOrderData;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Events\OrderCreated;
use App\Modules\Ordering\Events\OrderPaymentChanged;
use App\Modules\Ordering\Events\OrderStatusChanged;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Actions\MarkOrderPaid;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Shifts\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastOrderEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pola yang sama dengan OrderCreationTest: beku di 10:00 WIB agar
        // gerbang cutoff shift CreateOrder tidak bergantung wall-clock.
        CarbonImmutable::setTestNow(CarbonImmutable::now('Asia/Jakarta')->startOfDay()->addHours(10));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_order_creation_broadcasts_to_both_branch_channels_with_locked_payload(): void
    {
        $this->fakeBroadcastEvents();
        [$branchA] = $this->branches();
        [$order] = $this->createOrderIn($branchA, 'bcast-create-1');

        Event::assertDispatched(OrderCreated::class, function (OrderCreated $event) use ($order, $branchA): bool {
            $this->assertSame([
                'kind' => 'created',
                'order_number' => $order->order_number,
                'branch_id' => $order->branch_id,
                'created_at' => $order->created_at->toIso8601String(),
                'accepted_at' => null,
            ], $event->broadcastWith());

            $channels = $event->broadcastOn();

            foreach ($channels as $channel) {
                $this->assertInstanceOf(PrivateChannel::class, $channel);
            }

            $this->assertSame(
                ['private-kitchen.'.$branchA->id, 'private-pos.'.$branchA->id],
                array_map(fn (Channel $channel): string => $channel->name, $channels),
            );

            $this->assertSame('order-created', $event->broadcastAs());

            // Tanpa interface ini event() tidak pernah diantri ke broadcaster:
            // semua asersi di atas tetap hijau tapi klien tak menerima apa pun.
            $this->assertInstanceOf(ShouldBroadcast::class, $event);

            return true;
        });

        Event::assertDispatchedTimes(OrderCreated::class, 1);
        Event::assertNotDispatched(OrderStatusChanged::class);
        Event::assertNotDispatched(OrderPaymentChanged::class);
    }

    public function test_idempotent_replay_does_not_broadcast_again(): void
    {
        [$branchA] = $this->branches();
        [$cashier, $data] = $this->orderDataFor($branchA, 'bcast-idem-1');

        $this->fakeBroadcastEvents();

        $first = app(CreateOrder::class)($cashier, $data);
        $second = app(CreateOrder::class)($cashier, $data);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('orders', 1);
        Event::assertDispatchedTimes(OrderCreated::class, 1);
    }

    public function test_marking_paid_broadcasts_order_payment_changed(): void
    {
        [$branchA] = $this->branches();
        [$order, $cashier] = $this->createOrderIn($branchA, 'bcast-pay-1');

        $this->fakeBroadcastEvents();

        app(MarkOrderPaid::class)($order, $cashier, PaymentMethod::Cash, 100000);

        Event::assertDispatched(OrderPaymentChanged::class, function (OrderPaymentChanged $event) use ($order): bool {
            $this->assertSame([
                'kind' => 'payment',
                'order_number' => $order->order_number,
                'branch_id' => $order->branch_id,
                'created_at' => $order->created_at->toIso8601String(),
                'accepted_at' => null,
            ], $event->broadcastWith());

            $this->assertSame('order-payment-changed', $event->broadcastAs());

            return true;
        });

        Event::assertNotDispatched(OrderCreated::class);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_marking_paid_twice_does_not_rebroadcast(): void
    {
        [$branchA] = $this->branches();
        [$order, $cashier] = $this->createOrderIn($branchA, 'bcast-pay-2');

        $this->fakeBroadcastEvents();

        $first = app(MarkOrderPaid::class)($order, $cashier, PaymentMethod::Cash, 100000);
        $second = app(MarkOrderPaid::class)($order, $cashier, PaymentMethod::Cash, 100000);

        $this->assertSame($first->id, $second->id);
        Event::assertDispatchedTimes(OrderPaymentChanged::class, 1);
    }

    public function test_transition_to_accepted_sets_accepted_at_once_and_broadcasts_status(): void
    {
        [$branchA] = $this->branches();
        [$order, $cashier] = $this->createOrderIn($branchA, 'bcast-trans-1');
        app(MarkOrderPaid::class)($order, $cashier, PaymentMethod::Cash, 100000);
        $kitchen = User::factory()->kitchen()->for($branchA)->create();

        $this->assertNull($order->fresh()->accepted_at);

        $this->fakeBroadcastEvents();

        app(TransitionOrderStatus::class)($order, $kitchen, OrderStatus::Accepted);

        Event::assertDispatched(OrderStatusChanged::class, function (OrderStatusChanged $event) use ($order): bool {
            $this->assertSame([
                'kind' => 'status',
                'order_number' => $order->order_number,
                'branch_id' => $order->branch_id,
                'from' => 'queued',
                'to' => 'accepted',
                'created_at' => $order->created_at->toIso8601String(),
                'accepted_at' => $order->fresh()->accepted_at->toIso8601String(),
            ], $event->broadcastWith());

            $this->assertSame('order-status-changed', $event->broadcastAs());

            return true;
        });

        $t1 = $order->fresh()->accepted_at;

        $this->assertNotNull($t1);

        app(TransitionOrderStatus::class)($order, $kitchen, OrderStatus::Preparing);

        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $event): bool => $event->from === OrderStatus::Accepted
            && $event->to === OrderStatus::Preparing);

        Event::assertDispatchedTimes(OrderStatusChanged::class, 2);

        $t2 = $order->fresh()->accepted_at;

        $this->assertSame($t1->toIso8601String(), $t2->toIso8601String());
    }

    public function test_cancel_unpaid_order_broadcasts_status_only(): void
    {
        [$branchA] = $this->branches();
        [$order, $cashier] = $this->createOrderIn($branchA, 'bcast-cancel-1');

        $this->fakeBroadcastEvents();

        app(CancelOrder::class)($order, $cashier, 'pelanggan batal');

        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $event): bool => $event->broadcastWith()['to'] === OrderStatus::Cancelled->value);
        Event::assertNotDispatched(OrderPaymentChanged::class);
    }

    public function test_void_paid_order_broadcasts_status_and_payment(): void
    {
        [$branchA] = $this->branches();
        [$order, $cashier] = $this->createOrderIn($branchA, 'bcast-void-1');
        app(MarkOrderPaid::class)($order, $cashier, PaymentMethod::Cash, 100000);
        $kitchen = User::factory()->kitchen()->for($branchA)->create();
        app(TransitionOrderStatus::class)($order, $kitchen, OrderStatus::Accepted);

        $this->fakeBroadcastEvents();

        app(VoidOrder::class)($order, $cashier, 'salah input');

        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $event): bool => $event->broadcastWith()['to'] === OrderStatus::Cancelled->value);
        Event::assertDispatched(OrderPaymentChanged::class, fn (OrderPaymentChanged $event): bool => $event->broadcastWith() === [
            'kind' => 'payment',
            'order_number' => $order->order_number,
            'branch_id' => $order->branch_id,
            'created_at' => $order->created_at->toIso8601String(),
            'accepted_at' => $order->fresh()->accepted_at->toIso8601String(),
        ]);
    }

    private function fakeBroadcastEvents(): void
    {
        // Laravel 13 tidak punya Broadcast::fake(); Event::fake() pada tiga
        // event broadcast ini ekuivalen (emit lewat helper event()).
        Event::fake([
            OrderCreated::class,
            OrderStatusChanged::class,
            OrderPaymentChanged::class,
        ]);
    }

    /**
     * @return array{0: Branch, 1: Branch}
     */
    private function branches(): array
    {
        return [Branch::factory()->create(), Branch::factory()->create()];
    }

    /**
     * @return array{0: Order, 1: User}
     */
    private function createOrderIn(Branch $branch, string $idempotencyKey): array
    {
        [$cashier, $data] = $this->orderDataFor($branch, $idempotencyKey);

        return [app(CreateOrder::class)($cashier, $data), $cashier];
    }

    /**
     * @return array{0: User, 1: CreateOrderData}
     */
    private function orderDataFor(Branch $branch, string $idempotencyKey): array
    {
        $cashier = User::factory()->cashier()->for($branch)->create();
        CashierShift::factory()->open()->for($cashier, 'user')->create([
            'branch_id' => $branch->id,
        ]);
        [$product, $large] = $this->pizzaForBranch($branch);

        return [$cashier, new CreateOrderData(
            branchId: $branch->id,
            channel: OrderChannel::Takeaway,
            tableNumber: null,
            customerName: null,
            note: null,
            items: [[
                'product_id' => $product->id,
                'variant_id' => $large->id,
                'modifier_ids' => [],
                'quantity' => 1,
                'note' => null,
            ]],
            idempotencyKey: $idempotencyKey,
        )];
    }

    /**
     * @return array{0: Product, 1: ProductVariant, 2: Modifier}
     */
    private function pizzaForBranch(Branch $branch): array
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create([
            'name' => 'Pizza Margherita',
            'base_price' => 50000,
        ]);
        $large = $product->variants()->create([
            'name' => 'Large',
            'price' => 75000,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $group = ModifierGroup::factory()->create([
            'name' => 'Cheese',
            'min_select' => 0,
            'max_select' => 1,
            'is_required' => false,
        ]);
        $cheese = $group->modifiers()->create([
            'name' => 'Extra Cheese',
            'price_delta' => 10000,
            'is_active' => true,
        ]);
        $product->modifierGroups()->attach($group);
        $product->branches()->attach($branch, ['is_available' => true]);

        return [$product, $large, $cheese];
    }
}
