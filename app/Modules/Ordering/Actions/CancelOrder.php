<?php

namespace App\Modules\Ordering\Actions;

use App\Models\User;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Events\OrderStatusChanged;
use App\Modules\Ordering\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelOrder
{
    public function __construct(
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(Order $order, User $actor, string $reason): void
    {
        DB::transaction(function () use ($order, $actor, $reason): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if (! $locked->canBeCancelled()) {
                throw ValidationException::withMessages([
                    'order' => 'Pesanan sudah diproses dan tidak dapat dibatalkan.',
                ]);
            }

            $from = $locked->status;

            $locked->update([
                'status' => OrderStatus::Cancelled,
            ]);

            $locked->statusEvents()->create([
                'from_status' => $from,
                'to_status' => OrderStatus::Cancelled,
                'actor_id' => $actor->id,
                'note' => $reason,
            ]);

            ($this->writeAuditLog)(
                $actor,
                'order.cancelled',
                $locked,
                [
                    'status' => $from->value,
                    'payment_status' => $locked->payment_status->value,
                ],
                [
                    'status' => OrderStatus::Cancelled->value,
                    'payment_status' => $locked->payment_status->value,
                ],
                $reason,
            );

            event(new OrderStatusChanged($locked, $from, OrderStatus::Cancelled));
        });
    }
}
