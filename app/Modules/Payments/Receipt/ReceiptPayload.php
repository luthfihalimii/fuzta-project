<?php

namespace App\Modules\Payments\Receipt;

use App\Modules\Branches\Support\BranchPresenter;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Models\OrderItemModifier;

class ReceiptPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function fromOrder(Order $order): array
    {
        $order->loadMissing(['branch', 'placedBy', 'payment', 'items.modifiers']);

        return [
            'branch' => $order->branch ? BranchPresenter::summary($order->branch) : null,
            'order_number' => $order->order_number,
            'datetime' => $order->created_at?->toIso8601String(),
            'channel' => $order->channel->value,
            'table_number' => $order->table_number,
            'items' => $order->items
                ->map(fn (OrderItem $item) => [
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
            'total' => $order->total,
            'payment_status' => $order->payment_status->value,
            'payment_method' => $order->payment?->method->value,
            'cashier' => $order->placedBy?->name,
            'cash_received' => $order->payment?->cash_received,
            'change' => $order->payment?->change_amount,
        ];
    }
}
