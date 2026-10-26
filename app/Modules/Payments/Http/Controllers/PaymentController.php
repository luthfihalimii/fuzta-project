<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Support\OrderPayload;
use App\Modules\Payments\Actions\MarkOrderPaid;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly OrderPayload $payload,
    ) {}

    public function store(
        StorePaymentRequest $request,
        Order $order,
        MarkOrderPaid $markOrderPaid,
    ): JsonResponse|RedirectResponse {
        if ($order->payment_status === PaymentStatus::Paid) {
            $this->authorize('view', $order);

            return $this->respond($request, $order->refresh(), 'Pesanan sudah dibayar.');
        }

        $this->authorize('pay', $order);

        $validated = $request->validated();
        $cashReceived = isset($validated['cash_received'])
            ? (int) $validated['cash_received']
            : null;

        ($markOrderPaid)(
            $order,
            $request->user(),
            PaymentMethod::from($validated['method']),
            $cashReceived,
        );

        return $this->respond($request, $order->refresh(), 'Pembayaran berhasil.');
    }

    private function respond(
        Request $request,
        Order $order,
        string $message,
    ): JsonResponse|RedirectResponse {
        if ($this->wantsJson($request)) {
            return response()->json([
                'order' => ($this->payload)($order),
            ]);
        }

        return back()->with('success', $message);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }
}
