<?php

namespace App\Modules\Ordering\Actions;

use App\Models\User;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Events\OrderStatusChanged;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
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
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

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

            // Invoice online yang masih pending ikut void: webhook SUCCESS
            // telat tidak boleh menghidupkan order (refund manual di DOKU).
            $payment = $locked->payment()->first();

            if ($payment !== null && $payment->status === PaymentStatus::Pending && $payment->provider !== null) {
                $payment->forceFill([
                    'status' => PaymentStatus::Voided,
                    'voided_at' => now(),
                ])->save();

                ($this->writeAuditLog)(
                    $actor,
                    'payment.voided_on_cancel',
                    $payment,
                    [
                        'status' => PaymentStatus::Pending->value,
                        'order_id' => $locked->id,
                    ],
                    [
                        'status' => PaymentStatus::Voided->value,
                        'voided_at' => $payment->voided_at?->toIso8601String(),
                    ],
                );
            }

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
