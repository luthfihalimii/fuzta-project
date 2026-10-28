<?php

namespace App\Modules\Shifts\Actions;

use App\Models\User;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Validation\ValidationException;

class ResolveShiftForClose
{
    public function __invoke(User $actor, ?int $branchId = null): CashierShift
    {
        $query = CashierShift::query()
            ->where('user_id', $actor->id)
            ->whereNull('closed_at');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $shift = $query->first();

        if ($shift === null) {
            throw ValidationException::withMessages([
                'shift' => 'Tidak ada shift terbuka.',
            ]);
        }

        return $shift;
    }
}
