<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Support\FcmClient;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOrderStatusPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public int $orderId,
        public string $toStatus,
    ) {}

    public function handle(FcmClient $fcm): void
    {
        if (! $fcm->configured()) {
            return;
        }

        $order = Order::query()->with('customer.devices')->find($this->orderId);

        if ($order === null || $order->customer_id === null || $order->customer === null) {
            return;
        }

        $status = OrderStatus::tryFrom($this->toStatus);

        if ($status === null || ! in_array($status->value, config('notifications.order_push_statuses', []), true)) {
            return;
        }

        [$title, $body] = $this->copy($order, $status);

        $data = [
            'type' => 'order_status',
            'order_token' => (string) $order->public_token,
            'order_number' => $order->order_number,
            'status' => $status->value,
        ];

        foreach ($order->customer->devices as $device) {
            // Token mati (app di-uninstall/ganti akun) dibersihkan di sini.
            if (! $fcm->send($device->token, $title, $body, $data)) {
                $device->delete();
            }
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function copy(Order $order, OrderStatus $status): array
    {
        return match ($status) {
            OrderStatus::Accepted => [
                "Pesanan {$order->order_number} diterima",
                'Pesananmu sedang disiapkan.',
            ],
            OrderStatus::Ready => [
                "Pesanan {$order->order_number} siap",
                'Pesananmu sudah siap. Silakan ambil atau tunggu kurir.',
            ],
            OrderStatus::Cancelled => [
                "Pesanan {$order->order_number} dibatalkan",
                'Pesananmu dibatalkan. Hubungi cabang jika butuh bantuan.',
            ],
            default => [
                "Update pesanan {$order->order_number}",
                'Status pesananmu berubah menjadi '.$status->label().'.',
            ],
        };
    }
}
