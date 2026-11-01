<?php

namespace App\Modules\Payments\Actions;

use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Clients\DokuClient;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Pastikan order customer punya payment DOKU pending. Satu payment per
 * order (unyk `order_id`):
 * - pending + masih berlaku → kembalikan apa adanya (tanpa invoice baru);
 * - paid/voided → kembalikan apa adanya (jangan bikin invoice untuk order
 *   yang sudah selesai/mati);
 * - belum ada, ATAU pending tapi invoice kadaluarsa → invoice baru
 *   dijatuhkan ke baris yang sama (re-invoice, tetap satu payment).
 *
 * Panggilan HTTP ke DOKU sengaja di luar transaksi DB: lock order tidak
 * boleh ditahan selama network round-trip.
 */
class CreateDokuPayment
{
    public function __construct(
        private readonly DokuClient $doku,
    ) {}

    public function __invoke(Order $order): ?Payment
    {
        $payment = $order->payment()->first();

        // Order mati (dibatalkan/expired scheduler): jangan pernah bikin invoice baru.
        if ($order->status === OrderStatus::Cancelled) {
            return $payment;
        }

        // pending + masih berlaku, atau paid/voided → kembalikan apa adanya.
        if ($payment !== null && ! $this->needsNewInvoice($payment)) {
            return $payment;
        }

        $invoice = $this->doku->createPayment($order);

        return $this->persist($order, $invoice);
    }

    private function needsNewInvoice(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Pending
            && ($payment->payment_url === null || $payment->expires_at === null || $payment->expires_at->isPast());
    }

    /**
     * @param  array{payment_url: string, invoice_number: string, expires_at: CarbonImmutable}  $invoice
     */
    private function persist(Order $order, array $invoice): Payment
    {
        try {
            return Payment::query()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'shift_id' => null,
                    'method' => PaymentMethod::DokuQris,
                    'amount' => $order->total,
                    'cash_received' => null,
                    'change_amount' => null,
                    'status' => PaymentStatus::Pending,
                    'paid_at' => null,
                    'voided_at' => null,
                    'provider' => 'doku',
                    'provider_reference' => $invoice['invoice_number'],
                    'payment_url' => $invoice['payment_url'],
                    'expires_at' => $invoice['expires_at'],
                ],
            );
        } catch (QueryException $exception) {
            // Dua checkout konkuren dengan key sama: satu INSERT menang,
            // yang lain menabrak unique order_id → kembalikan baris pemenang.
            if (($exception->errorInfo[0] ?? null) === '23505') {
                return Payment::query()->where('order_id', $order->id)->firstOrFail();
            }

            throw $exception;
        }
    }
}
