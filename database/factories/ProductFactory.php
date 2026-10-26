<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => 'Pizza '.fake()->unique()->word(),
            'description' => fake()->sentence(),
            'base_price' => 50000,
            'is_active' => true,
        ];
    }
}
