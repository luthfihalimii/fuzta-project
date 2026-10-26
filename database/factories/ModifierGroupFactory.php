<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModifierGroup>
 */
class ModifierGroupFactory extends Factory
{
    protected $model = ModifierGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Grup '.fake()->unique()->word(),
            'min_select' => 0,
            'max_select' => 1,
            'is_required' => false,
            'sort_order' => 0,
        ];
    }
}
