<?php

namespace App\Console\Commands;

use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Events\OrderStatusChanged;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Batalkan order customer (placed_by_id null) yang belum dibayar dan sudah
 * lewat batas waktu pembayaran:
 * - ada payment row → payments.expires_at < now;
 * - tanpa payment row (mis. invoice gagal dibuat) → created_at < now - due_minutes.
 *
 * Order yang sudah paid tidak pernah disentuh. Di dalam transaksi: lock
 * order dulu, lalu payment, dan verifikasi ulang status setelah lock.
 * shortcut: SettleDokuPayment mengunci kebalikannya (payment → order);
 * aman sekarang karena keduanya verifikasi ulang setelah lock,
 * satukan urutan lock bila ada laporan deadlock.
 */
class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Batalkan order customer belum dibayar yang lewat batas waktu pembayaran online';

    /**
     * @param  CarbonImmutable  $expiredBefore
     */
    public function handle(WriteAuditLog $writeAuditLog): int
    {
        $dueMinutes = (int) config('services.doku.payment_due_minutes', 30);
        $expiredBefore = now()->subMinutes($dueMinutes);

        $candidates = Order::query()
            ->whereNull('placed_by_id')
            ->where('payment_status', PaymentStatus::Unpaid)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->where(function (Builder $query) use ($expiredBefore): void {
                $query->whereHas('payment', fn (Builder $payment): Builder => $payment
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now()))
                    ->orWhere(function (Builder $query) use ($expiredBefore): void {
                        $query->whereDoesntHave('payment')->where('created_at', '<', $expiredBefore);
                    });
            })
            ->pluck('id');

        $expired = 0;

        foreach ($candidates as $orderId) {
            $expired += $this->expireOne((int) $orderId, $expiredBefore, $writeAuditLog);
        }

        $this->info("Dibatalkan {$expired} pesanan kadaluarsa.");

        return self::SUCCESS;
    }

    /**
     * @return 0|1
     */
    private function expireOne(
        int $orderId,
        CarbonImmutable $expiredBefore,
        WriteAuditLog $writeAuditLog,
    ): int {
        return DB::transaction(function () use ($orderId, $expiredBefore, $writeAuditLog): int {
            $locked = Order::query()
                ->whereKey($orderId)
                ->lockForUpdate()
                ->first();

            if ($locked === null
                || $locked->placed_by_id !== null
                || $locked->payment_status !== PaymentStatus::Unpaid
                || $locked->status === OrderStatus::Cancelled) {
                return 0; // webhook/POS bisa saja melunasi di antara scan dan lock.
            }

            $payment = $locked->payment()->lockForUpdate()->first();

            $pastDue = $payment !== null
                ? ($payment->expires_at !== null && $payment->expires_at->isPast())
                : $locked->created_at->isBefore($expiredBefore);

            if (! $pastDue) {
                return 0;
            }

            $from = $locked->status;

            $locked->forceFill(['status' => OrderStatus::Cancelled])->save();

            $locked->statusEvents()->create([
                'from_status' => $from,
                'to_status' => OrderStatus::Cancelled,
                'actor_id' => null,
                'note' => 'Pembayaran online kadaluarsa.',
            ]);

            if ($payment !== null && $payment->status === PaymentStatus::Pending && $payment->provider !== null) {
                $payment->forceFill([
                    'status' => PaymentStatus::Voided,
                    'voided_at' => now(),
                ])->save();
            }

            $writeAuditLog(
                null,
                'order.expired',
                $locked,
                [
                    'status' => $from->value,
                    'payment_status' => PaymentStatus::Unpaid->value,
                ],
                [
                    'status' => OrderStatus::Cancelled->value,
                    'payment_status' => PaymentStatus::Unpaid->value,
                ],
            );

            DB::afterCommit(function () use ($locked, $from): void {
                event(new OrderStatusChanged($locked, $from, OrderStatus::Cancelled));
            });

            return 1;
        });
    }
}
