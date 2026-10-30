<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Purchasing\Models\Purchase;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'supplier_id' => Supplier::factory(),
            'received_at' => now()->toDateString(),
            'reference' => null,
            'total' => 0,
            'recorded_by' => User::factory(),
            'idempotency_key' => Str::uuid()->toString(),
        ];
    }
}
