<?php

namespace App\Modules\Delivery\Actions;

use App\Modules\Kitchen\Actions\TransitionOrderStatus;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Proses payload webhook Biteship (event order.status / order.waybill_id /
 * order.price; semua membawa `order_id` + opsional `status`/`courier_waybill_id`).
 *
 * Idempotent: update kolom apa adanya; `delivered` hanya transisi sekali
 * (guard status order). Unknown `biteship_order_id` → 404 (Biteship berhenti
 * retry pada 4xx). Order tetap transitioned lewat state machine eksisting
 * (tanpa konsumsi bahan — konsumsi hanya di `Preparing`).
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

        DB::transaction(function () use ($biteshipOrderId, $payload): void {
            $order = Order::query()
                ->where('biteship_order_id', $biteshipOrderId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                abort(404, 'Order Biteship tidak ditemukan.');
            }

            $status = (string) ($payload['status'] ?? '');

            if ($status !== '') {
                $order->forceFill(['delivery_status' => $status])->save();
            }

            // Tracking link dibangun dari waybill id (lihat plan Task 5).
            $waybill = $payload['courier_waybill_id'] ?? null;

            if (is_string($waybill) && $waybill !== '') {
                $order->forceFill(['tracking_url' => "https://track.biteship.com/{$waybill}"])->save();
            }

            if ($status === 'delivered'
                && $order->status !== OrderStatus::Completed
                && $order->status !== OrderStatus::Cancelled) {
                // Aktor null = transisi sistem (baris event actor_id null).
                ($this->transition)($order, null, OrderStatus::Completed);
            }
        });
    }
}
