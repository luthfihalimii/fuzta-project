<?php

namespace App\Modules\Shifts\Actions;

use App\Models\User;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Validation\ValidationException;

class ResolveOpenShift
{
    public function __invoke(User $actor, int $branchId, bool $lockForShare = false): CashierShift
    {
        $query = CashierShift::query()
            ->where('user_id', $actor->id)
            ->where('branch_id', $branchId)
            ->whereNull('closed_at');

        if ($lockForShare) {
            $query->sharedLock();
        }

        $shift = $query->first();

        if ($shift === null) {
            throw ValidationException::withMessages([
                'shift' => 'Buka shift terlebih dahulu.',
            ]);
        }

        return $shift;
    }
}
