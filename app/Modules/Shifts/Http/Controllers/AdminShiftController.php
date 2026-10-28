<?php

namespace App\Modules\Shifts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shifts\Actions\CloseShift;
use App\Modules\Shifts\Actions\ReconcileShift;
use App\Modules\Shifts\Actions\RecordCashMovement;
use App\Modules\Shifts\Enums\CashMovementDirection;
use App\Modules\Shifts\Http\Requests\CloseShiftRequest;
use App\Modules\Shifts\Http\Requests\ReconcileShiftRequest;
use App\Modules\Shifts\Http\Requests\RecordCashMovementRequest;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Http\RedirectResponse;

class AdminShiftController extends Controller
{
    public function close(
        CloseShiftRequest $request,
        CashierShift $shift,
        CloseShift $closeShift,
    ): RedirectResponse {
        $this->authorize('close', $shift);
        $validated = $request->validated();

        $closeShift(
            $shift,
            $request->user(),
            (int) $validated['counted_cash'],
            (int) $validated['cash_deposited'],
            $validated['closing_note'] ?? null,
        );

        return back()->with('success', 'Shift berhasil ditutup.');
    }

    public function reconcile(
        ReconcileShiftRequest $request,
        CashierShift $shift,
        ReconcileShift $reconcileShift,
    ): RedirectResponse {
        $this->authorize('reconcile', $shift);
        $validated = $request->validated();

        $reconcileShift(
            $shift,
            $request->user(),
            (int) $validated['counted_cash'],
            (int) $validated['cash_deposited'],
            $validated['reconciliation_note'] ?? null,
        );

        return back()->with('success', 'Shift berhasil direkonsiliasi.');
    }

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
