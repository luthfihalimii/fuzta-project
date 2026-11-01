<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Delivery\Actions\CreateBiteshipShipment;
use App\Modules\Delivery\Jobs\CreateShipmentJob;
use App\Modules\Kitchen\Actions\TransitionOrderStatus;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Events\OrderStatusChanged;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BiteshipDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const BITESHIP = [
        'api_key' => 'KEY-TEST',
        'base_url' => 'https://api.biteship.com',
        'couriers' => ['gojek'],
        'shipper_name' => 'Kirim Pizza Fruzta',
        'shipper_phone' => '081100000001',
        'shipper_email' => 'ops@pizzafruzta.test',
        'webhook_key' => 'X-Biteship-Signature',
        'webhook_secret' => 'WH-SECRET-TEST',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.biteship' => self::BITESHIP]);
    }

    // ============ queue saat ready ============

    public function test_ready_paid_delivery_order_queues_shipment_job(): void
    {
        Queue::fake();
        $order = $this->deliveryOrder(['status' => OrderStatus::Queued]);
        $kitchen = User::factory()->kitchen()->for($order->branch)->create();

        app(TransitionOrderStatus::class)($order, $kitchen, OrderStatus::Accepted);
        app(TransitionOrderStatus::class)($order->fresh(), $kitchen, OrderStatus::Preparing);
        app(TransitionOrderStatus::class)($order->fresh(), $kitchen, OrderStatus::Ready);

        Queue::assertPushed(CreateShipmentJob::class, fn (CreateShipmentJob $job): bool => $job->orderId === $order->id);
    }

    public function test_pickup_ready_does_not_queue_shipment(): void
    {
        Queue::fake();
        $order = $this->deliveryOrder([
            'channel' => OrderChannel::Takeaway,
            'status' => OrderStatus::Preparing,
        ]);

        event(new OrderStatusChanged($order, OrderStatus::Preparing, OrderStatus::Ready));

        Queue::assertNotPushed(CreateShipmentJob::class);
    }

    public function test_unpaid_delivery_order_never_queues_shipment(): void
    {
        Queue::fake();
        $order = $this->deliveryOrder(['status' => OrderStatus::Preparing])->fresh();
        $order->forceFill(['payment_status' => PaymentStatus::Unpaid])->save();
        $order->payment?->forceFill(['status' => PaymentStatus::Pending])->save();

        event(new OrderStatusChanged($order, OrderStatus::Preparing, OrderStatus::Ready));

        Queue::assertNotPushed(CreateShipmentJob::class);
    }

    public function test_already_shipped_order_does_not_queue_again_on_ready(): void
    {
        Queue::fake();
        $order = $this->deliveryOrder([
            'status' => OrderStatus::Preparing,
            'biteship_order_id' => 'BIT-EXIST',
            'delivery_status' => 'requested',
        ]);

        event(new OrderStatusChanged($order, OrderStatus::Preparing, OrderStatus::Ready));

        Queue::assertNotPushed(CreateShipmentJob::class);
    }

    // ============ job create shipment ============

    public function test_job_creates_shipment_and_stores_fields(): void
    {
        Http::fake(['api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'id' => 'BIT-1',
            'courier' => ['tracking_id' => 'TRK1', 'waybill_id' => 'WB1', 'company' => 'gojek', 'type' => 'instant'],
            'status' => 'confirmed',
        ])]);
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready]);

        (new CreateShipmentJob($order->id))->handle(app(CreateBiteshipShipment::class));

        Http::assertSent(function ($req) use ($order): bool {
            $body = json_decode((string) $req->body(), true) ?? [];

            return $req->url() === 'https://api.biteship.com/v1/orders'
                && $req->hasHeader('Authorization', 'Bearer KEY-TEST')
                && ($body['reference_id'] ?? null) === $order->order_number
                && ($body['delivery_type'] ?? null) === 'now'
                && ($body['origin_area_id'] ?? null) === 'IDNP10'
                && ($body['destination_area_id'] ?? null) === 'IDNP10'
                && ($body['courier_company'] ?? null) === 'gojek'
                && ($body['courier_type'] ?? null) === 'instant'
                && ($body['order_note'] ?? null) === 'Tanpa bawang'
                && ($body['shipper_contact_name'] ?? null) === self::BITESHIP['shipper_name']
                && ($body['origin_contact_name'] ?? null) === $order->branch->name
                && ($body['destination_contact_name'] ?? null) === 'Budi Santoso'
                && count($body['items'] ?? []) === 1
                && ($body['items'][0]['name'] ?? null) === 'Pizza Margherita'
                && ($body['items'][0]['value'] ?? null) === 100000
                && ($body['items'][0]['quantity'] ?? null) === 2
                // weight_grams null → fallback 500 g × qty 2.
                && ($body['items'][0]['weight'] ?? null) === 1000;
        });

        $fresh = $order->fresh();

        $this->assertSame('BIT-1', $fresh->biteship_order_id);
        $this->assertSame('https://track.biteship.com/WB1', $fresh->tracking_url);
        $this->assertSame('requested', $fresh->delivery_status);
        $this->assertSame(OrderStatus::Ready, $fresh->status);
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
    }

    public function test_second_job_run_does_not_post_again(): void
    {
        Http::fake(['api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'id' => 'BIT-1',
            'courier' => ['waybill_id' => 'WB1', 'company' => 'gojek', 'type' => 'instant'],
            'status' => 'confirmed',
        ])]);
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready]);
        $job = new CreateShipmentJob($order->id);
        $action = app(CreateBiteshipShipment::class);

        $job->handle($action);
        $job->handle($action);

        Http::assertSentCount(1);
        $this->assertSame('BIT-1', $order->fresh()->biteship_order_id);
        $this->assertSame('requested', $order->fresh()->delivery_status);
    }

    public function test_shipment_failure_marks_failed_without_corrupting_order(): void
    {
        // 500 (provider down) lalu 400 (payload salah): keduanya → failed,
        // order tetap paid/ready, tanpa exception yang lolos ke queue.
        Http::fake(['api.biteship.com/v1/orders' => Http::sequence()
            ->push(['error' => 'down'], 500)
            ->push(['error' => 'bad area'], 400)]);
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready]);
        $job = new CreateShipmentJob($order->id);
        $action = app(CreateBiteshipShipment::class);

        $job->handle($action);
        $this->assertSame('failed', $order->fresh()->delivery_status);
        $this->assertNull($order->fresh()->biteship_order_id);

        $job->handle($action);
        $fresh = $order->fresh();

        $this->assertSame('failed', $fresh->delivery_status);
        $this->assertNull($fresh->biteship_order_id);
        $this->assertSame(OrderStatus::Ready, $fresh->status);
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
        Http::assertSentCount(2);
    }

    // ============ webhook ============

    public function test_webhook_rejects_wrong_or_missing_header(): void
    {
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready, 'biteship_order_id' => 'BIT-1', 'delivery_status' => 'picked']);
        $body = ['event' => 'order.status', 'order_id' => 'BIT-1', 'status' => 'delivered'];

        $this->postJson('/api/v1/webhooks/biteship', $body, ['X-Biteship-Signature' => 'WRONG'])
            ->assertForbidden();
        $this->postJson('/api/v1/webhooks/biteship', $body)
            ->assertForbidden();

        $fresh = $order->fresh();
        $this->assertSame('picked', $fresh->delivery_status);
        $this->assertSame(OrderStatus::Ready, $fresh->status);
    }

    public function test_webhook_unknown_biteship_order_id_returns_404(): void
    {
        $this->postJson('/api/v1/webhooks/biteship', [
            'event' => 'order.status',
            'order_id' => 'UNKNOWN',
            'status' => 'delivered',
        ], ['X-Biteship-Signature' => self::BITESHIP['webhook_secret']])
            ->assertNotFound();
    }

    public function test_webhook_delivered_completes_order_without_stock_movement(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready, 'biteship_order_id' => 'BIT-1', 'delivery_status' => 'dropping_off']);
        $movementsBefore = DB::table('stock_movements')->count();

        $this->postJson('/api/v1/webhooks/biteship', [
            'event' => 'order.status',
            'order_id' => 'BIT-1',
            'status' => 'delivered',
        ], ['X-Biteship-Signature' => self::BITESHIP['webhook_secret']])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Completed, $fresh->status);
        $this->assertSame('delivered', $fresh->delivery_status);

        // Delivered tidak boleh mengonsumsi bahan lagi.
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());

        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $event): bool => $event->from === OrderStatus::Ready
            && $event->to === OrderStatus::Completed);

        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Ready->value,
            'to_status' => OrderStatus::Completed->value,
            'actor_id' => null,
        ]);
    }

    public function test_webhook_delivered_replay_does_not_transition_again(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready, 'biteship_order_id' => 'BIT-1', 'delivery_status' => 'dropping_off']);
        $body = ['event' => 'order.status', 'order_id' => 'BIT-1', 'status' => 'delivered'];
        $headers = ['X-Biteship-Signature' => self::BITESHIP['webhook_secret']];

        $this->postJson('/api/v1/webhooks/biteship', $body, $headers)->assertOk();
        $this->postJson('/api/v1/webhooks/biteship', $body, $headers)->assertOk();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(1, $order->fresh()->statusEvents()->count());
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    }

    public function test_webhook_cancelled_maps_delivery_status_without_completing_order(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready, 'biteship_order_id' => 'BIT-1', 'delivery_status' => 'picked']);

        $this->postJson('/api/v1/webhooks/biteship', [
            'event' => 'order.status',
            'order_id' => 'BIT-1',
            'status' => 'cancelled',
            'courier_waybill_id' => 'WBX',
        ], ['X-Biteship-Signature' => self::BITESHIP['webhook_secret']])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->delivery_status);
        $this->assertSame('https://track.biteship.com/WBX', $fresh->tracking_url);
        $this->assertSame(OrderStatus::Ready, $fresh->status);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_webhook_waybill_event_updates_tracking_url(): void
    {
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready, 'biteship_order_id' => 'BIT-1', 'delivery_status' => 'allocated', 'tracking_url' => null]);

        $this->postJson('/api/v1/webhooks/biteship', [
            'event' => 'order.waybill_id',
            'order_id' => 'BIT-1',
            'status' => 'picked',
            'courier_waybill_id' => 'WB-NEW',
        ], ['X-Biteship-Signature' => self::BITESHIP['webhook_secret']])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('picked', $fresh->delivery_status);
        $this->assertSame('https://track.biteship.com/WB-NEW', $fresh->tracking_url);
    }

    // ============ retry ============

    public function test_retry_endpoint_owner_only_and_only_when_failed(): void
    {
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready, 'delivery_status' => 'failed']);
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->postJson("/admin/orders/{$order->id}/delivery/retry")
            ->assertForbidden();

        Queue::fake();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->postJson("/admin/orders/{$order->id}/delivery/retry")
            ->assertOk();

        Queue::assertPushed(CreateShipmentJob::class, fn (CreateShipmentJob $job): bool => $job->orderId === $order->id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order.delivery_retried',
            'target_id' => $order->id,
            'actor_id' => $owner->id,
        ]);

        // Bukan failed → 422.
        $done = $this->deliveryOrder(['status' => OrderStatus::Ready, 'biteship_order_id' => 'BIT-2', 'delivery_status' => 'delivered']);
        $this->actingAs($owner)
            ->postJson("/admin/orders/{$done->id}/delivery/retry")
            ->assertStatus(422);
        Queue::assertNotPushed(CreateShipmentJob::class, fn (CreateShipmentJob $job): bool => $job->orderId === $done->id);
    }

    // ============ helpers ============

    /**
     * Order delivery paid via API-style data (placed_by null, customer name/
     * HP, courier tersimpan) + 1 item qty 2 tanpa resep (transisi Preparing
     * tidak menyentuh stok).
     */
    private function deliveryOrder(array $attributes = []): Order
    {
        $branch = Branch::factory()->create(['delivery_area_id' => 'IDNP10']);
        $product = Product::factory()->create(['name' => 'Pizza Margherita', 'weight_grams' => null]);

        $order = Order::factory()->paid()
            ->for($branch)
            ->create($attributes + [
                'channel' => OrderChannel::Delivery,
                'placed_by_id' => null,
                'customer_name' => 'Budi Santoso',
                'customer_phone' => '081234567890',
                'delivery_address' => 'Jl. Contoh No 1',
                'delivery_area_id' => 'IDNP10',
                'delivery_fee' => 20000,
                'courier_name' => 'gojek',
                'courier_service' => 'instant',
                'note' => 'Tanpa bawang',
            ]);

        $order->payment?->forceFill([
            'method' => PaymentMethod::DokuQris,
            'provider' => 'doku',
            'provider_reference' => $order->order_number,
        ])->save();

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price' => 50000,
            'line_total' => 100000,
        ]);

        return $order;
    }
}
