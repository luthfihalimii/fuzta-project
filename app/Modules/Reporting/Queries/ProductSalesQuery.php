<?php

namespace App\Modules\Reporting\Queries;

use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProductSalesQuery
{
    /**
     * @return array{
     *     products: list<array{product_id: int, product_name: string, quantity: int, line_total: int}>,
     *     modifiers: list<array{modifier_name: string, quantity: int, revenue: int}>
     * }
     */
    public function __invoke(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $branchId = null,
    ): array {
        $fromUtc = $from->utc();
        $toUtc = $to->utc();

        $products = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.payment_status', PaymentStatus::Paid->value)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc])
            ->when($branchId !== null, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByRaw('SUM(order_items.quantity) DESC')
            ->selectRaw('order_items.product_id, order_items.product_name, SUM(order_items.quantity) as quantity, SUM(order_items.line_total) as line_total')
            ->get()
            ->map(fn (object $row) => [
                'product_id' => (int) $row->product_id,
                'product_name' => (string) $row->product_name,
                'quantity' => (int) $row->quantity,
                'line_total' => (int) $row->line_total,
            ])
            ->values()
            ->all();

        $modifiers = DB::table('order_item_modifiers')
            ->join('order_items', 'order_items.id', '=', 'order_item_modifiers.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.payment_status', PaymentStatus::Paid->value)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc])
            ->when($branchId !== null, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->groupBy('order_item_modifiers.modifier_name')
            ->orderByRaw('SUM(order_items.quantity) DESC')
            ->selectRaw('order_item_modifiers.modifier_name, SUM(order_items.quantity) as quantity, SUM(order_item_modifiers.price_delta * order_items.quantity) as revenue')
            ->get()
            ->map(fn (object $row) => [
                'modifier_name' => (string) $row->modifier_name,
                'quantity' => (int) $row->quantity,
                'revenue' => (int) $row->revenue,
            ])
            ->values()
            ->all();

        return [
            'products' => $products,
            'modifiers' => $modifiers,
        ];
    }
}
