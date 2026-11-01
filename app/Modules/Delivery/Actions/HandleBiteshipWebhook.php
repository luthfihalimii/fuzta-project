<?php

namespace App\Modules\Delivery\Actions;

use App\Modules\Kitchen\Actions\TransitionOrderStatus;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Proses payload webhook Biteship (event order.status / order.waybill_id /
 * order.price; semua membawa `order_id` + opsional `status`/`courier_waybill_id`).
 *
 * `delivery_status`/`tracking_url` di-commit di transaksi sendiri; baru
 * setelah commit dicoba transisi `delivered → Completed` (hanya bila graf
 * status mengizinkan; penolakan dilog, bukan gagal webhook — status kurir
 * tidak boleh hilang hanya karena transisi ditolak). Idempotent; unknown
 * `biteship_order_id` → 404 (Biteship berhenti retry pada 4xx). Order tetap
 * transitioned lewat state machine eksisting (tanpa konsumsi bahan —
 * konsumsi hanya di `Preparing`).
 */
class HandleBiteshipWebhook
{
    public function __construct(
        private readonly TransitionOrderStatus $transition,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(array $payload): void
    {
        $biteshipOrderId = (string) ($payload['order_id'] ?? '');

        if ($biteshipOrderId === '') {
            abort(422, 'Payload webhook tidak valid.');
        }

        $order = null;
        $storedStatus = '';

        DB::transaction(function () use ($biteshipOrderId, $payload, &$order, &$storedStatus): void {
            $order = Order::query()
                ->where('biteship_order_id', $biteshipOrderId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                abort(404, 'Order Biteship tidak ditemukan.');
            }

            $raw = (string) ($payload['status'] ?? '');

            if ($raw !== '') {
                $storedStatus = $this->normalizeStatus($raw, (int) $order->id);
                $order->forceFill(['delivery_status' => $storedStatus])->save();
            }

            // Tracking link dibangun dari waybill id (lihat plan Task 5).
            $waybill = $payload['courier_waybill_id'] ?? null;

            if (is_string($waybill) && $waybill !== '') {
                $order->forceFill(['tracking_url' => "https://track.biteship.com/{$waybill}"])->save();
            }
        });

        if ($storedStatus !== 'delivered' || $order === null) {
            return;
        }

        // Transisi hanya bila graf status mengizinkan; penolakan (mis.
        // belum paid) dilog tanpa PII — webhook tetap sukses karena status
        // kurir sudah ter-commit di atas.
        if (! in_array(OrderStatus::Completed, $order->status->allowedNext(), true)) {
            return;
        }

        try {
            ($this->transition)($order, null, OrderStatus::Completed);
        } catch (Throwable $e) {
            Log::warning('Webhook Biteship: transisi delivered → completed ditolak (status kurir tetap tersimpan).', [
                'order_id' => (int) $order->id,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Kolom `delivery_status` = string(32): terima `[a-z_]{1,32}`, selain itu
     * `unknown` (log order_id + panjang mentah saja, tidak log isinya).
     */
    private function normalizeStatus(string $status, int $orderId): string
    {
        if (preg_match('/^[a-z_]{1,32}$/', $status) !== 1) {
            Log::warning('Webhook Biteship: status tidak dikenal, disimpan sebagai unknown.', [
                'order_id' => $orderId,
                'raw_length' => mb_strlen($status),
            ]);

            return 'unknown';
        }

        return $status;
    }
}
