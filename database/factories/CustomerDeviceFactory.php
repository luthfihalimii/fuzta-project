<?php

namespace Database\Factories;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerDevice>
 */
class CustomerDeviceFactory extends Factory
{
    protected $model = CustomerDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'token' => 'fcm-'.fake()->unique()->uuid(),
            'platform' => 'android',
            'last_seen_at' => now(),
        ];
    }
}
