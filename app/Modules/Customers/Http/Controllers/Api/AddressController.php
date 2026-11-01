<?php

namespace App\Modules\Customers\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerAddressRequest;
use App\Http\Requests\UpdateCustomerAddressRequest;
use App\Modules\Customers\Actions\CreateCustomerAddress;
use App\Modules\Customers\Actions\DeleteCustomerAddress;
use App\Modules\Customers\Actions\UpdateCustomerAddress;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Support\CustomerAddressPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AddressController extends Controller
{
    public function __construct(
        private readonly CustomerAddressPayload $addressPayload,
    ) {}

    public function index(Request $request, CustomerAddressPayload $payload): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        return response()->json([
            'addresses' => $customer->addresses()
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (CustomerAddress $address): array => $payload($address))
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreCustomerAddressRequest $request, CreateCustomerAddress $create): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        $address = $create($customer, $request->validated());

        return response()->json($this->wrap($address), 201);
    }

    public function update(UpdateCustomerAddressRequest $request, int $address, UpdateCustomerAddress $update): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        // findOrFail via relasi: id milik customer lain tak pernah terlihat (404).
        $owned = $customer->addresses()->findOrFail($address);

        return response()->json($this->wrap($update($customer, $owned, $request->validated())));
    }

    public function destroy(Request $request, int $address, DeleteCustomerAddress $delete): Response
    {
        $customer = $this->requireCustomer($request);

        // findOrFail via relasi: id milik customer lain tak pernah terlihat (404).
        $owned = $customer->addresses()->findOrFail($address);

        $delete($customer, $owned);

        return response()->noContent();
    }

    /**
     * @return array{address: array<string, mixed>}
     */
    private function wrap(CustomerAddress $address): array
    {
        return [
            'address' => ($this->addressPayload)($address),
        ];
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
