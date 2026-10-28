<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRecipeRequest;
use App\Http\Requests\UpdateRecipeRequest;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Recipe;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecipeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/stock/Recipes', [
            'products' => Product::query()
                ->with('variants:id,product_id,name')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'variants' => $product->variants
                        ->map(fn (ProductVariant $variant) => [
                            'id' => $variant->id,
                            'name' => $variant->name,
                        ])
                        ->values()
                        ->all(),
                ]),
            'modifiers' => Modifier::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'ingredients' => Ingredient::query()
                ->with('baseUnit:id,code,name')
                ->orderBy('name')
                ->get(['id', 'name', 'base_unit_id'])
                ->map(fn (Ingredient $ingredient) => [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'unit_code' => $ingredient->baseUnit?->code,
                ]),
            'recipes' => Recipe::query()
                ->with([
                    'recipeable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                        ProductVariant::class => ['product'],
                    ]),
                    'items.ingredient.baseUnit',
                ])
                ->latest()
                ->get()
                ->map(fn (Recipe $recipe) => [
                    'id' => $recipe->id,
                    'target' => $this->targetLabel($recipe),
                    'items' => $recipe->items
                        ->map(fn ($item) => [
                            'ingredient_id' => $item->ingredient_id,
                            'ingredient' => $item->ingredient?->name,
                            'unit_code' => $item->ingredient?->baseUnit?->code,
                            'quantity' => $item->quantity,
                        ])
                        ->values()
                        ->all(),
                ]),
        ]);
    }

    public function store(StoreRecipeRequest $request, WriteAuditLog $audit): RedirectResponse
    {
        $data = $request->validated();

        $class = match ($data['recipeable_type']) {
            'product' => Product::class,
            'product_variant' => ProductVariant::class,
            'modifier' => Modifier::class,
        };

        $duplicate = Recipe::query()
            ->where('recipeable_type', $class)
            ->where('recipeable_id', $data['recipeable_id'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'recipeable_id' => 'Target sudah memiliki resep.',
            ]);
        }

        DB::transaction(function () use ($data, $class, $request, $audit): void {
            $recipe = Recipe::create([
                'recipeable_type' => $class,
                'recipeable_id' => $data['recipeable_id'],
            ]);
            $recipe->items()->createMany($data['items']);

            $audit(
                $request->user(),
                'recipe.created',
                $recipe,
                [],
                ['target' => $this->targetLabel($recipe), 'items' => $this->itemSnapshot($recipe)],
            );
        });

        return back()->with('success', 'Resep berhasil ditambahkan.');
    }

    public function update(UpdateRecipeRequest $request, Recipe $recipe, WriteAuditLog $audit): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($recipe, $data, $request, $audit): void {
            $before = $this->itemSnapshot($recipe);

            $recipe->items()->delete();
            $recipe->items()->createMany($data['items']);

            $audit(
                $request->user(),
                'recipe.updated',
                $recipe,
                ['target' => $this->targetLabel($recipe), 'items' => $before],
                ['target' => $this->targetLabel($recipe), 'items' => $this->itemSnapshot($recipe)],
            );
        });

        return back()->with('success', 'Resep diperbarui.');
    }

    public function destroy(Request $request, Recipe $recipe, WriteAuditLog $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $recipe, $audit): void {
            $audit(
                $request->user(),
                'recipe.deleted',
                $recipe,
                ['target' => $this->targetLabel($recipe), 'items' => $this->itemSnapshot($recipe)],
                [],
            );

            $recipe->delete();
        });

        return back()->with('success', 'Resep dihapus.');
    }

    private function targetLabel(Recipe $recipe): string
    {
        $recipe->loadMissing('recipeable');
        $target = $recipe->recipeable;

        return match (true) {
            $target instanceof Product => $target->name,
            $target instanceof ProductVariant => "{$target->product?->name} — {$target->name}",
            $target instanceof Modifier => $target->name,
            default => class_basename((string) $recipe->recipeable_type).' #'.$recipe->recipeable_id,
        };
    }

    /**
     * @return list<array{ingredient: string|null, quantity: int}>
     */
    private function itemSnapshot(Recipe $recipe): array
    {
        $recipe->load('items.ingredient');

        return $recipe->items
            ->map(fn ($item) => [
                'ingredient' => $item->ingredient?->name,
                'quantity' => $item->quantity,
            ])
            ->values()
            ->all();
    }
}
