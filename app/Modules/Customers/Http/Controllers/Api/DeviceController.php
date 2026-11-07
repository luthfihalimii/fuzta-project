<?php

namespace App\Modules\Customers\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerDeviceRequest;
use App\Modules\Customers\Actions\RegisterCustomerDevice;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerDevicePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeviceController extends Controller
{
    public function __construct(
        private readonly CustomerDevicePayload $payload,
    ) {}

    public function store(StoreCustomerDeviceRequest $request, RegisterCustomerDevice $register): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        $device = $register(
            $customer,
            (string) $request->validated('token'),
            (string) $request->validated('platform'),
        );

        return response()->json([
            'device' => ($this->payload)($device),
        ]);
    }

    public function destroy(Request $request, int $device): Response
    {
        $customer = $this->requireCustomer($request);

        // findOrFail via relasi: id milik customer lain tak pernah terlihat (404).
        $customer->devices()->findOrFail($device)->delete();

        return response()->noContent();
    }

    /**
     * Middleware customer.auth selalu memasang customer ini; cek ini
     * defensif (pola sama dengan AddressController).
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
