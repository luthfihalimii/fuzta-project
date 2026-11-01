<?php

namespace Database\Factories;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'label' => 'Rumah',
            'recipient_name' => fake()->name(),
            'phone' => '08'.fake()->numerify('##########'),
            'address' => fake()->address(),
            'area_id' => 'area-'.fake()->unique()->numerify('###'),
            'notes' => null,
            'is_default' => false,
        ];
    }
}
