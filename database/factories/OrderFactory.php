<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'PF-TEST-'.fake()->unique()->numerify('########'),
            'branch_id' => Branch::factory(),
            'placed_by_id' => User::factory()->cashier(),
            'channel' => OrderChannel::Takeaway,
            'table_number' => null,
            'customer_name' => null,
            'note' => null,
            'status' => OrderStatus::Queued,
            'payment_status' => PaymentStatus::Unpaid,
            'subtotal' => 50000,
            'total' => 50000,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function unpaid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Queued,
            'payment_status' => PaymentStatus::Unpaid,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => PaymentStatus::Paid,
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Voided,
        ]);
    }
}
