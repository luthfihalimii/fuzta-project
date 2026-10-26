<?php

namespace App\Modules\Ordering\Actions;

use App\Models\User;
use App\Modules\Branches\Models\Branch;

class ResolvePosBranch
{
    public function __invoke(User $user, ?int $requestedBranchId = null): ?Branch
    {
        if (! $user->isOwner()) {
            return $user->branch;
        }

        $query = Branch::query()->where('is_active', true);

        if ($requestedBranchId !== null) {
            $branch = (clone $query)->find($requestedBranchId);

            if ($branch !== null) {
                return $branch;
            }
        }

        return $query->orderBy('id')->first();
    }
}
