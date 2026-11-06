<?php

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Events\OrderPaymentChanged;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentEventType;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Lunasi order dari webhook DOKU SUCCESS. Idempotent + aman terhadap
 * webhook terlambat/kadaluarsa:
 * - nominal webhook !== order.total → 422 (log, tanpa PII), tidak paid;
 * - order dibatalkan / payment voided → tidak menghidupkan order; catat
 *   payment.late_payment + event late_paid SEKALI per payment (DOKU bisa
 *   retry notifikasi) sebagai jejak rekonsiliasi refund manual;
 * - payment sudah paid → no-op (replay aman);
 * - sukses → paid + broadcast OrderPaymentChanged SETELAH commit.
 *
 * Urutan lock seragam di seluruh kodebase: order dulu, lalu payment
 * (sama dengan CancelOrder/VoidOrder/ExpireUnpaidOrders) — cegah deadlock.
 */
class SettleDokuPayment
{
    public function __construct(
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(string $invoiceNumber, int $amount): void
    {
        DB::transaction(function () use ($invoiceNumber, $amount): void {
            // Baca datar (tanpa lock) hanya untuk mengambil order_id.
            $payment = Payment::query()
                ->where('provider_reference', $invoiceNumber)
                ->first();

            if ($payment === null) {
                throw ValidationException::withMessages([
                    'order' => 'Tagihan tidak dikenal.',
                ]);
            }

            $order = Order::query()
                ->whereKey($payment->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Fetch ulang dengan lock: baris bisa berubah antara baca
            // datar dan lock (re-invoice/cancel/settle konkuren).
            $payment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($amount !== $order->total) {
                Log::warning('DOKU webhook nominal tidak cocok dengan total order.', [
                    'order_id' => $order->id,
                    'invoice_number' => $invoiceNumber,
                    'expected' => $order->total,
                    'received' => $amount,
                ]);

                throw ValidationException::withMessages([
                    'order' => 'Nominal pembayaran tidak sesuai.',
                ]);
            }

            if ($order->status === OrderStatus::Cancelled || $payment->status === PaymentStatus::Voided) {
                // Target audit = paymen: cek dulu agar retry notifikasi DOKU
                // tidak menuliskan baris audit duplikat.
                $alreadyAudited = AuditLog::query()
                    ->where('action', 'payment.late_payment')
                    ->where('target_type', Payment::class)
                    ->where('target_id', $payment->id)
                    ->exists();

                if (! $alreadyAudited) {
                    ($this->writeAuditLog)(
                        null,
                        'payment.late_payment',
                        $payment,
                        [
                            'order_status' => $order->status->value,
                            'payment_status' => $payment->status->value,
                        ],
                        [
                            'provider' => $payment->provider,
                            'invoice_number' => $invoiceNumber,
                        ],
                    );
                }

                $alreadyRecorded = $payment->events()
                    ->where('type', PaymentEventType::LatePaid)
                    ->exists();

                if (! $alreadyRecorded) {
                    $payment->events()->create([
                        'type' => PaymentEventType::LatePaid,
                        'amount' => $amount,
                        'actor_id' => null,
                        'reason' => 'Pembayaran diterima setelah order dibatalkan; refund manual via dashboard DOKU.',
                    ]);
                }

                if (! $alreadyAudited) {
                    Log::critical('Pembayaran DOKU masuk setelah pembatalan lokal.', [
                        'payment_id' => $payment->id,
                        'invoice_number' => $invoiceNumber,
                        'amount' => $amount,
                    ]);
                }

                return;
            }

            if ($payment->status !== PaymentStatus::Pending) {
                return; // paid (replay) / status tak terduga: tidak ada efek ganda.
            }

            $payment->forceFill([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
            ])->save();

            $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();

            $payment->events()->create([
                'type' => PaymentEventType::Paid,
                'amount' => $payment->amount,
                'actor_id' => null,
            ]);

            ($this->writeAuditLog)(
                null,
                'payment.paid',
                $order,
                [
                    'payment_status' => PaymentStatus::Unpaid->value,
                ],
                [
                    'payment_status' => PaymentStatus::Paid->value,
                    'provider' => $payment->provider,
                    'invoice_number' => $invoiceNumber,
                    'amount' => $payment->amount,
                ],
            );

            // Broadcast setelah commit: kitchen/POS tidak boleh melihat
            // "paid" yang belum masuk DB (event lost kalau rollback).
            DB::afterCommit(function () use ($order): void {
                event(new OrderPaymentChanged($order));
            });
        });
    }
}
