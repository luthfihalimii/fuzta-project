<?php

namespace App\Modules\Ordering\Actions;

use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Support\OpeningHours;
use App\Modules\Catalog\Actions\PriceOrderItems;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Ordering\Data\CustomerOrderData;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Models\OrderItemModifier;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Jalur checkout customer (tanpa user/shift). Pola idempotency sama dengan
 * CreateOrder: cek sebelum + dalam transaksi + tangkap unique violation.
 */
class CreateCustomerOrder
{
    public function __construct(
        private readonly PriceOrderItems $priceOrderItems,
        private readonly GenerateOrderNumber $generateOrderNumber,
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(CustomerOrderData $data): Order
    {
        $existing = $this->findExisting($data);

        if ($existing !== null) {
            return $existing;
        }

        $branch = Branch::query()->find($data->branchId);

        if ($branch === null || ! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang tidak aktif.',
            ]);
        }

        if (! OpeningHours::isOpen($branch->opens_at, $branch->closes_at)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang sedang tutup.',
            ]);
        }

        if ($data->items === []) {
            throw ValidationException::withMessages([
                'items' => 'Pesanan harus memiliki minimal satu item.',
            ]);
        }

        try {
            return DB::transaction(function () use ($data, $branch): Order {
                $existing = $this->findExisting($data, lock: true);

                if ($existing !== null) {
                    return $existing;
                }

                $pricedItems = ($this->priceOrderItems)($data->branchId, $data->items);
                $subtotal = array_sum(array_column($pricedItems, 'line_total'));

                $order = Order::create([
                    'order_number' => ($this->generateOrderNumber)($branch),
                    'branch_id' => $data->branchId,
                    'placed_by_id' => null,
                    'shift_id' => null,
                    'channel' => $data->fulfillment === 'delivery' ? OrderChannel::Delivery : OrderChannel::Takeaway,
                    'customer_name' => $data->customerName,
                    'customer_phone' => $data->customerPhone,
                    'note' => $data->note,
                    'status' => OrderStatus::Queued,
                    'payment_status' => PaymentStatus::Unpaid,
                    'subtotal' => $subtotal,
                    'delivery_fee' => $data->deliveryFee,
                    'total' => $subtotal + $data->deliveryFee,
                    'delivery_address' => $data->deliveryAddress,
                    'delivery_area_id' => $data->deliveryAreaId,
                    'courier_name' => $data->courierName,
                    'courier_service' => $data->courierService,
                    'public_token' => (string) Str::uuid(),
                    'idempotency_key' => $data->idempotencyKey,
                ]);

                foreach ($pricedItems as $line) {
                    $item = $order->items()->create(Arr::only($line, [
                        'product_id',
                        'product_name',
                        'variant_id',
                        'variant_name',
                        'quantity',
                        'unit_price',
                        'line_total',
                        'note',
                    ]));

                    foreach ($line['modifiers'] as $modifier) {
                        $item->modifiers()->create($modifier);
                    }
                }

                $order->statusEvents()->create([
                    'from_status' => null,
                    'to_status' => OrderStatus::Queued,
                    'actor_id' => null,
                ]);

                ($this->writeAuditLog)(null, 'order.created', $order, [], $this->snapshot($order));

                return $order->load(['items.modifiers', 'statusEvents']);
            });
        } catch (QueryException $exception) {
            if ($this->isIdempotencyViolation($exception)) {
                $existing = $this->findExisting($data);

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }

    /**
     * Cari order berdasarkan kunci idempoten per cabang (dipakai controller
     * untuk replay cepat sebelum harga/quote; tetap dipanggil ulang di
     * dalam transaksi sebagai pertahanan kedua).
     */
    public function findExistingByKey(int $branchId, string $idempotencyKey, bool $lock = false): ?Order
    {
        $query = Order::query()
            ->where('branch_id', $branchId)
            ->where('idempotency_key', $idempotencyKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function findExisting(CustomerOrderData $data, bool $lock = false): ?Order
    {
        return $this->findExistingByKey($data->branchId, $data->idempotencyKey, $lock);
    }

    private function isIdempotencyViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505'
            && str_contains($exception->getMessage(), 'orders_branch_id_idempotency_key_unique');
    }

    /**
     * Snapshot audit: tanpa phone/alamat/token (PII dikecualikan).
     *
     * @return array<string, mixed>
     */
    private function snapshot(Order $order): array
    {
        $order->loadMissing(['items.modifiers']);

        return [
            'order_number' => $order->order_number,
            'branch_id' => $order->branch_id,
            'channel' => $order->channel->value,
            'customer_name' => $order->customer_name,
            'note' => $order->note,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'subtotal' => $order->subtotal,
            'delivery_fee' => $order->delivery_fee,
            'total' => $order->total,
            'items' => $order->items
                ->map(fn (OrderItem $item) => [
                    'product_name' => $item->product_name,
                    'variant_name' => $item->variant_name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'note' => $item->note,
                    'modifiers' => $item->modifiers
                        ->map(fn (OrderItemModifier $modifier) => [
                            'modifier_name' => $modifier->modifier_name,
                            'price_delta' => $modifier->price_delta,
                        ])
                        ->all(),
                ])
                ->all(),
        ];
    }
}
