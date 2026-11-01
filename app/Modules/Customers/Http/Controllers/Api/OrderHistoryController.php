<?php

namespace App\Modules\Customers\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Ordering\Support\CustomerOrderSummaryPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderHistoryController extends Controller
{
    public function __invoke(Request $request, CustomerOrderSummaryPayload $payload): JsonResponse
    {
        $customer = $request->attributes->get('customer');

        // Route digarap customer.auth; cek ini defensif.
        if (! $customer instanceof Customer) {
            abort(401, 'Silakan masuk terlebih dahulu.');
        }

        $orders = $customer->orders()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json([
            'orders' => array_map(
                fn ($order) => $payload($order),
                $orders->items(),
            ),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }
}
