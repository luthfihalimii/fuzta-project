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
use App\Modules\Payments\Actions\CreateDokuPayment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly PriceOrderItems $priceOrderItems,
        private readonly QuoteDelivery $quoteDelivery,
        private readonly CreateCustomerOrder $createCustomerOrder,
        private readonly CustomerOrderPayload $payload,
        private readonly CreateDokuPayment $createDokuPayment,
    ) {}

    public function quote(QuoteCustomerOrderRequest $request): JsonResponse
    {
        $branchId = (int) $request->validated('branch_id');
        $items = $request->validated('items');
        $priced = ($this->priceOrderItems)($branchId, $items);

        // Pickup tidak pernah memanggil Biteship (ongkir 0), area tujuan diabaikan.
        $destinationAreaId = $request->validated('fulfillment') === 'delivery'
            ? (string) $request->validated('destination_area_id')
            : null;

        $options = $this->quoteFor($branchId, $destinationAreaId, $priced);

        if ($options instanceof JsonResponse) {
            return $options; // 502: Biteship tidak bisa dihubungi.
        }

        return response()->json(['options' => $options]);
    }

    /**
     * @param  list<array{product_id: int, product_name: string|null, quantity: int, line_total: int, note: string|null}>  $items  Hasil PriceOrderItems.
     * @return array<int, array{courier_name: string, courier_service: string, fee: int, etd: ?string}>|JsonResponse
     */
    private function quoteFor(int $branchId, ?string $destinationAreaId, array $items): array|JsonResponse
    {
        try {
            return ($this->quoteDelivery)($branchId, $destinationAreaId, $items);
        } catch (InvalidArgumentException) {
            // 4xx dari Biteship (area tidak dikenal) = input customer salah, bukan provider down.
            throw ValidationException::withMessages([
                'delivery_area_id' => 'Alamat pengiriman tidak dikenali.',
            ]);
        } catch (ConnectionException|RuntimeException) {
            return response()->json(['message' => 'Layanan pengiriman sedang tidak tersedia.'], 502);
        }
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

        // Login opsional: middleware customer.auth:optional hanya mengisi
        // atribut ini bila bearer valid; guest tetap checksum tanpa akun.
        // customer_id dari body tidak pernah dipercaya.
        $customerId = $request->attributes->get('customer')?->id;

        // Replay key yang sudah ada: langsung kembalikan order lama — tanpa harga/quote
        // (Biteship down pun tidak boleh menghalangi melihat order yang sudah dibuat).
        // Key milik customer lain → 409 (guard di dalam findExistingByKey).
        $order = $this->createCustomerOrder->findExistingByKey(
            $branchId,
            $idempotencyKey,
            customerId: $customerId,
            customerPhone: (string) $request->validated('customer_phone'),
        );

        if ($order === null) {
            $items = $request->validated('items');
            $fulfillment = (string) $request->validated('fulfillment');

            // Harga dihitung ulang server; fee ongkir client (kalau dikirim) diabaikan total.
            $priced = ($this->priceOrderItems)($branchId, $items);

            $deliveryFee = 0;
            $courierName = null;
            $courierService = null;

            if ($fulfillment === 'delivery') {
                $options = $this->quoteFor($branchId, (string) $request->validated('delivery_area_id'), $priced);

                if ($options instanceof JsonResponse) {
                    return $options; // 502: Biteship tidak bisa dihubungi.
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
                customerId: $customerId,
            ));
        }

        try {
            $payment = ($this->createDokuPayment)($order);
        } catch (RuntimeException) {
            // DOKU down/miskonfigurasi: order tetap aman (unpaid, tanpa payment
            // row palsu). Client retry dengan Idempotency-Key yang sama.
            return response()->json([
                'message' => 'Pembayaran online sedang tidak tersedia. Coba lagi dengan Idempotency-Key yang sama.',
                'token' => $order->public_token,
            ], 502);
        }

        $status = $order->wasRecentlyCreated ? 201 : 200;

        return response()->json([
            'order' => ($this->payload)($order),
            'token' => $order->public_token,
            'payment_url' => $payment?->payment_url,
            'payment_expires_at' => $payment?->expires_at?->toIso8601String(),
        ], $status);
    }
}
