<?php

namespace Tests\Feature;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerToken;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 4: checkout terhubung ke akun customer (login opsional).
 * Guest tetap jalan; bearer valid → customer_id terisi + kontak dari
 * profil; bearer invalid → 401; replay lintas owner → 409.
 */
class CustomerAccountCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private int $tokenSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Bekukan ke 12:00 WIB agar cek jam buka deterministik (pola CatalogApiTest).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 12:00', 'Asia/Jakarta'));
        // Kredensial DOKU dummy agar store tidak jatuh ke 502.
        config(['services.doku' => [
            'client_id' => 'CLIENT-TEST',
            'secret_key' => 'SECRET-TEST',
            'base_url' => 'https://api-sandbox.doku.com',
            'notification_path' => '/api/v1/webhooks/doku',
            'payment_due_minutes' => 30,
        ]]);
        // Pickup saja: tanpa Biteship.
        Http::fake([
            'api-sandbox.doku.com/checkout/v1/payment' => Http::response([
                'message' => ['SUCCESS'],
                'response' => ['payment' => ['url' => 'https://sandbox.doku.com/checkout-link-v2/TEST']],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_guest_checkout_without_bearer_stays_guest(): void
    {
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);

        $this->withHeader('Idempotency-Key', 'acct-guest')
            ->postJson('/api/v1/orders', $this->pickupPayload($branch, $product))
            ->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertNull($order->customer_id);
        $this->assertNull($order->placed_by_id);
        $this->assertSame('Budi Santoso', $order->customer_name);
        $this->assertSame('081234567890', $order->customer_phone);
    }

    public function test_valid_bearer_links_order_to_customer_and_profile_contact_wins(): void
    {
        [$customer, $token] = $this->customerWithToken();
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);

        // Body sengaja pakai nama/HP lain: profil harus menang.
        $payload = $this->pickupPayload($branch, $product);
        $payload['customer_name'] = 'Penyamar Jahat';
        $payload['customer_phone'] = '089988776655';

        $response = $this->withHeaders([
            'Idempotency-Key' => 'acct-linked',
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/orders', $payload)->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertNull($order->placed_by_id);
        $this->assertSame($customer->name, $order->customer_name);
        $this->assertSame($customer->phone, $order->customer_phone);

        $this->assertSame($customer->name, $response->json('order.customer_name'));
        $this->assertSame($customer->phone, $response->json('order.customer_phone'));
    }

    public function test_invalid_bearer_returns_401_without_creating_order(): void
    {
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);

        $this->withHeaders([
            'Idempotency-Key' => 'acct-bad',
            'Authorization' => 'Bearer ngawur',
        ])->postJson('/api/v1/orders', $this->pickupPayload($branch, $product))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Silakan masuk terlebih dahulu.');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_invalid_bearer_returns_401_on_quote(): void
    {
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);

        $this->withHeader('Authorization', 'Bearer ngawur')
            ->postJson('/api/v1/checkout/quote', [
                'branch_id' => $branch->id,
                'fulfillment' => 'pickup',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Silakan masuk terlebih dahulu.');
    }

    public function test_same_customer_replay_returns_same_order(): void
    {
        [, $token] = $this->customerWithToken();
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);
        $payload = $this->pickupPayload($branch, $product);

        $first = $this->withHeaders([
            'Idempotency-Key' => 'acct-replay',
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/orders', $payload)->assertCreated();

        $second = $this->withHeaders([
            'Idempotency-Key' => 'acct-replay',
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/orders', $payload)->assertOk();

        $this->assertSame($first->json('order.order_number'), $second->json('order.order_number'));
        $this->assertSame($first->json('token'), $second->json('token'));
        $this->assertSame(1, Order::count());
    }

    public function test_cross_owner_replay_is_conflict(): void
    {
        [$customerA, $tokenA] = $this->customerWithToken();
        [, $tokenB] = $this->customerWithToken();
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);
        $payload = $this->pickupPayload($branch, $product);

        $this->withHeaders([
            'Idempotency-Key' => 'acct-cross',
            'Authorization' => 'Bearer '.$tokenA,
        ])->postJson('/api/v1/orders', $payload)->assertCreated();

        // Customer lain dengan key yang sama.
        $this->withHeaders([
            'Idempotency-Key' => 'acct-cross',
            'Authorization' => 'Bearer '.$tokenB,
        ])->postJson('/api/v1/orders', $payload)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Kunci idempotensi sudah dipakai.');

        // Tamu juga: key milik customer pun ditolak.
        $this->withHeader('Idempotency-Key', 'acct-cross')
            ->postJson('/api/v1/orders', $payload)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Kunci idempotensi sudah dipakai.');

        $this->assertSame(1, Order::count());
        $this->assertSame($customerA->id, Order::first()->customer_id);
    }

    public function test_unpaid_customer_order_still_expires(): void
    {
        [, $token] = $this->customerWithToken();
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);

        $this->withHeaders([
            'Idempotency-Key' => 'acct-expire',
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/orders', $this->pickupPayload($branch, $product))
            ->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertNull($order->placed_by_id);

        // Lewati window pembayaran (order 12:00, payment kedaluwarsa 12:30).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 12:45', 'Asia/Jakarta'));

        $this->artisan('orders:expire-unpaid')->assertExitCode(0);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_new_customer_order_appears_in_me_orders(): void
    {
        [, $token] = $this->customerWithToken();
        $branch = Branch::factory()->create();
        $product = $this->availableProductAt($branch);

        $response = $this->withHeaders([
            'Idempotency-Key' => 'acct-history',
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/orders', $this->pickupPayload($branch, $product))
            ->assertCreated();

        $this->getJson('/api/v1/me/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.order_number', $response->json('order.order_number'))
            ->assertJsonPath('orders.0.token', $response->json('token'))
            ->assertJsonPath('meta.total', 1);
    }

    // ============ helpers ============

    private function availableProductAt(Branch $branch): Product
    {
        $product = Product::factory()->create(['weight_grams' => 500]);
        $product->branches()->attach($branch, ['is_available' => true]);

        return $product;
    }

    private function pickupPayload(Branch $branch, Product $product): array
    {
        return [
            'branch_id' => $branch->id,
            'fulfillment' => 'pickup',
            'customer_name' => 'Budi Santoso',
            'customer_phone' => '081234567890',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
    }

    /**
     * Customer aktif + token bearer (pola CustomerProfileTest).
     *
     * @return array{0: Customer, 1: string}
     */
    private function customerWithToken(): array
    {
        $this->tokenSeq++;
        $customer = Customer::factory()->create();
        $raw = 'acct-token-'.($this->tokenSeq);

        CustomerToken::query()->create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addDays(30),
        ]);

        return [$customer, $raw];
    }
}
