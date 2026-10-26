<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    protected $model = Ingredient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'base_unit_id' => Unit::factory(),
            'name' => 'Bahan '.fake()->unique()->words(2, true),
            'low_stock_threshold' => 100,
        ];
    }
}
