<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Branches\Models\Branch;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, Branch $branch): bool
    {
        return false;
    }
}
