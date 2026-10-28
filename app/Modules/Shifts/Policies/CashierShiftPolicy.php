<?php

namespace App\Modules\Shifts\Policies;

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use App\Modules\Shifts\Models\CashierShift;

class CashierShiftPolicy
{
    public function open(User $user, int $branchId): bool
    {
        return $user->is_active
            && in_array($user->role(), [Role::Owner, Role::Cashier], true)
            && $user->hasBranchAccess($branchId);
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role() === Role::Owner;
    }

    public function view(User $user, CashierShift $shift): bool
    {
        return $user->is_active
            && ($user->isOwner() || $shift->user_id === $user->id);
    }

    public function close(User $user, CashierShift $shift): bool
    {
        return $user->is_active
            && in_array($user->role(), [Role::Owner, Role::Cashier], true)
            && ($user->isOwner() || $shift->user_id === $user->id);
    }

    public function reconcile(User $user, CashierShift $shift): bool
    {
        return $this->close($user, $shift);
    }

    public function recordCashMovement(User $user): bool
    {
        return $user->is_active && $user->isOwner();
    }
}
