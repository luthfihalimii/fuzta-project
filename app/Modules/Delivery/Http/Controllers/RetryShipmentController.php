<?php

namespace App\Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Delivery\Jobs\CreateShipmentJob;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Ordering\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Retry manual pengiriman Biteship yang gagal (`delivery_status = failed`).
 * Hanya owner (rute di grup role:owner). Retry hanya dibuat antrekan —
 * keberhasilan tetap saja via job + webhook.
 */
class RetryShipmentController extends Controller
{
    public function __construct(
        private readonly WriteAuditLog $audit,
    ) {}

    public function retry(Request $request, Order $order): JsonResponse
    {
        if ($order->delivery_status !== 'failed') {
            throw ValidationException::withMessages([
                'order' => 'Hanya pesanan dengan pengiriman gagal yang bisa di-retry.',
            ]);
        }

        CreateShipmentJob::dispatch($order->id);

        ($this->audit)(
            $request->user(),
            'order.delivery_retried',
            $order,
            ['delivery_status' => $order->delivery_status],
            ['biteship_order_id' => $order->biteship_order_id],
        );

        return response()->json(['message' => 'Retry pengiriman dibuat antrekan.']);
    }
}
