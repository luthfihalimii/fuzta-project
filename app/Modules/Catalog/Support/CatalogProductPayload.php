<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;

class CatalogProductPayload
{
    /**
     * Bentuk katalog untuk API customer (nama field mengikuti payload PosController).
     *
     * @return array<string, mixed>
     */
    public function __invoke(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category === null ? null : [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ],
            'base_price' => $product->base_price,
            'weight_grams' => $product->weight_grams,
            'image_url' => $product->imageUrl(),
            'variants' => $product->variants
                ->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'price' => $variant->price,
                ])
                ->values()
                ->all(),
            'modifier_groups' => $product->modifierGroups
                ->map(function (ModifierGroup $group): array {
                    return [
                        'id' => $group->id,
                        'name' => $group->name,
                        'modifiers' => $group->modifiers
                            ->map(fn (Modifier $modifier): array => [
                                'id' => $modifier->id,
                                'name' => $modifier->name,
                                'price_delta' => $modifier->price_delta,
                            ])
                            ->values()
                            ->all(),
                    ];
                })
                ->values()
                ->all(),
        ];
    }
}
