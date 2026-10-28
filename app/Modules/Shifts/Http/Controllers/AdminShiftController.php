<?php

namespace App\Modules\Shifts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shifts\Actions\RecordCashMovement;
use App\Modules\Shifts\Enums\CashMovementDirection;
use App\Modules\Shifts\Http\Requests\RecordCashMovementRequest;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Http\RedirectResponse;

class AdminShiftController extends Controller
{
    public function storeCashMovement(
        RecordCashMovementRequest $request,
        CashierShift $shift,
        RecordCashMovement $recordCashMovement,
    ): RedirectResponse {
        $this->authorize('recordCashMovement', CashierShift::class);

        $validated = $request->validated();

        $recordCashMovement(
            $shift,
            $request->user(),
            CashMovementDirection::from($validated['direction']),
            (int) $validated['amount'],
            $validated['reason'],
            $validated['idempotency_key'],
        );

        return back()->with('success', 'Kas shift berhasil dicatat.');
    }
}
