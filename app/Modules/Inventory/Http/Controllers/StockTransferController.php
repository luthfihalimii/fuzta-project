<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelStockTransferRequest;
use App\Http\Requests\DispatchStockTransferRequest;
use App\Http\Requests\ReceiveStockTransferRequest;
use App\Http\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Actions\CancelStockTransfer;
use App\Modules\Inventory\Actions\CreateStockTransfer;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Http\RedirectResponse;

class StockTransferController extends Controller
{
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
