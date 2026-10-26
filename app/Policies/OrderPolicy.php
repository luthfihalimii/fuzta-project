<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->hasBranchAccess($order->branch_id);
    }

    public function pay(User $user, Order $order): bool
    {
        return $user->hasBranchAccess($order->branch_id)
            && in_array($user->role(), [Role::Owner, Role::Cashier], true)
            && $order->payment_status === PaymentStatus::Unpaid
            && $order->status !== OrderStatus::Cancelled;
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->hasBranchAccess($order->branch_id)
            && in_array($user->role(), [Role::Owner, Role::Cashier], true);
    }

    public function void(User $user, Order $order): bool
    {
        return $user->role() === Role::Owner
            && $order->payment_status === PaymentStatus::Paid;
    }
}
