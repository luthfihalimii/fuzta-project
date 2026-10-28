<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Actions\CreateStockTransfer;
use Illuminate\Http\RedirectResponse;

class StockTransferController extends Controller
{
    public function store(StoreStockTransferRequest $request, CreateStockTransfer $create): RedirectResponse
    {
        $create($request->user(), $request->validated());

        return back()->with('success', 'Draft transfer berhasil dibuat.');
    }
}
