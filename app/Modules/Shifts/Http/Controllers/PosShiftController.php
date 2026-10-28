<?php

namespace App\Modules\Shifts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shifts\Actions\OpenShift;
use App\Modules\Shifts\Http\Requests\OpenShiftRequest;
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
}
