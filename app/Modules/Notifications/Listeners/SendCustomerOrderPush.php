<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Jobs\SendOrderStatusPush;
use App\Modules\Notifications\Support\FcmClient;
use App\Modules\Ordering\Events\OrderStatusChanged;

class SendCustomerOrderPush
{
    public function __construct(
        private readonly FcmClient $fcm,
    ) {}

    public function handle(OrderStatusChanged $event): void
    {
        if (! $this->fcm->configured()) {
            return;
        }

        if ($event->order->customer_id === null) {
            return;
        }

        if (! in_array($event->to->value, config('notifications.order_push_statuses', []), true)) {
            return;
        }

        SendOrderStatusPush::dispatch($event->order->id, $event->to->value);
    }
}
