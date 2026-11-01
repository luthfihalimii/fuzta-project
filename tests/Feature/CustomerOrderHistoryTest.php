<?php

namespace Tests\Feature;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerToken;
use App\Modules\Ordering\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerOrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    private int $tokenSeq = 0;

    public function test_without_token_is_unauthorized(): void
    {
        $this->getJson('/api/v1/me/orders')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Silakan masuk terlebih dahulu.');
    }

    public function test_history_only_shows_own_orders_with_summary_and_pagination(): void
    {
        [$customer, $token] = $this->customerWithToken();
        $other = Customer::factory()->create();

        // 12 order milik customer (11:00–11:11), 3 tamu yang LEBIH BARU
        // (11:20–11:22), 2 order customer lain yang paling baru (11:30–11:31):
        // tamu/orang lain tidak boleh lolos ke halaman mana pun. created_at
        // diset lewat update DB (model in-memory tak ke-merge), jadi urutan
        // tes dihitung dari string waktu yang diset, bukan dari model.
        $own = [];

        foreach (range(0, 11) as $i) {
            $time = sprintf('2026-11-01 11:%02d:00', $i);
            $own[] = [$this->orderFor($customer->id, $time), $time];
        }

        $guestNumbers = [];

        foreach (['11:20', '11:21', '11:22'] as $minute) {
            $guestNumbers[] = $this->orderFor(null, "2026-11-01 {$minute}:00")->order_number;
        }

        $otherNumbers = [];

        foreach (['11:30', '11:31'] as $minute) {
            $otherNumbers[] = $this->orderFor($other->id, "2026-11-01 {$minute}:00")->order_number;
        }

        $first = $this->getJson('/api/v1/me/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $first->assertJsonCount(10, 'orders');
        $this->assertSame(1, $first->json('meta.current_page'));
        $this->assertSame(2, $first->json('meta.last_page'));
        $this->assertSame(10, $first->json('meta.per_page'));
        $this->assertSame(12, $first->json('meta.total'));

        // Terbaru dulu: 11:11..11:02.
        $expected = collect($own)
            ->sortByDesc(fn (array $pair) => $pair[1])
            ->values()
            ->slice(0, 10)
            ->values()
            ->map(fn (array $pair) => $pair[0]->order_number)
            ->all();
        $this->assertSame($expected, array_column($first->json('orders'), 'order_number'));

        foreach ($first->json('orders') as $item) {
            $this->assertSame(
                ['order_number', 'token', 'created_at', 'channel', 'status', 'payment_status', 'delivery_status', 'total'],
                array_keys($item),
            );
        }

        $firstNumbers = array_column($first->json('orders'), 'order_number');
        foreach (array_merge($guestNumbers, $otherNumbers) as $number) {
            $this->assertNotContains($number, $firstNumbers);
        }

        $second = $this->getJson('/api/v1/me/orders?page=2', ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $second->assertJsonCount(2, 'orders');
        $this->assertSame(2, $second->json('meta.current_page'));
        $remaining = collect($own)
            ->sortByDesc(fn (array $pair) => $pair[1])
            ->values()
            ->slice(10)
            ->values()
            ->map(fn (array $pair) => $pair[0]->order_number)
            ->all();
        $this->assertSame($remaining, array_column($second->json('orders'), 'order_number'));
    }

    /**
     * Order dengan created_at deterministik (urutan tes tidak boleh
     * bergantung pada waktu jam).
     */
    private function orderFor(?int $customerId, string $createdAt): Order
    {
        $order = Order::factory()->create([
            'customer_id' => $customerId,
            'placed_by_id' => null,
        ]);

        DB::table('orders')->where('id', $order->id)->update(['created_at' => $createdAt]);

        return $order;
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
        $raw = 'history-token-'.($this->tokenSeq);

        CustomerToken::query()->create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addDays(30),
        ]);

        return [$customer, $raw];
    }
}
