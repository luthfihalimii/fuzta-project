<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\RecipeItem;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\UnitConversion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class RecipeSchemaTest extends TestCase
{
    use DatabaseMigrations;

    public function test_recipe_belongs_to_product_with_items(): void
    {
        $product = Product::factory()->create();
        $recipe = Recipe::factory()->for($product, 'recipeable')->create();
        $ingredient = Ingredient::factory()->create();
        $item = RecipeItem::factory()->for($recipe)->for($ingredient)->create(['quantity' => 250]);

        $this->assertSame($product->id, $recipe->recipeable->id);
        $this->assertSame(Product::class, $recipe->recipeable_type);
        $this->assertSame(250, $item->quantity);
        $this->assertSame($recipe->id, $item->recipe->id);
        $this->assertSame($ingredient->id, $item->ingredient->id);
        $this->assertSame($item->id, $recipe->items->first()->id);
    }

    public function test_recipe_attaches_to_variant_and_modifier(): void
    {
        $variant = ProductVariant::factory()->create();
        $modifier = Modifier::factory()->create();

        $variantRecipe = Recipe::factory()->for($variant, 'recipeable')->create();
        $modifierRecipe = Recipe::factory()->for($modifier, 'recipeable')->create();

        $this->assertSame($variant->id, $variantRecipe->recipeable->id);
        $this->assertSame($modifier->id, $modifierRecipe->recipeable->id);
    }

    public function test_one_recipe_per_recipeable(): void
    {
        $product = Product::factory()->create();
        Recipe::factory()->for($product, 'recipeable')->create();

        try {
            Recipe::factory()->for($product, 'recipeable')->create();
            $this->fail('Duplicate recipe for the same recipeable was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0] ?? null);
        }
    }

    public function test_unit_conversion_pairs_units_once(): void
    {
        $kilogram = Unit::factory()->create(['code' => 'kg']);
        $gram = Unit::factory()->create(['code' => 'g']);
        $conversion = UnitConversion::factory()->for($kilogram, 'fromUnit')->for($gram, 'toUnit')->create([
            'from_quantity' => 1,
            'to_quantity' => 1000,
        ]);

        $this->assertSame('kg', $conversion->fromUnit->code);
        $this->assertSame('g', $conversion->toUnit->code);
        $this->assertSame(1000, $conversion->to_quantity);

        try {
            UnitConversion::factory()->for($kilogram, 'fromUnit')->for($gram, 'toUnit')->create();
            $this->fail('Duplicate unit conversion pair was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0] ?? null);
        }
    }

    public function test_consumption_movement_types_exist_and_do_not_require_owner(): void
    {
        $this->assertSame('consumption', StockMovementType::Consumption->value);
        $this->assertSame('consumption_reversal', StockMovementType::ConsumptionReversal->value);
        $this->assertSame('Konsumsi pesanan', StockMovementType::Consumption->label());
        $this->assertSame('Pembalikan konsumsi', StockMovementType::ConsumptionReversal->label());
        $this->assertFalse(StockMovementType::Consumption->requiresOwner());
        $this->assertFalse(StockMovementType::ConsumptionReversal->requiresOwner());
    }
}
