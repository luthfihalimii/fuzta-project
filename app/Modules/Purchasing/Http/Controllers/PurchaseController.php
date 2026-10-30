<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseRequest;
use App\Modules\Branches\Support\BranchPresenter;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Ordering\Actions\ResolvePosBranch;
use App\Modules\Purchasing\Actions\RecordPurchase;
use App\Modules\Purchasing\Data\RecordPurchaseData;
use App\Modules\Purchasing\Models\Purchase;
use App\Modules\Purchasing\Models\PurchaseItem;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function posCreate(Request $request, ResolvePosBranch $resolveBranch): Response
    {
        return Inertia::render('pos/ReceiveStock', $this->formProps($request, $resolveBranch));
    }

    public function kitchenCreate(Request $request, ResolvePosBranch $resolveBranch): Response
    {
        return Inertia::render('kitchen/ReceiveStock', $this->formProps($request, $resolveBranch));
    }

    public function create(Request $request, ResolvePosBranch $resolveBranch): Response
    {
        return Inertia::render('admin/purchases/Receive', $this->formProps($request, $resolveBranch));
    }

    public function index(Request $request, ResolvePosBranch $resolveBranch): Response
    {
        $requestedBranchId = $request->integer('branch');
        $branch = $resolveBranch($request->user(), $requestedBranchId > 0 ? $requestedBranchId : null);

        return Inertia::render('admin/purchases/Index', [
            'branch' => $branch ? BranchPresenter::summary($branch) : null,
            'branches' => BranchPresenter::options(),
            'purchases' => $branch
                ? Purchase::query()
                    ->with(['supplier', 'branch', 'recordedBy'])
                    ->where('branch_id', $branch->id)
                    ->orderByDesc('received_at')
                    ->orderByDesc('id')
                    ->limit(100)
                    ->get()
                    ->map(fn (Purchase $purchase) => [
                        'id' => $purchase->id,
                        'received_at' => $purchase->received_at?->format('Y-m-d'),
                        'reference' => $purchase->reference,
                        'supplier_name' => $purchase->supplier?->name,
                        'branch_name' => $purchase->branch?->name,
                        'total' => $purchase->total,
                        'recorded_by' => $purchase->recordedBy?->name,
                    ])
                    ->values()
                    ->all()
                : [],
        ]);
    }

    public function show(Purchase $purchase): Response
    {
        $purchase->load(['supplier', 'branch', 'recordedBy', 'items.ingredient', 'items.unit']);

        return Inertia::render('admin/purchases/Show', [
            'purchase' => [
                'id' => $purchase->id,
                'received_at' => $purchase->received_at?->format('Y-m-d'),
                'reference' => $purchase->reference,
                'total' => $purchase->total,
                'supplier_name' => $purchase->supplier?->name,
                'branch_name' => $purchase->branch?->name,
                'recorded_by' => $purchase->recordedBy?->name,
                'created_at' => $purchase->created_at?->toIso8601String(),
                'items' => $purchase->items
                    ->map(fn (PurchaseItem $item) => [
                        'id' => $item->id,
                        'ingredient_name' => $item->ingredient?->name,
                        'unit_code' => $item->unit?->code,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'line_total' => $item->line_total,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function store(StorePurchaseRequest $request, RecordPurchase $recordPurchase): RedirectResponse
    {
        $data = $request->validated();

        $purchase = $recordPurchase($request->user(), new RecordPurchaseData(
            branchId: (int) $data['branch_id'],
            supplierId: (int) $data['supplier_id'],
            receivedAt: $data['received_at'],
            reference: $data['reference'] ?? null,
            idempotencyKey: $data['idempotency_key'],
            items: array_map(fn (array $item) => [
                'ingredient_id' => (int) $item['ingredient_id'],
                'unit_id' => (int) $item['unit_id'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (int) $item['unit_price'],
            ], $data['items']),
        ));

        return back()->with('success', "Penerimaan #{$purchase->id} tercatat.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(Request $request, ResolvePosBranch $resolveBranch): array
    {
        $user = $request->user();
        $requestedBranchId = $request->integer('branch');
        $branch = $resolveBranch($user, $requestedBranchId > 0 ? $requestedBranchId : null);

        return [
            'branch' => $branch ? BranchPresenter::summary($branch) : null,
            'branches' => $user->isOwner() ? BranchPresenter::options() : [],
            'canChooseBranch' => $user->isOwner(),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'units' => Unit::query()->orderBy('name')->get(['id', 'code', 'name'])->all(),
            'ingredients' => Ingredient::query()->with('baseUnit')->orderBy('name')->get()
                ->map(fn (Ingredient $ingredient) => [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'base_unit_id' => $ingredient->base_unit_id,
                    'unit' => $ingredient->baseUnit?->code,
                ])
                ->values()
                ->all(),
            'default_date' => now()->toDateString(),
        ];
    }
}
