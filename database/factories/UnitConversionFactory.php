<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\UnitConversion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitConversion>
 */
class UnitConversionFactory extends Factory
{
    protected $model = UnitConversion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_unit_id' => Unit::factory(),
            'to_unit_id' => Unit::factory(),
            'from_quantity' => 1,
            'to_quantity' => 1000,
        ];
    }
}
