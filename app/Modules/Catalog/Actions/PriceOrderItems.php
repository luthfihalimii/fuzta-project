<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PriceOrderItems
{
    /**
     * @param  list<array{product_id: int, variant_id: ?int, modifier_ids: list<int>, quantity: int, note: ?string}>  $items
     * @return list<array{
     *     product_id: int,
     *     product_name: string,
     *     variant_id: ?int,
     *     variant_name: ?string,
     *     quantity: int,
     *     unit_price: int,
     *     line_total: int,
     *     note: ?string,
     *     modifiers: list<array{modifier_id: int, modifier_name: string, price_delta: int}>
     * }>
     */
    public function __invoke(int $branchId, array $items): array
    {
        $priced = [];

        foreach (array_values($items) as $index => $item) {
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($quantity < 1 || $quantity > 99) {
                $this->fail($index, 'quantity', 'Jumlah harus antara 1 dan 99.');
            }

            $product = Product::query()
                ->with(['variants', 'modifierGroups'])
                ->find($item['product_id'] ?? null);

            if ($product === null || ! $product->is_active) {
                $this->fail($index, 'product_id', 'Produk tidak tersedia.');
            }

            if (! $product->isAvailableAt($branchId)) {
                $this->fail($index, 'product_id', 'Produk tidak tersedia di cabang ini.');
            }

            $activeVariants = $product->variants->where('is_active', true);
            $variant = null;

            if (! empty($item['variant_id'])) {
                $variant = $activeVariants->firstWhere('id', (int) $item['variant_id']);

                if ($variant === null) {
                    $this->fail($index, 'variant_id', 'Varian tidak tersedia untuk produk ini.');
                }

                $unitPrice = $variant->price;
            } else {
                if ($activeVariants->isNotEmpty()) {
                    $this->fail($index, 'variant_id', 'Pilih varian produk ini.');
                }

                $unitPrice = $product->base_price;
            }

            $modifierIds = array_values(array_unique(array_map(
                'intval',
                $item['modifier_ids'] ?? [],
            )));

            /** @var Collection<int, Modifier> $modifiers */
            $modifiers = Modifier::query()
                ->whereIn('id', $modifierIds)
                ->get()
                ->keyBy('id');

            if ($modifiers->count() !== count($modifierIds)) {
                $this->fail($index, 'modifier_ids', 'Modifier tidak tersedia untuk produk ini.');
            }

            $attachedGroups = $product->modifierGroups->keyBy('id');
            /** @var array<int, list<Modifier>> $selectedByGroup */
            $selectedByGroup = [];
            $modifierSnapshots = [];

            foreach ($modifierIds as $modifierId) {
                $modifier = $modifiers->get($modifierId);

                if ($modifier === null || ! $modifier->is_active) {
                    $this->fail($index, 'modifier_ids', 'Modifier tidak tersedia untuk produk ini.');
                }

                $group = $attachedGroups->get($modifier->modifier_group_id);

                if ($group === null) {
                    $this->fail($index, 'modifier_ids', 'Modifier tidak tersedia untuk produk ini.');
                }

                $selectedByGroup[$group->id][] = $modifier;
                $unitPrice += $modifier->price_delta;

                $modifierSnapshots[] = [
                    'modifier_id' => $modifier->id,
                    'modifier_name' => $modifier->name,
                    'price_delta' => $modifier->price_delta,
                ];
            }

            foreach ($attachedGroups as $group) {
                $selectedCount = count($selectedByGroup[$group->id] ?? []);

                if ($group->is_required && $selectedCount < $group->min_select) {
                    $this->fail($index, 'modifier_ids', "Pilih minimal {$group->min_select} {$group->name}.");
                }

                if ($selectedCount > $group->max_select) {
                    $this->fail($index, 'modifier_ids', "Maksimal {$group->max_select} {$group->name}.");
                }
            }

            if ($unitPrice < 0) {
                $this->fail($index, 'modifier_ids', 'Harga item tidak boleh negatif.');
            }

            $priced[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'variant_id' => $variant?->id,
                'variant_name' => $variant?->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $unitPrice * $quantity,
                'note' => $item['note'] ?? null,
                'modifiers' => $modifierSnapshots,
            ];
        }

        return $priced;
    }

    private function fail(int $index, string $field, string $message): never
    {
        throw ValidationException::withMessages([
            "items.{$index}.{$field}" => $message,
        ]);
    }
}
