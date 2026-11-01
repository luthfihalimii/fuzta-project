<?php

namespace App\Modules\Ordering\Support;

use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Models\OrderItemModifier;

/**
 * Payload aman untuk customer (API / respons). Sengaja tanpa
 * placed_by_id, shift_id, idempotency_key, atau biteship_order_id.
 */
class CustomerOrderPayload
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Order $order): array
    {
        $order->loadMissing(['items.modifiers']);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'branch_id' => $order->branch_id,
            'token' => $order->public_token,
            'channel' => $order->channel->value,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'subtotal' => $order->subtotal,
            'delivery_fee' => $order->delivery_fee,
            'total' => $order->total,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'note' => $order->note,
            'delivery_address' => $order->delivery_address,
            'courier_name' => $order->courier_name,
            'courier_service' => $order->courier_service,
            'delivery_status' => $order->delivery_status,
            'tracking_url' => $order->tracking_url,
            'created_at' => $order->created_at?->toIso8601String(),
            'items' => $order->items
                ->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'variant_name' => $item->variant_name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'note' => $item->note,
                    'modifiers' => $item->modifiers
                        ->map(fn (OrderItemModifier $modifier) => [
                            'modifier_name' => $modifier->modifier_name,
                            'price_delta' => $modifier->price_delta,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }
}
