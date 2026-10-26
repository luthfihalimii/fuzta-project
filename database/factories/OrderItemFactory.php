<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Product;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_name' => 'Pizza',
            'variant_id' => null,
            'variant_name' => null,
            'quantity' => 1,
            'unit_price' => 50000,
            'line_total' => 50000,
            'note' => null,
        ];
    }
}
