<?php

namespace App\Modules\Delivery\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class BiteshipClient
{
    /**
     * Cari area pengiriman Indonesia (GET /v1/maps/areas).
     *
     * @return array<int, array{id: ?string, name: ?string, city: ?string, province: ?string, postal_code: ?string}>
     */
    public function areas(string $query): array
    {
        $response = Http::withToken((string) config('services.biteship.api_key'))
            ->baseUrl((string) config('services.biteship.base_url'))
            ->acceptJson()
            ->timeout(5)
            ->connectTimeout(3)
            ->get('/v1/maps/areas', ['countries' => 'ID', 'input' => $query]);

        if ($response->failed()) {
            throw new RuntimeException('Biteship area search failed.');
        }

        $areas = $response->json('areas');

        if (! is_array($areas)) {
            throw new RuntimeException('Biteship area search returned unexpected payload.');
        }

        // array_values: respons JSON harus tetap list, bukan object ber-key.
        return array_values(array_map(
            static fn (array $area): array => [
                'id' => $area['id'] ?? null,
                'name' => $area['name'] ?? null,
                'city' => $area['administrative_division_level_2_name'] ?? null,
                'province' => $area['administrative_division_level_1_name'] ?? null,
                'postal_code' => $area['postal_code'] ?? null,
            ],
            array_filter($areas, 'is_array'),
        ));
    }

    /**
     * Cari tarif kurir (POST /v1/rates).
     * shortcut: bentuk body mengikuti plan (docs Biteship tak terjangkau saat implementasi).
     *
     * @param  list<array{name: string, description: string, value: int, quantity: int, weight: int, height: int, length: int, width: int}>  $items
     * @return array<int, array<string, mixed>>
     */
    public function rates(string $originAreaId, string $destinationAreaId, array $items): array
    {
        $couriers = config('services.biteship.couriers');

        if (! is_array($couriers) || $couriers === []) {
            $couriers = ['jne', 'jnt', 'sicepat', 'anteraja', 'gojek', 'grab', 'sentral'];
        }

        $response = Http::withToken((string) config('services.biteship.api_key'))
            ->baseUrl((string) config('services.biteship.base_url'))
            ->acceptJson()
            ->timeout(10)
            ->connectTimeout(3)
            ->post('/v1/rates', [
                'origin_area_id' => $originAreaId,
                'destination_area_id' => $destinationAreaId,
                'couriers' => $couriers,
                'items' => $items,
            ]);

        // 400/404/422 = parameter/area tidak valid (salah client) → 422 ke customer.
        if (in_array($response->status(), [400, 404, 422], true)) {
            throw new InvalidArgumentException('Biteship menolak request rates (area/parameter tidak valid).');
        }

        // 401/403/429 = miskonfigurasi API key / rate limit provider → 502 (bukan salah customer).
        if ($response->failed()) {
            throw new RuntimeException('Biteship rates request failed.');
        }

        $rates = $response->json('pricing');

        if (! is_array($rates)) {
            throw new RuntimeException('Biteship rates request returned unexpected payload.');
        }

        return array_values(array_filter($rates, 'is_array'));
    }

    /**
     * Buat order kurir (POST /v1/orders). Dikirim oleh job (bukan request
     * user) → tidak ada data customer di luar field yang memang disyaratkan
     * provider; jangan log body/PII.
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: string, courier_name: string, courier_service: string, tracking_url: ?string}
     */
    public function createOrder(array $payload): array
    {
        try {
            $response = Http::withToken((string) config('services.biteship.api_key'))
                ->baseUrl((string) config('services.biteship.base_url'))
                ->acceptJson()
                ->timeout(10)
                ->connectTimeout(3)
                ->post('/v1/orders', $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Biteship order connection failed.');
        }

        if ($response->status() >= 400 && $response->status() < 500) {
            // 40002060 = reference_id sudah dipakai: order kurirnya sudah ada
            // (biasanya respons sukses terlewat, mis. timeout) → adopsi order
            // lama, bukan gagal. Bentuk resmi docs memakai `code` +
            // `details.{order_id,waybill_id}`; `error_code` dikenali agar
            // varian plan tetap jalan. 4xx lain tetap ditolak.
            if ((int) $response->json('code') === 40002060
                || (int) $response->json('error_code') === 40002060) {
                $id = (string) ($response->json('details.order_id') ?? $response->json('id') ?? '');

                if ($id === '') {
                    throw new RuntimeException('Biteship duplicate-reference response missing order id.');
                }

                $waybill = (string) ($response->json('details.waybill_id') ?? $response->json('courier.waybill_id') ?? '');

                return [
                    'id' => $id,
                    'courier_name' => '',
                    'courier_service' => '',
                    'tracking_url' => $waybill !== '' ? "https://track.biteship.com/{$waybill}" : null,
                ];
            }

            throw new InvalidArgumentException('Biteship menolak pembuatan order (payload/kurir tidak valid).');
        }

        // 5xx = gangguan sisi provider.
        if ($response->failed()) {
            throw new RuntimeException('Biteship order request failed.');
        }

        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Biteship order response missing id.');
        }

        $waybill = $response->json('courier.waybill_id');

        return [
            'id' => $id,
            'courier_name' => (string) $response->json('courier.company', ''),
            'courier_service' => (string) $response->json('courier.type', ''),
            'tracking_url' => is_string($waybill) && $waybill !== ''
                ? "https://track.biteship.com/{$waybill}"
                : null,
        ];
    }
}
