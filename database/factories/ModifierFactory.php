<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Modifier>
 */
class ModifierFactory extends Factory
{
    protected $model = Modifier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'modifier_group_id' => ModifierGroup::factory(),
            'name' => fake()->unique()->word(),
            'price_delta' => 10000,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
