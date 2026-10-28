<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Ordering\Models\Order;
use Illuminate\Support\Collection;

class ResolveOrderRequirements
{
    /**
     * Required base-unit quantity per ingredient for every line of the order.
     *
     * @return Collection<int, int> ingredient_id => quantity
     */
    public function __invoke(Order $order): Collection
    {
        $items = $order->items()->with('modifiers')->get();

        $targets = [];
        foreach ($items as $item) {
            $targets[Product::class][] = $item->product_id;
            if ($item->variant_id !== null) {
                $targets[ProductVariant::class][] = $item->variant_id;
            }
            foreach ($item->modifiers as $modifier) {
                $targets[Modifier::class][] = $modifier->modifier_id;
            }
        }

        $recipes = $this->recipesFor($targets);

        $requirements = [];
        foreach ($items as $item) {
            $base = $recipes[ProductVariant::class.':'.$item->variant_id]
                ?? $recipes[Product::class.':'.$item->product_id]
                ?? null;

            $this->accumulate($requirements, $base, $item->quantity);

            foreach ($item->modifiers as $modifier) {
                $this->accumulate($requirements, $recipes[Modifier::class.':'.$modifier->modifier_id] ?? null, $item->quantity);
            }
        }

        return collect($requirements)->filter()->map(fn (int $quantity): int => $quantity);
    }

    /**
     * @param  array<string, list<int>>  $targets
     * @return array<string, Recipe>
     */
    private function recipesFor(array $targets): array
    {
        $query = Recipe::query()->with('items');

        foreach ($targets as $type => $ids) {
            $query->orWhere(fn ($query) => $query->where('recipeable_type', $type)->whereIn('recipeable_id', $ids));
        }

        return $query->get()->keyBy(fn (Recipe $recipe): string => $recipe->recipeable_type.':'.$recipe->recipeable_id)->all();
    }

    /**
     * @param  array<int, int>  $requirements
     */
    private function accumulate(array &$requirements, ?Recipe $recipe, int $lineQuantity): void
    {
        if ($recipe === null) {
            return;
        }

        foreach ($recipe->items as $recipeItem) {
            $ingredientId = (int) $recipeItem->ingredient_id;
            $requirements[$ingredientId] = ($requirements[$ingredientId] ?? 0) + $recipeItem->quantity * $lineQuantity;
        }
    }
}
