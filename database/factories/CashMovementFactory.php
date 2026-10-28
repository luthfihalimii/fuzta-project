<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Shifts\Enums\CashMovementDirection;
use App\Modules\Shifts\Models\CashierShift;
use App\Modules\Shifts\Models\CashMovement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    protected $model = CashMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cashier_shift_id' => CashierShift::factory(),
            'direction' => CashMovementDirection::In,
            'amount' => 10000,
            'reason' => 'Tambahan kas',
            'actor_id' => User::factory()->owner(),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
