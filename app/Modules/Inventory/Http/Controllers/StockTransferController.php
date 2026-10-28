<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelStockTransferRequest;
use App\Http\Requests\DispatchStockTransferRequest;
use App\Http\Requests\ReceiveStockTransferRequest;
use App\Http\Requests\StoreStockTransferRequest;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Actions\CancelStockTransfer;
use App\Modules\Inventory\Actions\CreateStockTransfer;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/stock/Transfers', [
            'transfers' => StockTransfer::query()
                ->with(['items.ingredient.baseUnit', 'originBranch:id,name', 'destinationBranch:id,name', 'createdBy:id,name'])
                ->latest()
                ->get()
                ->map(fn (StockTransfer $transfer) => [
                    'id' => $transfer->id,
                    'status' => $transfer->status->value,
                    'origin_name' => $transfer->originBranch?->name,
                    'destination_name' => $transfer->destinationBranch?->name,
                    'created_by_name' => $transfer->createdBy?->name,
                    'created_at' => $transfer->created_at?->toIso8601String(),
                    'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
                    'received_at' => $transfer->received_at?->toIso8601String(),
                    'cancel_reason' => $transfer->cancel_reason,
                    'items' => $transfer->items
                        ->map(fn (StockTransferItem $item) => [
                            'id' => $item->id,
                            'ingredient' => $item->ingredient?->name,
                            'unit_code' => $item->ingredient?->baseUnit?->code,
                            'quantity' => $item->quantity,
                            'received_quantity' => $item->received_quantity,
                            'discrepancy_reason' => $item->discrepancy_reason,
                        ])
                        ->values()
                        ->all(),
                ]),
            'branches' => Branch::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'ingredients' => Ingredient::query()
                ->with('baseUnit:id,code,name')
                ->orderBy('name')
                ->get(['id', 'name', 'base_unit_id'])
                ->map(fn (Ingredient $ingredient) => [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'unit_code' => $ingredient->baseUnit?->code,
                ]),
        ]);
    }

    public function store(StoreStockTransferRequest $request, CreateStockTransfer $create): RedirectResponse
    {
        $create($request->user(), $request->validated());

        return back()->with('success', 'Draft transfer berhasil dibuat.');
    }

    public function dispatch(
        DispatchStockTransferRequest $request,
        StockTransfer $transfer,
        DispatchStockTransfer $dispatch,
    ): RedirectResponse {
        $dispatch($request->user(), $transfer->id);

        return back()->with('success', 'Transfer telah dikirim.');
    }

    public function receive(
        ReceiveStockTransferRequest $request,
        StockTransfer $transfer,
        ReceiveStockTransfer $receive,
    ): RedirectResponse {
        $receive($request->user(), $transfer->id, $request->validated('received_items'));

        return back()->with('success', 'Transfer diterima.');
    }

    public function cancel(
        CancelStockTransferRequest $request,
        StockTransfer $transfer,
        CancelStockTransfer $cancel,
    ): RedirectResponse {
        $cancel($request->user(), $transfer->id, (string) $request->validated('cancel_reason'));

        return back()->with('success', 'Transfer dibatalkan.');
    }
}
