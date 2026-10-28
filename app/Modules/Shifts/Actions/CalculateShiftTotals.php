<?php

namespace App\Modules\Shifts\Actions;

use App\Modules\Shifts\Enums\CashMovementDirection;
use App\Modules\Shifts\Models\CashierShift;

class CalculateShiftTotals
{
    /**
     * @return array{
     *     cash_sales_total: int,
     *     qris_sales_total: int,
     *     cash_in_total: int,
     *     cash_out_total: int,
     *     expected_cash: int
     * }
     */
    public function __invoke(CashierShift $shift): array
    {
        $cashSales = (int) $shift->payments()
            ->where('method', 'cash')
            ->where('status', 'paid')
            ->sum('amount');

        $qrisSales = (int) $shift->payments()
            ->where('method', 'qris_static')
            ->where('status', 'paid')
            ->sum('amount');

        $cashIn = (int) $shift->cashMovements()
            ->where('direction', CashMovementDirection::In->value)
            ->sum('amount');

        $cashOut = (int) $shift->cashMovements()
            ->where('direction', CashMovementDirection::Out->value)
            ->sum('amount');

        return [
            'cash_sales_total' => $cashSales,
            'qris_sales_total' => $qrisSales,
            'cash_in_total' => $cashIn,
            'cash_out_total' => $cashOut,
            'expected_cash' => (int) $shift->opening_cash + $cashSales + $cashIn - $cashOut,
        ];
    }
}
