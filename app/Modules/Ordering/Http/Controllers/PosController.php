<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Modules\Ordering\Actions\CreateOrder;
use App\Modules\Ordering\Data\CreateOrderData;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Models\OrderItemModifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('pos/Index');
    }

    public function store(StoreOrderRequest $request, CreateOrder $createOrder): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $branchId = $user->isOwner()
            ? (int) $validated['branch_id']
            : (int) ($validated['branch_id'] ?? $user->branch_id);

        if ($branchId === 0) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang kasir belum diatur.',
            ]);
        }

        $order = $createOrder($user, new CreateOrderData(
            branchId: $branchId,
            channel: OrderChannel::from($validated['channel']),
            tableNumber: $validated['table_number'] ?? null,
            customerName: $validated['customer_name'] ?? null,
            note: $validated['note'] ?? null,
            items: array_values($validated['items']),
            idempotencyKey: $validated['idempotency_key'],
        ));

        return response()->json(
            ['order' => $this->payload($order)],
            $order->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Order $order): array
    {
        $order->loadMissing(['items.modifiers']);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'branch_id' => $order->branch_id,
            'channel' => $order->channel->value,
            'table_number' => $order->table_number,
            'customer_name' => $order->customer_name,
            'note' => $order->note,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'subtotal' => $order->subtotal,
            'total' => $order->total,
            'created_at' => $order->created_at?->toIso8601String(),
            'items' => $order->items
                ->map(fn (OrderItem $item) => [
                    'id' => $item->id,
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
