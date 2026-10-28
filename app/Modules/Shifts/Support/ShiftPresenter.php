<?php

namespace App\Modules\Shifts\Support;

use App\Modules\Shifts\Actions\CalculateShiftTotals;
use App\Modules\Shifts\Models\CashierShift;

class ShiftPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(CashierShift $shift, ?array $liveTotals = null): array
    {
        if ($shift->closed_at === null) {
            $liveTotals ??= app(CalculateShiftTotals::class)($shift);
        } else {
            $liveTotals = [
                'cash_sales_total' => $shift->cash_sales_total,
                'qris_sales_total' => $shift->qris_sales_total,
                'cash_in_total' => $shift->cash_in_total,
                'cash_out_total' => $shift->cash_out_total,
                'expected_cash' => $shift->expected_cash,
            ];
        }

        $status = match (true) {
            $shift->closed_at === null => 'open',
            $shift->isAutoClosedUnreconciled() => 'auto_closed_unreconciled',
            default => 'closed',
        };

        return [
            'id' => $shift->id,
            'branch_id' => $shift->branch_id,
            'branch' => $shift->relationLoaded('branch') && $shift->branch
                ? [
                    'id' => $shift->branch->id,
                    'code' => $shift->branch->code,
                    'name' => $shift->branch->name,
                ]
                : null,
            'user_id' => $shift->user_id,
            'user_name' => $shift->relationLoaded('user') ? $shift->user?->name : null,
            'window_key' => $shift->window_key,
            'scheduled_start_at' => $shift->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $shift->scheduled_end_at?->toIso8601String(),
            'opened_at' => $shift->opened_at?->toIso8601String(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'opening_cash' => $shift->opening_cash,
            'cash_sales_total' => $liveTotals['cash_sales_total'],
            'qris_sales_total' => $liveTotals['qris_sales_total'],
            'cash_in_total' => $liveTotals['cash_in_total'],
            'cash_out_total' => $liveTotals['cash_out_total'],
            'expected_cash' => $liveTotals['expected_cash'],
            'counted_cash' => $shift->counted_cash,
            'variance' => $shift->variance,
            'cash_deposited' => $shift->cash_deposited,
            'closing_note' => $shift->closing_note,
            'close_type' => $shift->close_type?->value,
            'reconciled_at' => $shift->reconciled_at?->toIso8601String(),
            'reconciliation_note' => $shift->reconciliation_note,
            'status' => $status,
        ];
    }
}
