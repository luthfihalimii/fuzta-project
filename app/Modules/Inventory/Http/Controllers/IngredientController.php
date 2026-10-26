<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIngredientRequest;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class IngredientController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/stock/Ingredients', [
            'units' => Unit::query()
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Unit $unit) => [
                    'id' => $unit->id,
                    'code' => $unit->code,
                    'name' => $unit->name,
                ]),
            'ingredients' => Ingredient::query()
                ->with(['baseUnit', 'balances.branch'])
                ->orderBy('name')
                ->get()
                ->map(fn (Ingredient $ingredient) => [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'base_unit_id' => $ingredient->base_unit_id,
                    'unit' => [
                        'id' => $ingredient->baseUnit?->id,
                        'code' => $ingredient->baseUnit?->code,
                        'name' => $ingredient->baseUnit?->name,
                    ],
                    'low_stock_threshold' => $ingredient->low_stock_threshold,
                    'total_quantity' => $ingredient->balances->sum('quantity'),
                    'balances' => $ingredient->balances
                        ->map(fn ($balance) => [
                            'branch_id' => $balance->branch_id,
                            'branch_code' => $balance->branch?->code,
                            'branch_name' => $balance->branch?->name,
                            'quantity' => $balance->quantity,
                            'is_low_stock' => $balance->quantity <= $ingredient->low_stock_threshold,
                        ])
                        ->values()
                        ->all(),
                ]),
        ]);
    }

    public function store(StoreIngredientRequest $request): RedirectResponse
    {
        $ingredient = Ingredient::create($request->validated());

        return back()->with('success', "Bahan {$ingredient->name} berhasil ditambahkan.");
    }
}
