<?php

namespace App\Modules\Shifts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shifts\Actions\CloseShift;
use App\Modules\Shifts\Actions\OpenShift;
use App\Modules\Shifts\Actions\ReconcileShift;
use App\Modules\Shifts\Actions\ResolveShiftForClose;
use App\Modules\Shifts\Http\Requests\CloseShiftRequest;
use App\Modules\Shifts\Http\Requests\OpenShiftRequest;
use App\Modules\Shifts\Http\Requests\ReconcileShiftRequest;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class PosShiftController extends Controller
{
    public function open(OpenShiftRequest $request, OpenShift $openShift): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $branchId = $user->isOwner()
            ? (int) $validated['branch_id']
            : (int) $user->branch_id;

        if ($branchId === 0) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang kasir belum diatur.',
            ]);
        }

        $this->authorize('open', [CashierShift::class, $branchId]);

        $openShift($user, $branchId);

        return back()->with('success', 'Shift berhasil dibuka.');
    }

    public function close(
        CloseShiftRequest $request,
        CloseShift $closeShift,
        ResolveShiftForClose $resolveShift,
    ): RedirectResponse {
        $user = $request->user();
        $validated = $request->validated();
        $branchId = $user->isOwner()
            ? (int) $validated['branch_id']
            : (int) $user->branch_id;

        if ($branchId === 0) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang kasir belum diatur.',
            ]);
        }

        $shift = $resolveShift($user, $branchId);
        $this->authorize('close', $shift);

        $closeShift(
            $shift,
            $user,
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
}
