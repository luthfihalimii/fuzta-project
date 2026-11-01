<?php

namespace App\Modules\Ordering\Support;

use App\Modules\Ordering\Models\Order;

/**
 * Ringkasan riwayat untuk /me/orders: ringkas dan aman.
 * Sengaja tanpa nama/HP/alamat customer, items, dan id internal.
 */
class CustomerOrderSummaryPayload
{
    /**
     * @return array{order_number: string, token: string, created_at: ?string, channel: string, status: string, payment_status: string, delivery_status: mixed, total: int}
     */
    public function __invoke(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'token' => $order->public_token,
            'created_at' => $order->created_at?->toIso8601String(),
            'channel' => $order->channel->value,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'delivery_status' => $order->delivery_status,
            'total' => $order->total,
        ];
    }
}
