<?php

namespace App\Modules\Delivery\Actions;

use App\Modules\Delivery\Clients\BiteshipClient;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Buat order kurir Biteship untuk pesanan delivery yang sudah paid.
 *
 * Idempotent: tolak ganda bila `biteship_order_id` sudah tersimpan (retry
 * job / repeat event tidak boleh membuat dua order kurir). Gagal provider →
 * `delivery_status = failed` + log (tanpa PII) agar owner bisa retry manual;
 * order tidak dirusak (tetap paid/ready, tidak pernah selesai otomatis).
 */
class CreateBiteshipShipment
{
    private const FALLBACK_WEIGHT_GRAMS = 500;

    public function __construct(
        private readonly BiteshipClient $biteship,
    ) {}

    public function __invoke(Order $order): void
    {
        $order->loadMissing(['branch', 'items.product']);

        if ($order->channel !== OrderChannel::Delivery
            || $order->payment_status !== PaymentStatus::Paid
            || $order->biteship_order_id !== null
            || $order->delivery_area_id === null
            || $order->delivery_area_id === '') {
            return;
        }

        $branch = $order->branch;

        // Tanpa area cabang payload pasti ditolak provider — tandai failed
        // tanpa memanggil API (log hanya order_id + reason, tanpa PII).
        if ($branch->delivery_area_id === null || $branch->delivery_area_id === '') {
            Log::warning('Pembuatan shipment Biteship gagal (cabang tanpa delivery_area_id).', [
                'order_id' => $order->id,
                'reason' => 'branch_delivery_area_missing',
            ]);

            $order->forceFill(['delivery_status' => 'failed'])->save();

            return;
        }

        $payload = [
            'reference_id' => $order->order_number,
            'shipper_contact_name' => (string) config('services.biteship.shipper_name', ''),
            'shipper_contact_phone' => (string) config('services.biteship.shipper_phone', ''),
            'shipper_contact_email' => (string) config('services.biteship.shipper_email', ''),
            'origin_contact_name' => (string) $branch->name,
            'origin_contact_phone' => (string) $branch->phone,
            'origin_address' => (string) $branch->address,
            'origin_area_id' => $branch->delivery_area_id,
            'destination_contact_name' => (string) $order->customer_name,
            'destination_contact_phone' => (string) $order->customer_phone,
            'destination_address' => (string) $order->delivery_address,
            'destination_area_id' => $order->delivery_area_id,
            'courier_company' => $order->courier_name,
            'courier_type' => $order->courier_service,
            'delivery_type' => 'now',
            'order_note' => $order->note,
            'items' => $order->items->map(static function (array|object $item): array {
                $weight = (int) ($item->product?->weight_grams ?? 0) ?: self::FALLBACK_WEIGHT_GRAMS;

                return [
                    'name' => (string) $item->product_name,
                    'value' => (int) $item->line_total,
                    'quantity' => (int) $item->quantity,
                    'weight' => $weight * (int) $item->quantity,
                    'height' => 10,
                    'length' => 10,
                    'width' => 10,
                ];
            })->all(),
        ];

        try {
            $result = $this->biteship->createOrder($payload);
        } catch (InvalidArgumentException|RuntimeException $e) {
            // Jangan log PII (nomor HP/alamat customer) — hanya order_id +
            // class exception.
            Log::warning('Pembuatan shipment Biteship gagal.', [
                'order_id' => $order->id,
                'exception' => $e::class,
            ]);

            $order->forceFill(['delivery_status' => 'failed'])->save();

            return;
        }

        $order->forceFill([
            'biteship_order_id' => $result['id'],
            'tracking_url' => $result['tracking_url'],
            'delivery_status' => 'requested',
        ])->save();
    }
}
