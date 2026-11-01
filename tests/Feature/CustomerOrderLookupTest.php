<?php

namespace Tests\Feature;

use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerOrderLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_by_public_token_returns_customer_safe_payload(): void
    {
        $order = Order::factory()->create([
            'public_token' => (string) Str::uuid(),
            'customer_name' => 'Budi',
            'customer_phone' => '081200000001',
            'note' => 'tanpa bawang',
        ]);

        $this->getJson('/api/v1/orders/'.$order->public_token)
            ->assertOk()
            ->assertJsonPath('order.token', $order->public_token)
            ->assertJsonPath('order.order_number', $order->order_number)
            ->assertJsonPath('order.customer_name', 'Budi')
            ->assertJsonPath('order.customer_phone', '081200000001')
            ->assertJsonPath('order.note', 'tanpa bawang')
            ->assertJsonMissingPath('order.placed_by_id')
            ->assertJsonMissingPath('order.shift_id')
            ->assertJsonMissingPath('order.idempotency_key')
            ->assertJsonMissingPath('order.biteship_order_id');
    }

    public function test_unknown_token_returns_404_json(): void
    {
        Order::factory()->create(['public_token' => (string) Str::uuid()]);

        $this->getJson('/api/v1/orders/not-a-real-token')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_lookup_returns_own_status_without_leaking_other_orders(): void
    {
        $unpaid = Order::factory()->create(['order_number' => 'PF-UNPAID-1']);
        $paid = Order::factory()->paid()->create(['order_number' => 'PF-PAYED-1']);
        $cancelled = Order::factory()->create(['order_number' => 'PF-CANCELLED-1', 'status' => OrderStatus::Cancelled]);

        // Beri tiap order token uuid berbeda (kolom public_token bertipe uuid).
        foreach ([$unpaid, $paid, $cancelled] as $o) {
            $o->forceFill(['public_token' => (string) Str::uuid()])->save();
        }

        $paidResponse = $this->getJson('/api/v1/orders/'.$paid->public_token)
            ->assertOk()
            ->assertJsonPath('order.payment_status', 'paid')
            ->assertJsonPath('order.order_number', 'PF-PAYED-1');

        // Order lain (unpaid/cancelled) tidak boleh bocor di respons.
        $this->assertStringNotContainsString('PF-UNPAID-1', $paidResponse->getContent());
        $this->assertStringNotContainsString('PF-CANCELLED-1', $paidResponse->getContent());

        $this->getJson('/api/v1/orders/'.$unpaid->public_token)
            ->assertOk()
            ->assertJsonPath('order.payment_status', 'unpaid')
            ->assertJsonPath('order.status', 'queued');

        $this->getJson('/api/v1/orders/'.$cancelled->public_token)
            ->assertOk()
            ->assertJsonPath('order.status', 'cancelled');
    }

    public function test_lookup_is_rate_limited(): void
    {
        // 61 request per menit per IP → request ke-61 ditolak (limit 60/menit).
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/orders/not-a-real-token');
        }

        $this->getJson('/api/v1/orders/not-a-real-token')->assertStatus(429);
    }
}
