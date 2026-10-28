<?php

namespace App\Modules\Shifts\Actions;

use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Shifts\Enums\ShiftCloseType;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Support\Facades\DB;

class AutoCloseShift
{
    public function __construct(
        private readonly CalculateShiftTotals $calculateTotals,
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(): int
    {
        $grace = (int) config('shifts.auto_close_grace_minutes', 15);
        $cutoff = now()->subMinutes($grace);

        $ids = CashierShift::query()
            ->whereNull('closed_at')
            ->where('scheduled_end_at', '<=', $cutoff)
            ->pluck('id');

        $closed = 0;

        foreach ($ids as $id) {
            $didClose = DB::transaction(function () use ($id): bool {
                $shift = CashierShift::query()->lockForUpdate()->find($id);

                if ($shift === null || ! $shift->isOpen()) {
                    return false;
                }

                $unpaid = $shift->orders()
                    ->where('payment_status', 'unpaid')
                    ->where('status', '!=', 'cancelled')
                    ->count();

                if ($unpaid > 0) {
                    return false;
                }

                $totals = ($this->calculateTotals)($shift);

                $shift->fill([
                    ...$totals,
                    'closed_at' => now(),
                    'closed_by_id' => null,
                    'close_type' => ShiftCloseType::Auto,
                    'closing_note' => 'Ditutup otomatis oleh sistem karena melewati jadwal shift.',
                    'counted_cash' => null,
                    'variance' => null,
                    'cash_deposited' => null,
                    'reconciled_at' => null,
                    'reconciled_by_id' => null,
                    'reconciliation_note' => null,
                ])->save();

                ($this->writeAuditLog)(
                    null,
                    'shift.auto_closed',
                    $shift,
                    ['status' => 'open'],
                    [
                        'expected_cash' => $totals['expected_cash'],
                        'cash_sales_total' => $totals['cash_sales_total'],
                        'close_type' => ShiftCloseType::Auto->value,
                    ],
                    'Ditutup otomatis karena melewati jadwal shift.',
                );

                return true;
            });

            if ($didClose) {
                $closed++;
            }
        }

        return $closed;
    }
}
