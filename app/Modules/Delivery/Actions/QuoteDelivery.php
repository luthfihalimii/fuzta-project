<?php

namespace App\Modules\Delivery\Actions;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Delivery\Clients\BiteshipClient;
use Illuminate\Validation\ValidationException;

class QuoteDelivery
{
    public function __construct(
        private readonly BiteshipClient $biteship,
    ) {}

    /**
     * Kalkulasi ongkir server-side. $items = hasil PriceOrderItems (sudah terharga).
     *
     * @param  list<array{product_id: int, product_name: ?string, quantity: int, line_total: int, note: ?string}>  $items
     * @return array<int, array{courier_name: string, courier_service: string, fee: int, etd: ?string}>
     */
    public function __invoke(int $branchId, ?string $destinationAreaId, array $items): array
    {
        if ($destinationAreaId === null || $destinationAreaId === '') {
            return [[
                'courier_name' => 'Ambil sendiri',
                'courier_service' => 'pickup',
                'fee' => 0,
                'etd' => null,
            ]];
        }

        $branch = Branch::query()->find($branchId);

        if ($branch === null || ! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang tidak aktif.',
            ]);
        }

        if ($branch->delivery_area_id === null || $branch->delivery_area_id === '') {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang ini tidak melayani pengiriman.',
            ]);
        }

        $weights = Product::query()
            ->whereIn('id', array_column($items, 'product_id'))
            ->pluck('weight_grams', 'id')
            ->all();

        $payload = array_map(static function (array $line) use ($weights): array {
            $unitWeight = (int) ($weights[(int) $line['product_id']] ?? 0) ?: 500;

            return [
                'name' => (string) ($line['product_name'] ?? ''),
                'description' => (string) ($line['note'] ?? ''),
                'value' => (int) $line['line_total'],
                'quantity' => (int) $line['quantity'],
                'weight' => $unitWeight * (int) $line['quantity'],
                'height' => 10,
                'length' => 10,
                'width' => 10,
            ];
        }, array_values($items));

        $rates = $this->biteship->rates($branch->delivery_area_id, $destinationAreaId, $payload);

        $options = [];

        foreach ($rates as $rate) {
            $options[] = [
                'courier_name' => (string) ($rate['courier_name'] ?? 'Tidak diketahui'),
                'courier_service' => (string) ($rate['courier_service_name'] ?? ''),
                'fee' => (int) round((float) ($rate['price'] ?? 0)),
                'etd' => isset($rate['duration']) ? (string) $rate['duration'] : null,
            ];
        }

        if ($options === []) {
            throw ValidationException::withMessages([
                'delivery_area_id' => 'Tidak ada kurir untuk alamat ini.',
            ]);
        }

        return $options;
    }
}
