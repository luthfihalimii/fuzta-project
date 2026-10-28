<?php

namespace Database\Factories;

use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Cabang '.fake()->city(),
            'code' => 'PF-'.fake()->unique()->numerify('##'),
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'cash_float' => 0,
            'is_active' => true,
        ];
    }
}
