<?php

namespace App\Modules\Ordering\Actions;

use App\Models\User;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentEventType;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidOrder
{
    public function __construct(
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(Order $order, User $actor, string $reason): void
    {
        DB::transaction(function () use ($order, $actor, $reason): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->payment_status !== PaymentStatus::Paid) {
                throw ValidationException::withMessages([
                    'order' => 'Hanya pesanan lunas yang dapat di-void.',
                ]);
            }

            $payment = $locked->payment()->first();

            if ($payment !== null && $payment->shift_id !== null && $payment->shift?->closed_at !== null) {
                throw ValidationException::withMessages([
                    'order' => 'Pembayaran pada shift yang sudah ditutup tidak dapat di-void. Gunakan modul refund.',
                ]);
            }

            $from = $locked->status;

            $locked->update([
                'status' => OrderStatus::Cancelled,
                'payment_status' => PaymentStatus::Voided,
            ]);

            if ($payment !== null) {
                $payment->update([
                    'status' => PaymentStatus::Voided,
                    'voided_at' => now(),
                ]);

                $payment->events()->create([
                    'type' => PaymentEventType::Voided,
                    'amount' => $payment->amount,
                    'actor_id' => $actor->id,
                    'reason' => $reason,
                ]);
            }

            if ($from !== OrderStatus::Cancelled) {
                $locked->statusEvents()->create([
                    'from_status' => $from,
                    'to_status' => OrderStatus::Cancelled,
                    'actor_id' => $actor->id,
                    'note' => $reason,
                ]);
            }

            ($this->writeAuditLog)(
                $actor,
                'order.voided',
                $locked,
                [
                    'status' => $from->value,
                    'payment_status' => PaymentStatus::Paid->value,
                ],
                [
                    'status' => OrderStatus::Cancelled->value,
                    'payment_status' => PaymentStatus::Voided->value,
                ],
                $reason,
            );
        });
    }
}
