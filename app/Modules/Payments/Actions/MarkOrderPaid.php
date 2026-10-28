<?php

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentEventType;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Shifts\Actions\ResolveOpenShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkOrderPaid
{
    public function __construct(
        private readonly WriteAuditLog $writeAuditLog,
        private readonly ResolveOpenShift $resolveOpenShift,
    ) {}

    public function __invoke(
        Order $order,
        User $actor,
        PaymentMethod $method,
        ?int $cashReceived = null,
    ): Payment {
        return DB::transaction(function () use ($order, $actor, $method, $cashReceived): Payment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            $existing = $locked->payment()->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($locked->status === OrderStatus::Cancelled || $locked->payment_status === PaymentStatus::Voided) {
                throw ValidationException::withMessages([
                    'order' => 'Pesanan yang dibatalkan tidak dapat dibayar.',
                ]);
            }

            if ($locked->payment_status !== PaymentStatus::Unpaid) {
                throw ValidationException::withMessages([
                    'order' => 'Status pembayaran pesanan tidak valid.',
                ]);
            }

            $shift = ($this->resolveOpenShift)($actor, $locked->branch_id, lockForShare: true);

            if ($method === PaymentMethod::Cash) {
                if ($cashReceived !== null && ($cashReceived < 0 || $cashReceived < $locked->total)) {
                    throw ValidationException::withMessages([
                        'cash_received' => 'Uang diterima kurang dari total pesanan.',
                    ]);
                }
            } elseif ($cashReceived !== null) {
                throw ValidationException::withMessages([
                    'cash_received' => 'Pembayaran QRIS tidak memakai uang tunai.',
                ]);
            }

            $change = $method === PaymentMethod::Cash && $cashReceived !== null
                ? $cashReceived - $locked->total
                : null;

            $payment = Payment::create([
                'order_id' => $locked->id,
                'shift_id' => $shift->id,
                'method' => $method,
                'amount' => $locked->total,
                'cash_received' => $method === PaymentMethod::Cash ? $cashReceived : null,
                'change_amount' => $change,
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
            ]);

            $locked->update([
                'payment_status' => PaymentStatus::Paid,
            ]);

            $payment->events()->create([
                'type' => PaymentEventType::Paid,
                'amount' => $payment->amount,
                'actor_id' => $actor->id,
            ]);

            ($this->writeAuditLog)(
                $actor,
                'order.paid',
                $locked,
                ['payment_status' => PaymentStatus::Unpaid->value],
                [
                    'payment_status' => PaymentStatus::Paid->value,
                    'payment_id' => $payment->id,
                    'method' => $method->value,
                    'amount' => $payment->amount,
                    'cash_received' => $payment->cash_received,
                    'change_amount' => $payment->change_amount,
                ],
            );

            return $payment;
        });
    }
}
