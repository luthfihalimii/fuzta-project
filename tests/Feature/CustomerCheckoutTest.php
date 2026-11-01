<?php

namespace Tests\Feature;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Ordering\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Bekukan ke 12:00 WIB agar cek jam buka deterministik (pola CatalogApiTest).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 12:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_pickup_quote_returns_zero_fee_without_calling_biteship(): void
    {
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);

        $this->postJson('/api/v1/checkout/quote', [
            'branch_id' => $branch->id,
            'fulfillment' => 'pickup',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk()
            ->assertJsonPath('options.0.courier_name', 'Ambil sendiri')
            ->assertJsonPath('options.0.courier_service', 'pickup')
            ->assertJsonPath('options.0.fee', 0)
            ->assertJsonPath('options.0.etd', null);

        Http::assertNothingSent();
    }

    public function test_delivery_quote_uses_biteship_rates_as_integer(): void
    {
        $this->fakeRates();
        $branch = $this->deliveryBranch();
        $product = $this->availableProductAt($branch);

        $this->postJson('/api/v1/checkout/quote', [
            'branch_id' => $branch->id,
            'fulfillment' => 'delivery',
            'destination_area_id' => 'IDNP10',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertOk()
            ->assertJsonCount(2, 'options')
            ->assertJsonPath('options.0.courier_name', 'Gojek')
            ->assertJsonPath('options.0.courier_service', 'Instant')
            ->assertJsonPath('options.0.fee', 12346)
            ->assertJsonPath('options.0.etd', '1 - 2 hours');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.biteship.com/v1/rates'
                && $request['origin_area_id'] === 'IDNP01'
                && $request['destination_area_id'] === 'IDNP10'
                && is_array($request['couriers']) && $request['couriers'] !== []
                && $request['items'][0]['quantity'] === 2
                && $request['items'][0]['value'] === 100000
                && $request['items'][0]['weight'] === 1000;
        });
    }

    public function test_delivery_quote_rejects_when_no_rate_available(): void
    {
        $this->fakeRates([]);
        $branch = $this->deliveryBranch();
        $product = $this->availableProductAt($branch);

        $this->postJson('/api/v1/checkout/quote', [
            'branch_id' => $branch->id,
            'fulfillment' => 'delivery',
            'destination_area_id' => 'IDNP10',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('delivery_area_id');
    }

    public function test_quote_and_store_return_422_when_biteship_rejects_area(): void
    {
        Http::fake(['api.biteship.com/*' => Http::response(['error' => 'area unknown'], 400)]);
        $branch = $this->deliveryBranch();
        $product = $this->availableProductAt($branch);
        $items = [['product_id' => $product->id, 'quantity' => 1]];

        $this->postJson('/api/v1/checkout/quote', [
            'branch_id' => $branch->id,
            'fulfillment' => 'delivery',
            'destination_area_id' => 'BADAREA',
            'items' => $items,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('delivery_area_id')
            ->assertJsonPath('errors.delivery_area_id.0', 'Alamat pengiriman tidak dikenali.');

        $storePayload = $this->deliveryPayload($branch, $product);
        $storePayload['delivery_area_id'] = 'BADAREA';

        $this->withHeader('Idempotency-Key', 'bad-area')
            ->postJson('/api/v1/orders', $storePayload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_area_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_delivery_quote_rejects_rates_without_valid_price(): void
    {
        // price hilang / nol bukan berarti gratis: tidak ada opsi valid.
        $this->fakeRates([
            ['courier_name' => 'Gojek', 'courier_service_name' => 'Instant', 'duration' => '1 - 2 hours'],
            ['courier_name' => 'JNE', 'courier_service_name' => 'Reguler', 'price' => 0, 'duration' => '2 days'],
            ['courier_name' => 'Grab', 'courier_service_name' => 'Express', 'price' => 'bukan-angka', 'duration' => '1 day'],
        ]);
        $branch = $this->deliveryBranch();
        $product = $this->availableProductAt($branch);

        $this->postJson('/api/v1/checkout/quote', [
            'branch_id' => $branch->id,
            'fulfillment' => 'delivery',
            'destination_area_id' => 'IDNP10',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('delivery_area_id');
    }

    public function test_delivery_quote_rejects_inactive_branch(): void
    {
        $branch = $this->deliveryBranch(['is_active' => false]);
        $product = $this->availableProductAt($branch);

        $this->postJson('/api/v1/checkout/quote', [
            'branch_id' => $branch->id,
            'fulfillment' => 'delivery',
            'destination_area_id' => 'IDNP10',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_pickup_quote_ignores_destination_area_and_stays_free(): void
    {
        $branch = $this->deliveryBranch();
        $product = $this->availableProductAt($branch);

        $this->postJson('/api/v1/checkout/quote', [
            'branch_id' => $branch->id,
            'fulfillment' => 'pickup',
            'destination_area_id' => 'IDNP10',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk()
            ->assertJsonCount(1, 'options')
            ->assertJsonPath('options.0.courier_service', 'pickup')
            ->assertJsonPath('options.0.fee', 0);

        Http::assertNothingSent();
    }

    public function test_quote_and_store_return_502_when_biteship_down(): void
    {
        Http::fake(['api.biteship.com/*' => Http::response(['error' => 'down'], 500)]);
        $branch = $this->deliveryBranch();
        $product = $this->availableProductAt($branch);
        $items = [['product_id' => $product->id, 'quantity' => 1]];

        $this->postJson('/api/v1/checkout/quote', [
            'branch_id' => $branch->id,
            'fulfillment' => 'delivery',
            'destination_area_id' => 'IDNP10',
            'items' => $items,
        ])->assertStatus(502)
            ->assertJsonPath('message', 'Layanan pengiriman sedang tidak tersedia.');

        $this->withHeader('Idempotency-Key', 'down-1')->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'fulfillment' => 'delivery',
            'customer_name' => 'Budi',
            'customer_phone' => '081234567890',
            'delivery_address' => 'Jl. Kemang Raya No. 1',
            'delivery_area_id' => 'IDNP10',
            'courier_name' => 'Gojek',
            'courier_service' => 'Instant',
            'items' => $items,
        ])->assertStatus(502);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_create_pickup_order_snapshots_items_and_returns_token(): void
    {
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch, ['base_price' => 50000]);

        $group = ModifierGroup::factory()->create();
        $product->modifierGroups()->attach($group->id, ['sort_order' => 0]);
        $modifier = Modifier::factory()->for($group)->create(['price_delta' => 5000]);

        $expectedTotal = (50000 + 5000) * 2;

        $response = $this->withHeader('Idempotency-Key', 'key-1')->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'fulfillment' => 'pickup',
            'customer_name' => 'Budi Santoso',
            'customer_phone' => '081234567890',
            'note' => 'Tanpa bawang',
            'items' => [['product_id' => $product->id, 'modifier_ids' => [$modifier->id], 'quantity' => 2]],
        ])->assertCreated()
            ->assertJsonPath('order.channel', 'takeaway')
            ->assertJsonPath('order.status', 'queued')
            ->assertJsonPath('order.payment_status', 'unpaid')
            ->assertJsonPath('order.subtotal', $expectedTotal)
            ->assertJsonPath('order.delivery_fee', 0)
            ->assertJsonPath('order.total', $expectedTotal)
            ->assertJsonPath('order.customer_name', 'Budi Santoso')
            ->assertJsonPath('order.customer_phone', '081234567890')
            ->assertJsonPath('order.note', 'Tanpa bawang')
            ->assertJsonPath('order.items.0.product_name', $product->name)
            ->assertJsonPath('order.items.0.modifiers.0.modifier_name', $modifier->name);

        $this->assertArrayNotHasKey('placed_by_id', $response->json('order'));
        $this->assertArrayNotHasKey('shift_id', $response->json('order'));
        $this->assertArrayNotHasKey('idempotency_key', $response->json('order'));

        /** @var Order $order */
        $order = Order::query()->where('idempotency_key', 'key-1')->firstOrFail();
        $this->assertNull($order->placed_by_id);
        $this->assertNull($order->shift_id);
        $this->assertSame($expectedTotal, $order->total);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(110000, $order->items()->first()->line_total);
        $this->assertSame(55000, $order->items()->first()->unit_price);
        $this->assertSame(1, $order->items()->first()->modifiers()->count());
        $this->assertSame(1, $order->statusEvents()->count());
        $this->assertNull($order->statusEvents()->first()->actor_id);
        $this->assertNotNull($response->json('token'));
        $this->assertSame($order->public_token, $response->json('token'));

        $audit = AuditLog::query()->where('action', 'order.created')->firstOrFail();
        $this->assertNull($audit->actor_id);
        $this->assertSame($order->id, $audit->target_id);
        $this->assertArrayNotHasKey('customer_phone', $audit->after);
        $this->assertArrayNotHasKey('delivery_address', $audit->after);
        $this->assertArrayNotHasKey('public_token', $audit->after);
    }

    public function test_repeat_same_idempotency_key_returns_same_order(): void
    {
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);
        $payload = $this->pickupPayload($branch, $product);

        $first = $this->withHeader('Idempotency-Key', 'same')->postJson('/api/v1/orders', $payload)->assertCreated();
        $second = $this->withHeader('Idempotency-Key', 'same')->postJson('/api/v1/orders', $payload)->assertOk();

        $this->assertSame($first->json('order.order_number'), $second->json('order.order_number'));
        $this->assertSame($first->json('token'), $second->json('token'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Order::first()->items()->count());
    }

    public function test_replay_returns_stored_order_without_calling_biteship(): void
    {
        $this->fakeRates([
            ['courier_name' => 'Gojek', 'courier_service_name' => 'Instant', 'price' => 20000, 'duration' => '1 - 2 hours'],
        ]);
        $branch = $this->deliveryBranch();
        $product = $this->availableProductAt($branch);
        $payload = $this->deliveryPayload($branch, $product);

        $first = $this->withHeader('Idempotency-Key', 'replay-down')
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated();

        // Biteship down saat retry key sama: harus tetap 200 + order yang sama, tanpa order baru.
        Http::fake(['api.biteship.com/*' => Http::response(['error' => 'down'], 500)]);

        $this->withHeader('Idempotency-Key', 'replay-down')
            ->postJson('/api/v1/orders', $payload)
            ->assertOk()
            ->assertJsonPath('order.order_number', $first->json('order.order_number'))
            ->assertJsonPath('token', $first->json('token'));

        Http::assertNothingSent();
        $this->assertSame(1, Order::count());
    }

    public function test_same_key_different_branch_creates_separate_orders(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $product = Product::factory()->create(['base_price' => 30000]);
        $product->branches()->attach($branchA, ['is_available' => true]);
        $product->branches()->attach($branchB, ['is_available' => true]);

        $payload = $this->pickupPayload($branchA, $product);

        $this->withHeader('Idempotency-Key', 'shared-key')->postJson('/api/v1/orders', $payload)->assertCreated();
        $this->withHeader('Idempotency-Key', 'shared-key')
            ->postJson('/api/v1/orders', $this->pickupPayload($branchB, $product))
            ->assertCreated();

        $this->assertSame(2, Order::count());
        $this->assertSame(2, AuditLog::where('action', 'order.created')->count());
    }

    public function test_delivery_order_re_quotes_on_server_and_ignores_client_fee(): void
    {
        $this->fakeRates([
            ['courier_name' => 'Gojek', 'courier_service_name' => 'Instant', 'price' => 20000, 'duration' => '1 - 2 hours'],
        ]);
        $branch = $this->deliveryBranch();
        $product = $this->availableProductAt($branch, ['base_price' => 50000]);
        $payload = $this->deliveryPayload($branch, $product);

        // Fee palsu dari client harus diabaikan; server memakai ulang quote.
        $payload['delivery_fee'] = 1;

        $this->withHeader('Idempotency-Key', 'fee-1')->postJson('/api/v1/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('order.delivery_fee', 20000)
            ->assertJsonPath('order.total', 70000);

        /** @var Order $order */
        $order = Order::query()->firstOrFail();
        $this->assertSame(20000, $order->delivery_fee);
        $this->assertSame(70000, $order->total);
        $this->assertSame('Gojek', $order->courier_name);
        $this->assertSame('Instant', $order->courier_service);
        $this->assertSame('IDNP10', $order->delivery_area_id);
        $this->assertSame('Jl. Kemang Raya No. 1', $order->delivery_address);
    }

    public function test_delivery_float_fee_rounds_into_integer_total(): void
    {
        $this->fakeRates();
        $branch = $this->deliveryBranch();
        $product = $this->availableProductAt($branch, ['base_price' => 50000]);
        $payload = $this->deliveryPayload($branch, $product);

        // Subtotal 100000 (2 x 50000) + ongkir 12345.6 -> 12346.
        $payload['items'][0]['quantity'] = 2;

        $this->withHeader('Idempotency-Key', 'float-1')->postJson('/api/v1/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('order.subtotal', 100000)
            ->assertJsonPath('order.delivery_fee', 12346)
            ->assertJsonPath('order.total', 112346);

        $order = Order::query()->firstOrFail();
        $this->assertSame(12346, $order->delivery_fee);
        $this->assertSame(112346, $order->total);
    }

    public function test_checkout_rejects_closed_branch(): void
    {
        $branch = Branch::factory()->create(['opens_at' => '22:00', 'closes_at' => '23:00']);
        $product = $this->availableProductAt($branch);

        $this->withHeader('Idempotency-Key', 'closed-1')
            ->postJson('/api/v1/orders', $this->pickupPayload($branch, $product))
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id')
            ->assertJsonPath('errors.branch_id.0', 'Cabang sedang tutup.');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_inactive_branch(): void
    {
        $branch = Branch::factory()->create(['is_active' => false]);
        $product = $this->availableProductAt($branch);

        $this->withHeader('Idempotency-Key', 'inactive-1')
            ->postJson('/api/v1/orders', $this->pickupPayload($branch, $product))
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_inactive_or_unavailable_product(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();

        $inactive = Product::factory()->create(['is_active' => false]);
        $inactive->branches()->attach($branch, ['is_available' => true]);

        $elsewhere = Product::factory()->create();
        $elsewhere->branches()->attach($otherBranch, ['is_available' => true]);

        foreach ([$inactive, $elsewhere] as $product) {
            $this->withHeader('Idempotency-Key', 'prod-'.$product->id)
                ->postJson('/api/v1/orders', $this->pickupPayload($branch, $product))
                ->assertStatus(422)
                ->assertJsonValidationErrors('items.0.product_id');
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_invalid_quantity(): void
    {
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);

        foreach ([0, 100] as $quantity) {
            $payload = $this->pickupPayload($branch, $product);
            $payload['items'][0]['quantity'] = $quantity;

            $this->withHeader('Idempotency-Key', 'qty-'.$quantity)
                ->postJson('/api/v1/orders', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('items.0.quantity');
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_store_rejects_invalid_phone_and_missing_delivery_fields(): void
    {
        $branch = $this->deliveryBranch();
        $product = $this->availableProductAt($branch);
        $payload = $this->deliveryPayload($branch, $product);

        // Format HP salah (harus 08 + 8-13 digit).
        $badPhone = $payload;
        $badPhone['customer_phone'] = '021234';

        $this->withHeader('Idempotency-Key', 'phone-1')
            ->postJson('/api/v1/orders', $badPhone)
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_phone');

        // Field wajib delivery hilang satu per satu.
        foreach (['delivery_address', 'delivery_area_id', 'courier_name', 'courier_service'] as $missing) {
            $incomplete = $payload;
            unset($incomplete[$missing]);

            $this->withHeader('Idempotency-Key', 'missing-'.$missing)
                ->postJson('/api/v1/orders', $incomplete)
                ->assertStatus(422)
                ->assertJsonValidationErrors($missing);
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_missing_or_blank_idempotency_key(): void
    {
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);
        $payload = $this->pickupPayload($branch, $product);

        $this->postJson('/api/v1/orders', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->withHeader('Idempotency-Key', '  ')
            ->postJson('/api/v1/orders', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_delivery_order_requires_courier_chosen_from_quote(): void
    {
        $this->fakeRates();
        $branch = $this->deliveryBranch();
        $product = $this->availableProductAt($branch);

        // Kurir tidak ada di hasil quote.
        $unknown = $this->deliveryPayload($branch, $product);
        $unknown['courier_name'] = 'AlJabor';

        // Kurir ada, tapi layanan tidak ada di hasil quote.
        $wrongService = $this->deliveryPayload($branch, $product);
        $wrongService['courier_service'] = 'Same Day';

        foreach ([$unknown, $wrongService] as $i => $payload) {
            $this->withHeader('Idempotency-Key', 'courier-'.$i)
                ->postJson('/api/v1/orders', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('courier_name');
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_quote_requires_destination_area_for_delivery(): void
    {
        $branch = $this->deliveryBranch();
        $product = $this->availableProductAt($branch);

        $this->postJson('/api/v1/checkout/quote', [
            'branch_id' => $branch->id,
            'fulfillment' => 'delivery',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('destination_area_id');
    }

    public function test_public_token_is_uuid_and_unique_per_order(): void
    {
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);

        $first = $this->withHeader('Idempotency-Key', 'uuid-1')
            ->postJson('/api/v1/orders', $this->pickupPayload($branch, $product))
            ->assertCreated()
            ->json('token');
        $second = $this->withHeader('Idempotency-Key', 'uuid-2')
            ->postJson('/api/v1/orders', $this->pickupPayload($branch, $product))
            ->assertCreated()
            ->json('token');

        $this->assertTrue(Str::isUuid($first));
        $this->assertTrue(Str::isUuid($second));
        $this->assertNotSame($first, $second);
    }

    public function test_snapshot_survives_later_product_price_change(): void
    {
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch, ['base_price' => 50000]);

        $this->withHeader('Idempotency-Key', 'snap-1')
            ->postJson('/api/v1/orders', $this->pickupPayload($branch, $product))
            ->assertCreated()
            ->assertJsonPath('order.total', 50000);

        // Harga katalog berubah setelah order dibuat: snapshot lama tidak boleh bergerak.
        $product->update(['base_price' => 999999]);

        $this->assertSame(999999, $product->fresh()->base_price);
        $item = Order::query()->firstOrFail()->items()->firstOrFail();
        $this->assertSame(50000, $item->unit_price);
        $this->assertSame(50000, $item->line_total);

        // Replay key sama tetap kembali ke order lama dengan harga lama.
        $this->withHeader('Idempotency-Key', 'snap-1')
            ->postJson('/api/v1/orders', $this->pickupPayload($branch, $product))
            ->assertOk()
            ->assertJsonPath('order.total', 50000);
        $this->assertSame(1, Order::count());
    }

    public function test_customer_checkouts_are_rate_limited(): void
    {
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/checkout/quote', [
                'branch_id' => $branch->id,
                'fulfillment' => 'pickup',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertOk();
        }

        $this->postJson('/api/v1/checkout/quote', [
            'branch_id' => $branch->id,
            'fulfillment' => 'pickup',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(429);
    }

    // ============ helpers ============

    private function availableProductAt(Branch $branch, array $attributes = []): Product
    {
        $product = Product::factory()->create(['weight_grams' => 500] + $attributes);
        $product->branches()->attach($branch, ['is_available' => true]);

        return $product;
    }

    private function deliveryBranch(array $attributes = []): Branch
    {
        return Branch::factory()->create(['delivery_area_id' => 'IDNP01'] + $attributes);
    }

    private function pickupPayload(Branch $branch, Product $product, int $quantity = 1): array
    {
        return [
            'branch_id' => $branch->id,
            'fulfillment' => 'pickup',
            'customer_name' => 'Budi Santoso',
            'customer_phone' => '081234567890',
            'note' => 'Tanpa bawang',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ];
    }

    private function deliveryPayload(Branch $branch, Product $product, int $quantity = 1): array
    {
        $payload = $this->pickupPayload($branch, $product, $quantity);
        $payload['fulfillment'] = 'delivery';

        return $payload + [
            'delivery_address' => 'Jl. Kemang Raya No. 1',
            'delivery_area_id' => 'IDNP10',
            'courier_name' => 'Gojek',
            'courier_service' => 'Instant',
        ];
    }

    /**
     * @param  list<array{courier_name: string, courier_service_name: string, price: float, duration: string}>  $pricing  null = default 2 opsi.
     */
    private function fakeRates(?array $pricing = null): void
    {
        if ($pricing === null) {
            $pricing = [
                ['courier_name' => 'Gojek', 'courier_service_name' => 'Instant', 'price' => 12345.6, 'duration' => '1 - 2 hours'],
                ['courier_name' => 'JNE', 'courier_service_name' => 'Reguler', 'price' => 25000, 'duration' => '2 - 3 days'],
            ];
        }

        Http::fake(['api.biteship.com/v1/rates' => Http::response(['success' => true, 'pricing' => $pricing])]);
    }
}
