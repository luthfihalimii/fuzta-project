<?php

namespace App\Modules\Ordering\Events;

use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('kitchen.'.$this->order->branch_id),
            new PrivateChannel('pos.'.$this->order->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order-status-changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'kind' => 'status',
            'order_number' => $this->order->order_number,
            'branch_id' => $this->order->branch_id,
            'from' => $this->from->value,
            'to' => $this->to->value,
            'created_at' => $this->order->created_at?->toIso8601String(),
            'accepted_at' => $this->order->accepted_at?->toIso8601String(),
        ];
    }
}
