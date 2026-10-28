<?php

namespace App\Modules\Kitchen\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\ConsumeOrderIngredients;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Events\OrderStatusChanged;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderStatusEvent;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;

class TransitionOrderStatus
{
    public function __construct(
        private readonly ConsumeOrderIngredients $consumeOrderIngredients,
    ) {}

    public function __invoke(Order $order, User $actor, OrderStatus $next): Order
    {
        return DB::transaction(function () use ($order, $actor, $next): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->payment_status !== PaymentStatus::Paid) {
                abort(422, 'Pesanan belum dibayar.');
            }

            $allowed = array_filter(
                $locked->status->allowedNext(),
                fn (OrderStatus $status): bool => $status !== OrderStatus::Cancelled,
            );

            if (! in_array($next, $allowed, true)) {
                abort(422, 'Status pesanan tidak valid.');
            }

            $from = $locked->status;
            $locked->status = $next;

            if ($next === OrderStatus::Accepted && $locked->accepted_at === null) {
                $locked->accepted_at = now();
            }

            $locked->save();

            if ($next === OrderStatus::Preparing && config('inventory.deduct_on', 'preparing') === 'preparing') {
                ($this->consumeOrderIngredients)($locked, $actor);
            }

            OrderStatusEvent::create([
                'order_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $next,
                'actor_id' => $actor->id,
            ]);

            event(new OrderStatusChanged($locked, $from, $next));

            return $locked->refresh()->load(['branch', 'items.modifiers', 'payment']);
        });
    }
}
