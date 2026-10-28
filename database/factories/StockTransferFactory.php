<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransfer>
 */
class StockTransferFactory extends Factory
{
    protected $model = StockTransfer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'origin_branch_id' => Branch::factory(),
            'destination_branch_id' => Branch::factory(),
            'status' => TransferStatus::Draft,
            'created_by' => User::factory()->owner(),
        ];
    }

    public function dispatched(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransferStatus::Dispatched,
            'dispatched_at' => now(),
        ]);
    }

    public function received(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransferStatus::Received,
            'dispatched_at' => now(),
            'received_at' => now(),
        ]);
    }

    public function cancelled(string $reason = 'Dibatalkan'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransferStatus::Cancelled,
            'cancel_reason' => $reason,
        ]);
    }
}
