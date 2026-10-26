<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, User $staff): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, User $staff): bool
    {
        return false;
    }
}
