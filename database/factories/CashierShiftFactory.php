<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shifts\Enums\ShiftCloseType;
use App\Modules\Shifts\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashierShift>
 */
class CashierShiftFactory extends Factory
{
    protected $model = CashierShift::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now('Asia/Jakarta')->startOfDay()->addHours(9);

        return [
            'branch_id' => Branch::factory(),
            'user_id' => User::factory()->cashier(),
            'window_key' => 'morning',
            'scheduled_start_at' => $start->utc(),
            'scheduled_end_at' => $start->addHours(6)->utc(),
            'opened_at' => now(),
            'closed_at' => null,
            'opening_cash' => 0,
            'cash_sales_total' => null,
            'qris_sales_total' => null,
            'cash_in_total' => null,
            'cash_out_total' => null,
            'expected_cash' => null,
            'counted_cash' => null,
            'variance' => null,
            'cash_deposited' => null,
            'closing_note' => null,
            'closed_by_id' => null,
            'close_type' => null,
            'reconciled_at' => null,
            'reconciled_by_id' => null,
            'reconciliation_note' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'closed_at' => null,
            'close_type' => null,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'closed_at' => now(),
            'close_type' => ShiftCloseType::Manual,
            'reconciled_at' => now(),
            'cash_sales_total' => 0,
            'qris_sales_total' => 0,
            'cash_in_total' => 0,
            'cash_out_total' => 0,
            'expected_cash' => 0,
            'counted_cash' => 0,
            'variance' => 0,
            'cash_deposited' => 0,
        ])->afterCreating(function (CashierShift $shift): void {
            $shift->forceFill([
                'closed_by_id' => $shift->user_id,
                'reconciled_by_id' => $shift->user_id,
            ])->save();
        });
    }

    public function autoClosed(): static
    {
        return $this->state(fn (array $attributes) => [
            'closed_at' => now(),
            'close_type' => ShiftCloseType::Auto,
            'reconciled_at' => null,
            'reconciled_by_id' => null,
            'reconciliation_note' => null,
            'cash_sales_total' => 0,
            'qris_sales_total' => 0,
            'cash_in_total' => 0,
            'cash_out_total' => 0,
            'expected_cash' => 0,
            'counted_cash' => null,
            'variance' => null,
            'cash_deposited' => null,
            'closing_note' => 'Ditutup otomatis oleh sistem karena melewati jadwal shift.',
            'closed_by_id' => null,
        ]);
    }
}
