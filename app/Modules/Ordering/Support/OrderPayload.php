<?php

namespace App\Modules\Ordering\Support;

use App\Modules\Branches\Support\BranchPresenter;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Models\OrderItemModifier;
use App\Modules\Payments\Models\Payment;

class OrderPayload
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Order $order): array
    {
        $order->loadMissing(['branch', 'items.modifiers', 'payment']);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'branch_id' => $order->branch_id,
            'branch' => $order->branch ? BranchPresenter::summary($order->branch) : null,
            'channel' => $order->channel->value,
            'table_number' => $order->table_number,
            'customer_name' => $order->customer_name,
            'note' => $order->note,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'subtotal' => $order->subtotal,
            'total' => $order->total,
            'created_at' => $order->created_at?->toIso8601String(),
            'accepted_at' => $order->accepted_at?->toIso8601String(),
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
            'payment' => $this->payment($order->payment),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payment(?Payment $payment): ?array
    {
        if ($payment === null) {
            return null;
        }

        return [
            'id' => $payment->id,
            'method' => $payment->method->value,
            'amount' => $payment->amount,
            'cash_received' => $payment->cash_received,
            'change_amount' => $payment->change_amount,
            'status' => $payment->status->value,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'voided_at' => $payment->voided_at?->toIso8601String(),
        ];
    }
}
