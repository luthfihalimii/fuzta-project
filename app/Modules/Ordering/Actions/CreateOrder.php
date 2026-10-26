<?php

namespace App\Modules\Ordering\Actions;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Actions\PriceOrderItems;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Ordering\Data\CreateOrderData;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Models\OrderItemModifier;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOrder
{
    public function __construct(
        private readonly PriceOrderItems $priceOrderItems,
        private readonly GenerateOrderNumber $generateOrderNumber,
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(User $actor, CreateOrderData $data): Order
    {
        if (! $actor->hasBranchAccess($data->branchId)) {
            throw new AuthorizationException('Anda tidak dapat membuat pesanan untuk cabang lain.');
        }

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

        if ($data->items === []) {
            throw ValidationException::withMessages([
                'items' => 'Pesanan harus memiliki minimal satu item.',
            ]);
        }

        try {
            return DB::transaction(function () use ($actor, $data, $branch): Order {
                $existing = $this->findExisting($data, lock: true);

                if ($existing !== null) {
                    return $existing;
                }

                $pricedItems = ($this->priceOrderItems)($data->branchId, $data->items);
                $subtotal = array_sum(array_column($pricedItems, 'line_total'));

                $order = Order::create([
                    'order_number' => ($this->generateOrderNumber)($branch),
                    'branch_id' => $data->branchId,
                    'placed_by_id' => $actor->id,
                    'channel' => $data->channel,
                    'table_number' => $data->tableNumber,
                    'customer_name' => $data->customerName,
                    'note' => $data->note,
                    'status' => OrderStatus::Queued,
                    'payment_status' => PaymentStatus::Unpaid,
                    'subtotal' => $subtotal,
                    'total' => $subtotal,
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
                    'actor_id' => $actor->id,
                ]);

                ($this->writeAuditLog)(
                    $actor,
                    'order.created',
                    $order,
                    [],
                    $this->snapshot($order),
                );

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

    private function findExisting(CreateOrderData $data, bool $lock = false): ?Order
    {
        $query = Order::query()
            ->where('branch_id', $data->branchId)
            ->where('idempotency_key', $data->idempotencyKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function isIdempotencyViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505'
            && str_contains($exception->getMessage(), 'orders_branch_id_idempotency_key_unique');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Order $order): array
    {
        $order->loadMissing(['items.modifiers']);

        return [
            'order_number' => $order->order_number,
            'branch_id' => $order->branch_id,
            'placed_by_id' => $order->placed_by_id,
            'channel' => $order->channel->value,
            'table_number' => $order->table_number,
            'customer_name' => $order->customer_name,
            'note' => $order->note,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'subtotal' => $order->subtotal,
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
