<?php

namespace App\Modules\Reporting\Queries;

use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class StockReportQuery
{
    /**
     * @return array{
     *     balances: list<array{
     *         branch_id: int,
     *         branch_code: string,
     *         branch_name: string,
     *         ingredient_id: int,
     *         ingredient_name: string,
     *         quantity: int,
     *         low_stock_threshold: int,
     *         unit: string,
     *         is_low_stock: bool
     *     }>,
     *     low_stock: list<array<string, mixed>>,
     *     movements: list<array<string, mixed>>
     * }
     */
    public function __invoke(?int $branchId = null): array
    {
        $balances = DB::table('branches')
            ->crossJoin('ingredients')
            ->join('units', 'units.id', '=', 'ingredients.base_unit_id')
            ->leftJoin('stock_balances', function ($join): void {
                $join->on('stock_balances.branch_id', '=', 'branches.id')
                    ->on('stock_balances.ingredient_id', '=', 'ingredients.id');
            })
            ->where('branches.is_active', true)
            ->when($branchId !== null, fn ($query) => $query->where('branches.id', $branchId))
            ->orderBy('branches.code')
            ->orderBy('ingredients.name')
            ->get([
                'branches.id as branch_id',
                'branches.code as branch_code',
                'branches.name as branch_name',
                'ingredients.id as ingredient_id',
                'ingredients.name as ingredient_name',
                DB::raw('COALESCE(stock_balances.quantity, 0) as quantity'),
                'ingredients.low_stock_threshold',
                'units.code as unit',
            ])
            ->map(function (object $row) {
                $quantity = (int) $row->quantity;
                $threshold = (int) $row->low_stock_threshold;

                return [
                    'branch_id' => (int) $row->branch_id,
                    'branch_code' => (string) $row->branch_code,
                    'branch_name' => (string) $row->branch_name,
                    'ingredient_id' => (int) $row->ingredient_id,
                    'ingredient_name' => (string) $row->ingredient_name,
                    'quantity' => $quantity,
                    'low_stock_threshold' => $threshold,
                    'unit' => (string) $row->unit,
                    'is_low_stock' => $quantity <= $threshold,
                ];
            })
            ->values()
            ->all();

        $lowStock = array_values(array_filter(
            $balances,
            fn (array $row) => $row['is_low_stock'],
        ));

        $movements = StockMovement::query()
            ->with(['branch', 'ingredient', 'unit', 'actor'])
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'branch_id' => $movement->branch_id,
                'branch_code' => $movement->branch?->code,
                'branch_name' => $movement->branch?->name,
                'ingredient_id' => $movement->ingredient_id,
                'ingredient_name' => $movement->ingredient?->name,
                'unit' => $movement->unit?->code,
                'quantity' => $movement->quantity,
                'type' => $movement->type->value,
                'type_label' => $movement->type->label(),
                'reason' => $movement->reason,
                'actor_name' => $movement->actor?->name,
                'created_at' => $movement->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'balances' => $balances,
            'low_stock' => $lowStock,
            'movements' => $movements,
        ];
    }
}
