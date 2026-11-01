<?php

namespace App\Modules\Delivery\Clients;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class BiteshipClient
{
    /**
     * Cari area pengiriman Indonesia (GET /v1/maps/areas).
     *
     * @return array<int, array{id: string, name: string, city: ?string, province: ?string, postal_code: ?string}>
     */
    public function areas(string $query): array
    {
        $response = Http::withToken((string) config('services.biteship.api_key'))
            ->baseUrl((string) config('services.biteship.base_url'))
            ->acceptJson()
            ->get('/v1/maps/areas', ['countries' => 'ID', 'input' => $query]);

        if ($response->failed()) {
            throw new RuntimeException('Biteship area search failed.');
        }

        $areas = $response->json('areas');

        if (! is_array($areas)) {
            throw new RuntimeException('Biteship area search returned unexpected payload.');
        }

        return array_map(
            static fn (array $area): array => [
                'id' => (string) $area['id'],
                'name' => (string) ($area['name'] ?? ''),
                'city' => $area['administrative_division_level_2_name'] ?? null,
                'province' => $area['administrative_division_level_1_name'] ?? null,
                'postal_code' => $area['postal_code'] ?? null,
            ],
            array_filter($areas, 'is_array'),
        );
    }
}
