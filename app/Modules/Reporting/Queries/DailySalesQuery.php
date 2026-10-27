<?php

namespace App\Modules\Reporting\Queries;

use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DailySalesQuery
{
    /**
     * @return array{
     *     order_count: int,
     *     gross_sales: int,
     *     average_order_value: int,
     *     by_payment_method: list<array{method: string, label: string, order_count: int, amount: int}>
     * }
     */
    public function __invoke(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $branchId = null,
    ): array {
        $fromUtc = $from->utc();
        $toUtc = $to->utc();

        $summary = DB::table('orders')
            ->where('payment_status', PaymentStatus::Paid->value)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total), 0) as gross_sales')
            ->first();

        $orderCount = (int) $summary->order_count;
        $grossSales = (int) $summary->gross_sales;

        $byPaymentMethod = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.status', PaymentStatus::Paid->value)
            ->where('orders.payment_status', PaymentStatus::Paid->value)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc])
            ->when($branchId !== null, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->groupBy('payments.method')
            ->orderBy('payments.method')
            ->selectRaw('payments.method, COUNT(*) as order_count, COALESCE(SUM(payments.amount), 0) as amount')
            ->get()
            ->map(fn (object $row) => [
                'method' => (string) $row->method,
                'label' => PaymentMethod::tryFrom($row->method)?->label() ?? $row->method,
                'order_count' => (int) $row->order_count,
                'amount' => (int) $row->amount,
            ])
            ->values()
            ->all();

        return [
            'order_count' => $orderCount,
            'gross_sales' => $grossSales,
            'average_order_value' => $orderCount > 0 ? intdiv($grossSales, $orderCount) : 0,
            'by_payment_method' => $byPaymentMethod,
        ];
    }
}
