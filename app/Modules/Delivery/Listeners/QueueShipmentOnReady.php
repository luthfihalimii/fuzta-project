<?php

namespace App\Modules\Delivery\Listeners;

use App\Modules\Delivery\Jobs\CreateShipmentJob;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Events\OrderStatusChanged;
use App\Modules\Payments\Enums\PaymentStatus;

/**
 * Order delivery yang sudah paid dan baru ditandai dapur `Ready`
 * → antrekan pembuatan shipment Biteship (database queue). `afterCommit`
 * agar job tidak dikirim sebelum status ready masuk DB.
 */
class QueueShipmentOnReady
{
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;

        if ($event->to !== OrderStatus::Ready
            || $order->channel !== OrderChannel::Delivery
            || $order->payment_status !== PaymentStatus::Paid
            || $order->delivery_status !== null) {
            return;
        }

        CreateShipmentJob::dispatch($order->id)->afterCommit();
    }
}
