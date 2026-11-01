<?php

namespace App\Modules\Ordering\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteCustomerOrderRequest;
use App\Http\Requests\StoreCustomerOrderRequest;
use App\Modules\Catalog\Actions\PriceOrderItems;
use App\Modules\Delivery\Actions\QuoteDelivery;
use App\Modules\Ordering\Actions\CreateCustomerOrder;
use App\Modules\Ordering\Data\CustomerOrderData;
use App\Modules\Ordering\Support\CustomerOrderPayload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly PriceOrderItems $priceOrderItems,
        private readonly QuoteDelivery $quoteDelivery,
        private readonly CreateCustomerOrder $createCustomerOrder,
        private readonly CustomerOrderPayload $payload,
    ) {}

    public function quote(QuoteCustomerOrderRequest $request): JsonResponse
    {
        $branchId = (int) $request->validated('branch_id');
        $items = $request->validated('items');
        $priced = ($this->priceOrderItems)($branchId, $items);

        try {
            $options = ($this->quoteDelivery)($branchId, $request->validated('destination_area_id'), $priced);
        } catch (ConnectionException|RuntimeException) {
            return response()->json(['message' => 'Layanan pengiriman sedang tidak tersedia.'], 502);
        }

        return response()->json(['options' => $options]);
    }

    public function store(StoreCustomerOrderRequest $request): JsonResponse
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Header Idempotency-Key wajib diisi.',
            ]);
        }

        $branchId = (int) $request->validated('branch_id');
        $items = $request->validated('items');
        $fulfillment = (string) $request->validated('fulfillment');

        // Harga dihitung ulang server; fee ongkir client (kalau dikirim) diabaikan total.
        $priced = ($this->priceOrderItems)($branchId, $items);

        $deliveryFee = 0;
        $courierName = null;
        $courierService = null;

        if ($fulfillment === 'delivery') {
            try {
                $options = ($this->quoteDelivery)($branchId, (string) $request->validated('delivery_area_id'), $priced);
            } catch (ConnectionException|RuntimeException) {
                return response()->json(['message' => 'Layanan pengiriman sedang tidak tersedia.'], 502);
            }

            $chosen = null;

            foreach ($options as $option) {
                if ($option['courier_name'] === $request->validated('courier_name')
                    && $option['courier_service'] === $request->validated('courier_service')) {
                    $chosen = $option;

                    break;
                }
            }

            if ($chosen === null) {
                throw ValidationException::withMessages([
                    'courier_name' => 'Kurir atau layanan tidak tersedia untuk alamat ini.',
                ]);
            }

            $deliveryFee = $chosen['fee'];
            $courierName = $chosen['courier_name'];
            $courierService = $chosen['courier_service'];
        }

        $order = ($this->createCustomerOrder)(new CustomerOrderData(
            branchId: $branchId,
            fulfillment: $fulfillment,
            customerName: (string) $request->validated('customer_name'),
            customerPhone: (string) $request->validated('customer_phone'),
            note: $request->validated('note'),
            deliveryAddress: $fulfillment === 'delivery' ? (string) $request->validated('delivery_address') : null,
            deliveryAreaId: $fulfillment === 'delivery' ? (string) $request->validated('delivery_area_id') : null,
            deliveryFee: $deliveryFee,
            courierName: $courierName,
            courierService: $courierService,
            items: $items,
            idempotencyKey: $idempotencyKey,
        ));

        return response()->json(
            ['order' => ($this->payload)($order), 'token' => $order->public_token],
            $order->wasRecentlyCreated ? 201 : 200,
        );
    }
}
