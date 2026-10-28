<?php

namespace App\Modules\Shifts\Queries;

use App\Modules\Shifts\Actions\CalculateShiftTotals;
use App\Modules\Shifts\Models\CashierShift;
use App\Modules\Shifts\Support\ShiftPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ShiftReportQuery
{
    public function __construct(
        private readonly CalculateShiftTotals $calculateTotals,
    ) {}

    /**
     * @param  array{from?: string, to?: string, branch?: int|null, user?: int|null, status?: string|null}  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        return $this->query($filters)
            ->with(['branch', 'user'])
            ->latest('opened_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CashierShift $shift) => $this->row($shift));
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(CashierShift $shift): array
    {
        $shift->load(['branch', 'user', 'cashMovements.actor', 'orders']);

        $totals = $shift->closed_at !== null && $shift->cash_sales_total !== null
            ? [
                'cash_sales_total' => $shift->cash_sales_total,
                'qris_sales_total' => $shift->qris_sales_total ?? 0,
                'cash_in_total' => $shift->cash_in_total ?? 0,
                'cash_out_total' => $shift->cash_out_total ?? 0,
                'expected_cash' => $shift->expected_cash ?? 0,
            ]
            : ($this->calculateTotals)($shift);

        return [
            'shift' => ShiftPresenter::summary($shift),
            'summary' => [
                ...$totals,
                'order_count' => $shift->orders->count(),
                'movement_count' => $shift->cashMovements->count(),
            ],
            'totals' => $totals,
            'movements' => $shift->cashMovements
                ->map(fn ($movement) => [
                    'id' => $movement->id,
                    'direction' => $movement->direction->value,
                    'amount' => $movement->amount,
                    'reason' => $movement->reason,
                    'actor_name' => $movement->actor?->name,
                    'created_at' => $movement->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'orders' => $shift->orders
                ->map(fn ($order) => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'total' => $order->total,
                    'status' => $order->status->value,
                    'payment_status' => $order->payment_status->value,
                    'created_at' => $order->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{from?: string, to?: string, branch?: int|null, user?: int|null, status?: string|null}  $filters
     */
    private function query(array $filters): Builder
    {
        $from = isset($filters['from']) && $filters['from'] !== ''
            ? CarbonImmutable::parse($filters['from'], 'Asia/Jakarta')->startOfDay()
            : null;
        $to = isset($filters['to']) && $filters['to'] !== ''
            ? CarbonImmutable::parse($filters['to'], 'Asia/Jakarta')->endOfDay()
            : null;

        return CashierShift::query()
            ->when($from !== null, fn (Builder $query) => $query->where('opened_at', '>=', $from->utc()))
            ->when($to !== null, fn (Builder $query) => $query->where('opened_at', '<=', $to->utc()))
            ->when(($filters['branch'] ?? null) !== null, fn (Builder $query) => $query->where('branch_id', $filters['branch']))
            ->when(($filters['user'] ?? null) !== null, fn (Builder $query) => $query->where('user_id', $filters['user']))
            ->when(($filters['status'] ?? null) === 'open', fn (Builder $query) => $query->whereNull('closed_at'))
            ->when(($filters['status'] ?? null) === 'unreconciled', fn (Builder $query) => $query
                ->where('close_type', 'auto')
                ->whereNull('reconciled_at'))
            ->when(($filters['status'] ?? null) === 'closed', fn (Builder $query) => $query->whereNotNull('closed_at'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CashierShift $shift): array
    {
        return [
            ...ShiftPresenter::summary($shift),
            'branch_code' => $shift->branch?->code,
            'branch_name' => $shift->branch?->name,
            'cashier_name' => $shift->user?->name,
        ];
    }
}
