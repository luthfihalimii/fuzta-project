<?php

namespace App\Modules\Customers\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCustomerProfileRequest;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(
        private readonly CustomerPayload $payload,
    ) {}

    public function show(Request $request, CustomerPayload $payload): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        return response()->json([
            'customer' => $payload($customer, withCount: true),
        ]);
    }

    public function update(UpdateCustomerProfileRequest $request): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        // Hanya `name` yang ditulis: field lain tak pernah diterima dari API.
        $customer->update(['name' => (string) $request->validated('name')]);

        return response()->json([
            'customer' => ($this->payload)($customer, withCount: true),
        ]);
    }

    /**
     * Middleware customer.auth selalu memasang customer ini; cek ini
     * defensif (mirip AuthController::logout).
     */
    private function requireCustomer(Request $request): Customer
    {
        $customer = $request->attributes->get('customer');

        if ($customer === null) {
            abort(401, 'Silakan masuk terlebih dahulu.');
        }

        return $customer;
    }
}
