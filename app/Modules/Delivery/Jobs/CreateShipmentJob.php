<?php

namespace App\Modules\Delivery\Jobs;

use App\Modules\Delivery\Actions\CreateBiteshipShipment;
use App\Modules\Ordering\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateShipmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public int $orderId,
    ) {}

    public function handle(CreateBiteshipShipment $createShipment): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null) {
            return;
        }

        // Retry (tries) aman: action idempotent via guard biteship_order_id.
        ($createShipment)($order);
    }
}
