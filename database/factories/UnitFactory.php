<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'u'.fake()->unique()->numerify('###');

        return [
            'code' => $code,
            'name' => 'Satuan '.$code,
        ];
    }
}
