<?php

namespace App\Http\Controllers\Admin;

use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    public function index(): Response
    {
        $lowStock = DB::table('branches')
            ->crossJoin('ingredients')
            ->leftJoin('stock_balances', function ($join): void {
                $join->on('stock_balances.branch_id', '=', 'branches.id')
                    ->on('stock_balances.ingredient_id', '=', 'ingredients.id');
            })
            ->join('units', 'units.id', '=', 'ingredients.base_unit_id')
            ->where('branches.is_active', true)
            ->whereRaw('COALESCE(stock_balances.quantity, 0) <= ingredients.low_stock_threshold')
            ->orderBy('branches.code')
            ->orderBy('ingredients.name')
            ->get([
                'ingredients.id as ingredient_id',
                DB::raw('COALESCE(stock_balances.quantity, 0) as quantity'),
                'ingredients.name as ingredient_name',
                'ingredients.low_stock_threshold',
                'units.code as unit',
                'branches.id as branch_id',
                'branches.code as branch_code',
                'branches.name as branch_name',
            ])
            ->map(fn (object $row) => [
                'ingredient_id' => (int) $row->ingredient_id,
                'ingredient_name' => $row->ingredient_name,
                'quantity' => (int) $row->quantity,
                'low_stock_threshold' => (int) $row->low_stock_threshold,
                'unit' => $row->unit,
                'branch_id' => (int) $row->branch_id,
                'branch_code' => $row->branch_code,
                'branch_name' => $row->branch_name,
            ])
            ->values()
            ->all();

        $grace = (int) config('shifts.auto_close_grace_minutes', 15);
        $cutoff = now()->subMinutes($grace);

        $shiftAttention = [
            'overdue' => CashierShift::query()
                ->whereNull('closed_at')
                ->where('scheduled_end_at', '<=', now())
                ->count(),
            'unreconciled' => CashierShift::query()
                ->where('close_type', 'auto')
                ->whereNull('reconciled_at')
                ->count(),
            'unpaid' => CashierShift::query()
                ->whereNull('closed_at')
                ->where('scheduled_end_at', '<=', $cutoff)
                ->whereHas('orders', fn ($query) => $query
                    ->where('payment_status', 'unpaid')
                    ->where('status', '!=', 'cancelled'))
                ->count(),
        ];

        return Inertia::render('admin/Dashboard', [
            'low_stock' => $lowStock,
            'shift_attention' => $shiftAttention,
        ]);
    }
}
