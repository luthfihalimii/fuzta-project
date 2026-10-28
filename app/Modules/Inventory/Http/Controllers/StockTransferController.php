<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\DispatchStockTransferRequest;
use App\Http\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Actions\CreateStockTransfer;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
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
}
