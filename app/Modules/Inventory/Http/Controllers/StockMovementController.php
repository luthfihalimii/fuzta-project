<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordStockWasteRequest;
use App\Http\Requests\StoreStockMovementRequest;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Support\BranchPresenter;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Ordering\Actions\ResolvePosBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockMovementController extends Controller
{
    public function index(
        Request $request,
        ResolvePosBranch $resolveBranch,
    ): Response {
        $user = $request->user();
        $requestedBranchId = $request->integer('branch');
        $branch = $resolveBranch($user, $requestedBranchId > 0 ? $requestedBranchId : null);

        return Inertia::render('admin/stock/Movements', [
            'branch' => $branch ? BranchPresenter::summary($branch) : null,
            'branches' => BranchPresenter::options(),
            'ingredients' => $this->ingredientOptions(),
            'movements' => $branch
                ? StockMovement::query()
                    ->with(['ingredient.baseUnit', 'unit', 'actor'])
                    ->where('branch_id', $branch->id)
                    ->latest('id')
                    ->limit(100)
                    ->get()
                    ->map(fn (StockMovement $movement) => $this->movementData($movement))
                    ->values()
                    ->all()
                : [],
        ]);
    }

    public function store(
        StoreStockMovementRequest $request,
        RecordStockMovement $recordStockMovement,
        ResolvePosBranch $resolveBranch,
    ): RedirectResponse {
        $data = $request->validated();
        $branchId = $this->targetBranchId($request, $resolveBranch);

        $movement = ($recordStockMovement)(
            $request->user(),
            $branchId,
            (int) $data['ingredient_id'],
            (int) $data['quantity'],
            StockMovementType::from($data['type']),
            $data['reason'],
        );

        return back()->with('success', "Pergerakan stok #{$movement->id} tercatat.");
    }

    public function kitchenIndex(
        Request $request,
        ResolvePosBranch $resolveBranch,
    ): Response {
        $user = $request->user();
        $requestedBranchId = $request->integer('branch');
        $branch = $resolveBranch($user, $requestedBranchId > 0 ? $requestedBranchId : null);

        $rows = $branch ? $this->stockRows($branch) : [];
        $lowStock = array_values(array_filter(
            $rows,
            fn (array $row) => $row['is_low_stock'],
        ));

        return Inertia::render('kitchen/Stock', [
            'branch' => $branch ? BranchPresenter::summary($branch) : null,
            'branches' => $user->isOwner() ? BranchPresenter::options() : [],
            'canChooseBranch' => $user->isOwner(),
            'ingredients' => $rows,
            'low_stock' => $lowStock,
        ]);
    }

    public function kitchenWaste(
        RecordStockWasteRequest $request,
        RecordStockMovement $recordStockMovement,
        ResolvePosBranch $resolveBranch,
    ): RedirectResponse {
        $data = $request->validated();
        $branchId = $this->targetBranchId($request, $resolveBranch);

        $movement = ($recordStockMovement)(
            $request->user(),
            $branchId,
            (int) $data['ingredient_id'],
            -abs((int) $data['quantity']),
            StockMovementType::Waste,
            $data['reason'],
        );

        return back()->with('success', "Stok rusak #{$movement->id} tercatat.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stockRows(Branch $branch): array
    {
        return Ingredient::query()
            ->with(['baseUnit', 'balances' => fn ($query) => $query->where('branch_id', $branch->id)])
            ->orderBy('name')
            ->get()
            ->map(function (Ingredient $ingredient) {
                $balance = $ingredient->balances->first();
                $quantity = (int) ($balance?->quantity ?? 0);

                return [
                    'ingredient_id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'unit' => $ingredient->baseUnit?->code,
                    'quantity' => $quantity,
                    'low_stock_threshold' => $ingredient->low_stock_threshold,
                    'is_low_stock' => $quantity <= $ingredient->low_stock_threshold,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function movementData(StockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'ingredient_id' => $movement->ingredient_id,
            'ingredient_name' => $movement->ingredient?->name,
            'unit' => $movement->unit?->code,
            'quantity' => $movement->quantity,
            'type' => $movement->type->value,
            'type_label' => $movement->type->label(),
            'reason' => $movement->reason,
            'actor_name' => $movement->actor?->name,
            'created_at' => $movement->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ingredientOptions(): array
    {
        return Ingredient::query()
            ->with('baseUnit')
            ->orderBy('name')
            ->get()
            ->map(fn (Ingredient $ingredient) => [
                'id' => $ingredient->id,
                'name' => $ingredient->name,
                'unit' => $ingredient->baseUnit?->code,
            ])
            ->values()
            ->all();
    }

    private function targetBranchId(
        Request $request,
        ResolvePosBranch $resolveBranch,
    ): int {
        $requestedBranchId = $request->integer('branch_id');

        if ($requestedBranchId > 0) {
            abort_unless(
                $request->user()->hasBranchAccess($requestedBranchId),
                403,
                'Anda tidak dapat mengubah stok cabang lain.',
            );

            return $requestedBranchId;
        }

        $branch = $resolveBranch($request->user());

        abort_if($branch === null, 422, 'Cabang belum tersedia.');

        return $branch->id;
    }
}
