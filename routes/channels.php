<?php

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('kitchen.{branch_id}', function (User $user, string $branchId): bool {
    return in_array($user->role(), [Role::Kitchen, Role::Owner], true)
        && $user->hasBranchAccess((int) $branchId);
}, ['guards' => ['web']]);

Broadcast::channel('pos.{branch_id}', function (User $user, string $branchId): bool {
    return in_array($user->role(), [Role::Cashier, Role::Owner], true)
        && $user->hasBranchAccess((int) $branchId);
}, ['guards' => ['web']]);
