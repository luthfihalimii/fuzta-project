<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Delivery\Actions\CreateBiteshipShipment;
use App\Modules\Delivery\Jobs\CreateShipmentJob;
use App\Modules\Inventory\Actions\ConsumeOrderIngredients;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use LogicException;
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

    public function test_duplicate_reference_adopted_on_retry_after_provider_error(): void
    {
        // 500 (provider down) → failed; retry dapat 400 duplicate
        // reference (40002060) → adopsi order kurir yang sudah ada.
        Http::fake(['api.biteship.com/v1/orders' => Http::sequence()
            ->push(['error' => 'down'], 500)
            ->push([
                'error_code' => 40002060,
                'details' => ['order_id' => 'BIT-9', 'waybill_id' => 'WB9', 'reference_id' => 'PF-0001'],
            ], 400)]);
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready]);
        $job = new CreateShipmentJob($order->id);
        $action = app(CreateBiteshipShipment::class);

        $job->handle($action);
        $this->assertSame('failed', $order->fresh()->delivery_status);

        $job->handle($action);

        $fresh = $order->fresh();
        $this->assertSame('BIT-9', $fresh->biteship_order_id);
        $this->assertSame('https://track.biteship.com/WB9', $fresh->tracking_url);
        $this->assertSame('requested', $fresh->delivery_status);
        Http::assertSentCount(2);
    }

    public function test_4xx_other_than_duplicate_reference_stays_failed(): void
    {
        // Ada field `code`, tapi bukan 40002060 → tetap gagal.
        Http::fake(['api.biteship.com/v1/orders' => Http::response([
            'code' => 40002003,
            'message' => 'kurir tidak valid',
        ], 400)]);
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready]);

        (new CreateShipmentJob($order->id))->handle(app(CreateBiteshipShipment::class));

        $fresh = $order->fresh();
        $this->assertSame('failed', $fresh->delivery_status);
        $this->assertNull($fresh->biteship_order_id);
    }

    public function test_branch_without_delivery_area_fails_without_provider_call(): void
    {
        Http::fake();
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready]);
        $order->branch->forceFill(['delivery_area_id' => null])->save();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => (int) ($context['order_id'] ?? 0) === (int) $order->id
                && ($context['reason'] ?? null) === 'branch_delivery_area_missing'
                && array_keys($context) === ['order_id', 'reason']);

        (new CreateShipmentJob($order->id))->handle(app(CreateBiteshipShipment::class));

        Http::assertNothingSent();

        $this->assertSame('failed', $order->fresh()->delivery_status);
        $this->assertNull($order->fresh()->biteship_order_id);
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

    public function test_webhook_delivered_unpaid_preparing_keeps_status_without_transition(): void
    {
        // Transisi delivered → Completed tak diizinkan dari Preparing →
        // status kurir tetap harus tersimpan, tanpa exception.
        Event::fake([OrderStatusChanged::class]);
        $order = $this->deliveryOrder(['status' => OrderStatus::Preparing, 'biteship_order_id' => 'BIT-1']);
        $order->forceFill(['payment_status' => PaymentStatus::Unpaid])->save();
        $order->payment?->forceFill(['status' => PaymentStatus::Pending])->save();

        $this->postJson('/api/v1/webhooks/biteship', [
            'event' => 'order.status',
            'order_id' => 'BIT-1',
            'status' => 'delivered',
        ], ['X-Biteship-Signature' => self::BITESHIP['webhook_secret']])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('delivered', $fresh->delivery_status);
        $this->assertSame(OrderStatus::Preparing, $fresh->status);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_webhook_delivered_ready_unpaid_logs_rejected_transition(): void
    {
        // Graf mengizinkan (Ready → Completed), tapi unpaid →
        // TransitionOrderStatus abort 422; status kurir tak boleh hilang.
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready, 'biteship_order_id' => 'BIT-1']);
        $order->forceFill(['payment_status' => PaymentStatus::Unpaid])->save();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'ditolak')
                && (int) ($context['order_id'] ?? 0) === (int) $order->id
                && array_keys($context) === ['order_id', 'exception']);

        $this->postJson('/api/v1/webhooks/biteship', [
            'event' => 'order.status',
            'order_id' => 'BIT-1',
            'status' => 'delivered',
        ], ['X-Biteship-Signature' => self::BITESHIP['webhook_secret']])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('delivered', $fresh->delivery_status);
        $this->assertSame(OrderStatus::Ready, $fresh->status);
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

    public function test_webhook_exotic_status_stored_as_unknown_without_500(): void
    {
        // >32 char (kolom string(32)) dan uppercase → `unknown`; log
        // hanya order_id + panjang mentah, bukan isinya.
        $order = $this->deliveryOrder(['status' => OrderStatus::Ready, 'biteship_order_id' => 'BIT-1']);

        Log::shouldReceive('warning')
            ->times(2)
            ->withArgs(fn (string $message, array $context): bool => (int) ($context['order_id'] ?? 0) === (int) $order->id
                && array_keys($context) === ['order_id', 'raw_length']);

        foreach ([str_repeat('x', 60), 'DELIVERED'] as $raw) {
            $this->postJson('/api/v1/webhooks/biteship', [
                'event' => 'order.status',
                'order_id' => 'BIT-1',
                'status' => $raw,
            ], ['X-Biteship-Signature' => self::BITESHIP['webhook_secret']])
                ->assertOk();

            $this->assertSame('unknown', $order->fresh()->delivery_status);
        }
    }

    // ============ konsumsi bahan ============

    public function test_consume_order_ingredients_requires_actor(): void
    {
        $order = $this->deliveryOrder(['status' => OrderStatus::Accepted]);

        $this->expectException(LogicException::class);

        app(ConsumeOrderIngredients::class)($order, null);
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

        // before/after harus sama kuncinya.
        $audit = AuditLog::query()
            ->where('action', 'order.delivery_retried')
            ->where('target_id', $order->id)
            ->firstOrFail();

        $this->assertSame(['delivery_status' => 'failed', 'retry_requested' => false], $audit->before);
        $this->assertSame(['delivery_status' => 'failed', 'retry_requested' => true], $audit->after);

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
