<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_enums_have_expected_values(): void
    {
        $this->assertSame('delivery', OrderChannel::Delivery->value);
        $this->assertSame('Delivery', OrderChannel::Delivery->label());
        $this->assertSame('doku_qris', PaymentMethod::DokuQris->value);
        $this->assertSame('QRIS online (DOKU)', PaymentMethod::DokuQris->label());
        $this->assertSame('pending', PaymentStatus::Pending->value);
        $this->assertSame('Menunggu pembayaran', PaymentStatus::Pending->label());
    }

    public function test_customer_order_columns_persist(): void
    {
        $token = (string) Str::uuid();

        $order = Order::factory()->create([
            'channel' => OrderChannel::Delivery,
            'placed_by_id' => null,
            'public_token' => $token,
            'customer_phone' => '081200000001',
            'delivery_address' => 'Jl. Contoh 1',
            'delivery_area_id' => 'IDNP10',
            'delivery_fee' => 15000,
            'biteship_order_id' => 'BIT-777',
            'courier_name' => 'Gojek',
            'courier_service' => 'Instant',
            'delivery_status' => 'requested',
            'tracking_url' => 'https://track.example/TRK1',
        ])->refresh();

        $this->assertSame(OrderChannel::Delivery, $order->channel);
        $this->assertNull($order->placed_by_id);
        $this->assertSame($token, $order->public_token);
        $this->assertSame('081200000001', $order->customer_phone);
        $this->assertSame('Jl. Contoh 1', $order->delivery_address);
        $this->assertSame('IDNP10', $order->delivery_area_id);
        $this->assertSame(15000, $order->delivery_fee);
        $this->assertSame('BIT-777', $order->biteship_order_id);
        $this->assertSame('Gojek', $order->courier_name);
        $this->assertSame('Instant', $order->courier_service);
        $this->assertSame('requested', $order->delivery_status);
        $this->assertSame('https://track.example/TRK1', $order->tracking_url);
    }

    public function test_product_weight_and_branch_delivery_area_persist(): void
    {
        $product = Product::factory()->create(['weight_grams' => 750])->refresh();
        $branch = Branch::factory()->create(['delivery_area_id' => 'IDNP10'])->refresh();

        $this->assertSame(750, $product->weight_grams);
        $this->assertSame('IDNP10', $branch->delivery_area_id);
    }

    public function test_payment_provider_columns_persist(): void
    {
        $order = Order::factory()->create();
        $expiresAt = now()->startOfMinute()->addMinutes(30);

        $payment = Payment::create([
            'order_id' => $order->id,
            'method' => PaymentMethod::DokuQris,
            'amount' => $order->total,
            'status' => PaymentStatus::Pending,
            'provider' => 'doku',
            'provider_reference' => 'INV-20261101-001',
            'payment_url' => 'https://sandbox.doku.com/pay/abc',
            'expires_at' => $expiresAt,
        ])->refresh();

        $this->assertSame(PaymentMethod::DokuQris, $payment->method);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('doku', $payment->provider);
        $this->assertSame('INV-20261101-001', $payment->provider_reference);
        $this->assertSame('https://sandbox.doku.com/pay/abc', $payment->payment_url);
        $this->assertTrue($payment->expires_at->equalTo($expiresAt));
    }

    public function test_delivery_channel_round_trips_through_order_cast(): void
    {
        $order = Order::factory()->create([
            'channel' => OrderChannel::Delivery,
            'placed_by_id' => null,
        ])->refresh();

        $this->assertSame('delivery', $order->getRawOriginal('channel'));
        $this->assertSame(OrderChannel::Delivery, $order->channel);
        $this->assertSame('delivery', $order->channel->value);
    }

    public function test_pos_payment_rejects_online_doku_method(): void
    {
        $order = Order::factory()->unpaid()->create(['total' => 170000]);
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $this->actingAs($cashier)
            ->postJson("/orders/{$order->id}/pay", ['method' => 'doku_qris'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_pos_order_rejects_delivery_channel(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        $product = Product::factory()->create();

        $this->actingAs($cashier)
            ->postJson('/pos/orders', [
                'channel' => 'delivery',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'idempotency_key' => 'pos-channel-test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel');

        $this->assertDatabaseCount('orders', 0);
    }
}
