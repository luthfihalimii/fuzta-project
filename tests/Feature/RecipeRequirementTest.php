<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Actions\ResolveOrderRequirements;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Collection;
use Tests\TestCase;

class RecipeRequirementTest extends TestCase
{
    use DatabaseMigrations;

    public function test_product_recipe_requirement(): void
    {
        $product = Product::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $this->recipeFor($product, [$ingredient->id => 2]);
        $order = $this->orderWith($product, null, [], 1);

        $requirements = (new ResolveOrderRequirements)($order);

        $this->assertSame([$ingredient->id => 2], $requirements->all());
    }

    public function test_variant_and_modifier_requirements_multiply_by_line_quantity(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create();
        $modifier = Modifier::factory()->create();
        $ingredientA = Ingredient::factory()->create();
        $ingredientB = Ingredient::factory()->create();

        $this->recipeFor($variant, [$ingredientA->id => 1, $ingredientB->id => 2]);
        $this->recipeFor($modifier, [$ingredientB->id => 1]);

        $order = $this->orderWith($product, $variant, [$modifier], 3);

        $requirements = (new ResolveOrderRequirements)($order);

        $this->assertInstanceOf(Collection::class, $requirements);
        $this->assertSame([
            $ingredientA->id => 3,
            $ingredientB->id => 9,
        ], $requirements->all());
    }

    public function test_variant_recipe_wins_over_product_recipe(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create();
        $ingredientA = Ingredient::factory()->create();
        $ingredientB = Ingredient::factory()->create();

        $this->recipeFor($product, [$ingredientA->id => 1, $ingredientB->id => 1]);
        $this->recipeFor($variant, [$ingredientA->id => 2, $ingredientB->id => 4]);

        $order = $this->orderWith($product, $variant, [], 1);

        $requirements = (new ResolveOrderRequirements)($order);

        $this->assertSame([
            $ingredientA->id => 2,
            $ingredientB->id => 4,
        ], $requirements->all());
    }

    public function test_product_without_recipe_yields_empty_requirements(): void
    {
        $product = Product::factory()->create();
        $order = $this->orderWith($product, null, [], 1);

        $requirements = (new ResolveOrderRequirements)($order);

        $this->assertInstanceOf(Collection::class, $requirements);
        $this->assertTrue($requirements->isEmpty());
    }

    public function test_modifier_recipe_counts_without_product_or_variant_recipe(): void
    {
        $product = Product::factory()->create();
        $modifier = Modifier::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $this->recipeFor($modifier, [$ingredient->id => 2]);

        $order = $this->orderWith($product, null, [$modifier], 2);

        $requirements = (new ResolveOrderRequirements)($order);

        $this->assertSame([$ingredient->id => 4], $requirements->all());
    }

    /**
     * @param  array<int, int>  $quantities  ingredient_id => quantity per unit
     */
    private function recipeFor(object $recipeable, array $quantities): Recipe
    {
        $recipe = Recipe::factory()->for($recipeable, 'recipeable')->create();

        foreach ($quantities as $ingredientId => $quantity) {
            $recipe->items()->create(['ingredient_id' => $ingredientId, 'quantity' => $quantity]);
        }

        return $recipe;
    }

    /**
     * @param  array<int, Modifier>  $modifiers
     */
    private function orderWith(Product $product, ?ProductVariant $variant, array $modifiers, int $quantity): Order
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->for($product)->create([
            'variant_id' => $variant?->id,
            'quantity' => $quantity,
        ]);

        foreach ($modifiers as $modifier) {
            $item->modifiers()->create([
                'modifier_name' => $modifier->name,
                'modifier_id' => $modifier->id,
                'price_delta' => $modifier->price_delta,
            ]);
        }

        return $order;
    }
}
