<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitStockCountRequest;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Support\BranchPresenter;
use App\Modules\Inventory\Actions\SubmitStockCount;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Ordering\Actions\ResolvePosBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockCountController extends Controller
{
    public function index(
        Request $request,
        ResolvePosBranch $resolveBranch,
    ): Response {
        $user = $request->user();
        $requestedBranchId = $request->integer('branch');
        $branch = $resolveBranch($user, $requestedBranchId > 0 ? $requestedBranchId : null);

        return Inertia::render('admin/stock/Count', [
            'branch' => $branch ? BranchPresenter::summary($branch) : null,
            'branches' => BranchPresenter::options(),
            'ingredients' => $branch ? $this->stockRows($branch) : [],
            'counts' => $branch
                ? StockCount::query()
                    ->with('submittedBy')
                    ->withCount('items')
                    ->where('branch_id', $branch->id)
                    ->latest('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (StockCount $count) => [
                        'id' => $count->id,
                        'submitted_by_name' => $count->submittedBy?->name,
                        'note' => $count->note,
                        'items_count' => $count->items_count,
                        'created_at' => $count->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all()
                : [],
        ]);
    }

    public function store(
        SubmitStockCountRequest $request,
        SubmitStockCount $submitStockCount,
        ResolvePosBranch $resolveBranch,
    ): RedirectResponse {
        $count = $this->submit($request, $submitStockCount, $resolveBranch);

        return back()->with('success', "Opname #{$count->id} tersimpan.");
    }

    public function kitchenStore(
        SubmitStockCountRequest $request,
        SubmitStockCount $submitStockCount,
        ResolvePosBranch $resolveBranch,
    ): RedirectResponse {
        $count = $this->submit($request, $submitStockCount, $resolveBranch);

        return back()->with('success', "Opname #{$count->id} tersimpan.");
    }

    private function submit(
        SubmitStockCountRequest $request,
        SubmitStockCount $submitStockCount,
        ResolvePosBranch $resolveBranch,
    ): StockCount {
        $data = $request->validated();
        $branchId = $this->targetBranchId($request, $resolveBranch);

        return ($submitStockCount)(
            $request->user(),
            $branchId,
            array_map(fn (array $item) => [
                'ingredient_id' => (int) $item['ingredient_id'],
                'counted_quantity' => (int) $item['counted_quantity'],
            ], $data['counted_items']),
            $data['note'] ?? null,
        );
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
                $quantity = (int) ($ingredient->balances->first()?->quantity ?? 0);

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

    private function targetBranchId(
        Request $request,
        ResolvePosBranch $resolveBranch,
    ): int {
        $requestedBranchId = $request->integer('branch_id');

        if ($requestedBranchId > 0) {
            abort_unless(
                $request->user()->hasBranchAccess($requestedBranchId),
                403,
                'Anda tidak dapat menghitung stok cabang lain.',
            );

            return $requestedBranchId;
        }

        $branch = $resolveBranch($request->user());

        abort_if($branch === null, 422, 'Cabang belum tersedia.');

        return $branch->id;
    }
}
