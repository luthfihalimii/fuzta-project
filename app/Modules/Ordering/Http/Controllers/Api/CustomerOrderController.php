<?php

namespace App\Modules\Ordering\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Support\CustomerOrderPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class CustomerOrderController extends Controller
{
    public function __construct(
        private readonly CustomerOrderPayload $payload,
    ) {}

    public function show(string $token): JsonResponse
    {
        // Token dibuat sebagai uuid (str::uuid); kolom DB bertipe uuid,
        // jadi string liar divalidasi dulu agar 404, bukan error cast DB.
        if (! Str::isUuid($token)) {
            abort(404);
        }

        // Tanpa implicit route binding: token opaque, pencarian eksplisit.
        $order = Order::query()
            ->where('public_token', $token)
            ->firstOrFail();

        return response()->json(['order' => ($this->payload)($order)]);
    }
}
