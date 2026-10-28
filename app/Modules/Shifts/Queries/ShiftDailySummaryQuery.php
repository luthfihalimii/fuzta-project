<?php

namespace App\Modules\Shifts\Queries;

use App\Modules\Shifts\Models\CashierShift;
use Carbon\CarbonImmutable;

class ShiftDailySummaryQuery
{
    /**
     * @param  array{from?: string, to?: string, branch?: int|null, user?: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function __invoke(array $filters): array
    {
        $timezone = (string) config('shifts.timezone', 'Asia/Jakarta');
        $from = isset($filters['from']) && $filters['from'] !== ''
            ? CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();
        $to = isset($filters['to']) && $filters['to'] !== ''
            ? CarbonImmutable::parse($filters['to'], $timezone)->endOfDay()
            : ($from->setTimezone($timezone)->endOfDay());

        $shifts = CashierShift::query()
            ->with(['branch', 'user'])
            ->whereBetween('opened_at', [$from->utc(), $to->utc()])
            ->when(($filters['branch'] ?? null) !== null, fn ($query) => $query->where('branch_id', $filters['branch']))
            ->when(($filters['user'] ?? null) !== null, fn ($query) => $query->where('user_id', $filters['user']))
            ->orderBy('opened_at')
            ->get();

        return $shifts
            ->groupBy(fn (CashierShift $shift) => implode('|', [
                $shift->opened_at->setTimezone((string) config('shifts.timezone', 'Asia/Jakarta'))->toDateString(),
                $shift->branch_id,
                $shift->user_id,
            ]))
            ->map(function ($group) {
                $first = $group->first();
                $closed = $group->whereNotNull('closed_at');
                $reconciled = $closed->whereNotNull('reconciled_at');

                return [
                    'date' => $first->opened_at->setTimezone((string) config('shifts.timezone', 'Asia/Jakarta'))->toDateString(),
                    'branch_id' => $first->branch_id,
                    'branch_code' => $first->branch?->code,
                    'branch_name' => $first->branch?->name,
                    'user_id' => $first->user_id,
                    'cashier_name' => $first->user?->name,
                    'closed_shift_count' => $closed->count(),
                    'open_count' => $group->whereNull('closed_at')->count(),
                    'unreconciled_count' => $closed
                        ->where('close_type', 'auto')
                        ->whereNull('reconciled_at')
                        ->count(),
                    'cash_sales_total' => (int) $closed->sum('cash_sales_total'),
                    'qris_sales_total' => (int) $closed->sum('qris_sales_total'),
                    'cash_in_total' => (int) $closed->sum('cash_in_total'),
                    'cash_out_total' => (int) $closed->sum('cash_out_total'),
                    'expected_cash' => (int) $closed->sum('expected_cash'),
                    'counted_cash' => (int) $reconciled->sum('counted_cash'),
                    'variance' => (int) $reconciled->sum('variance'),
                    'cash_deposited' => (int) $reconciled->sum('cash_deposited'),
                ];
            })
            ->values()
            ->all();
    }
}
