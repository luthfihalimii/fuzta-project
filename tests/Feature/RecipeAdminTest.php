<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RecipeAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_cannot_open_recipes_page(): void
    {
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)->get('/admin/stock/recipes')->assertForbidden();
    }

    public function test_owner_sees_empty_recipes_page(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get('/admin/stock/recipes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/stock/Recipes')
                ->has('recipes', 0)
                ->has('products')
                ->has('modifiers')
                ->has('ingredients'));
    }

    public function test_owner_can_store_recipe_with_items(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::factory()->create();
        $flour = Ingredient::factory()->create(['name' => 'Tepung']);
        $cheese = Ingredient::factory()->create(['name' => 'Keju']);

        $this->actingAs($owner)->post('/admin/stock/recipes', [
            'recipeable_type' => 'product',
            'recipeable_id' => $product->id,
            'items' => [
                ['ingredient_id' => $flour->id, 'quantity' => 200],
                ['ingredient_id' => $cheese->id, 'quantity' => 50],
            ],
        ])->assertRedirect();

        $this->assertSame(1, Recipe::count());
        $recipe = Recipe::firstOrFail();
        $this->assertSame(Product::class, $recipe->recipeable_type);
        $this->assertSame($product->id, $recipe->recipeable_id);
        $this->assertSame(2, $recipe->items()->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'recipe.created',
            'target_type' => Recipe::class,
            'target_id' => $recipe->id,
        ]);

        $log = AuditLog::where('action', 'recipe.created')->firstOrFail();
        $this->assertCount(2, $log->after['items']);
    }

    public function test_store_recipe_rejects_duplicate_target(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $payload = [
            'recipeable_type' => 'product',
            'recipeable_id' => $product->id,
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 100]],
        ];

        $this->actingAs($owner)->post('/admin/stock/recipes', $payload)->assertRedirect();

        $this->actingAs($owner)
            ->from('/admin/stock/recipes')
            ->post('/admin/stock/recipes', $payload)
            ->assertSessionHasErrors('recipeable_id');

        $this->assertSame(1, Recipe::count());
        $this->assertStringContainsString(
            'sudah memiliki resep',
            session('errors')->first('recipeable_id'),
        );
    }

    public function test_owner_can_update_recipe_items(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::factory()->create();
        $flour = Ingredient::factory()->create(['name' => 'Tepung']);
        $cheese = Ingredient::factory()->create(['name' => 'Keju']);
        $yeast = Ingredient::factory()->create(['name' => 'Ragi']);

        $this->actingAs($owner)->post('/admin/stock/recipes', [
            'recipeable_type' => 'product',
            'recipeable_id' => $product->id,
            'items' => [
                ['ingredient_id' => $flour->id, 'quantity' => 200],
                ['ingredient_id' => $cheese->id, 'quantity' => 50],
            ],
        ])->assertRedirect();

        $recipe = Recipe::firstOrFail();

        $this->actingAs($owner)->patch("/admin/stock/recipes/{$recipe->id}", [
            'items' => [
                ['ingredient_id' => $yeast->id, 'quantity' => 5],
            ],
        ])->assertRedirect();

        $recipe->refresh();
        $this->assertSame(1, $recipe->items()->count());
        $this->assertDatabaseHas('recipe_items', [
            'recipe_id' => $recipe->id,
            'ingredient_id' => $yeast->id,
            'quantity' => 5,
        ]);
        $this->assertDatabaseMissing('recipe_items', [
            'recipe_id' => $recipe->id,
            'ingredient_id' => $flour->id,
        ]);

        $log = AuditLog::where('action', 'recipe.updated')->firstOrFail();
        $this->assertCount(2, $log->before['items']);
        $this->assertCount(1, $log->after['items']);
        $this->assertSame('Ragi', $log->after['items'][0]['ingredient']);
        $this->assertSame(5, $log->after['items'][0]['quantity']);
    }

    public function test_owner_can_delete_recipe(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Tepung']);

        $this->actingAs($owner)->post('/admin/stock/recipes', [
            'recipeable_type' => 'product',
            'recipeable_id' => $product->id,
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 100]],
        ])->assertRedirect();

        $recipe = Recipe::firstOrFail();

        $this->actingAs($owner)
            ->delete("/admin/stock/recipes/{$recipe->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('recipes', ['id' => $recipe->id]);
        $this->assertDatabaseMissing('recipe_items', ['recipe_id' => $recipe->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'recipe.deleted',
            'target_type' => Recipe::class,
            'target_id' => $recipe->id,
        ]);
    }

    public function test_store_recipe_validates_items(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $this->actingAs($owner)->post('/admin/stock/recipes', [
            'recipeable_type' => 'product',
            'recipeable_id' => $product->id,
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 0]],
        ])->assertSessionHasErrors('items.0.quantity');

        $this->actingAs($owner)->post('/admin/stock/recipes', [
            'recipeable_type' => 'product',
            'recipeable_id' => $product->id,
            'items' => [['ingredient_id' => 999999, 'quantity' => 10]],
        ])->assertSessionHasErrors('items.0.ingredient_id');

        $this->assertSame(0, Recipe::count());
    }

    public function test_recipeable_id_must_match_recipeable_type(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $this->actingAs($owner)->post('/admin/stock/recipes', [
            'recipeable_type' => 'modifier',
            'recipeable_id' => $product->id,
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 10]],
        ])->assertSessionHasErrors('recipeable_id');

        $this->assertSame(0, Recipe::count());
    }

    public function test_owner_can_store_recipe_for_variant_and_modifier(): void
    {
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();
        $modifier = Modifier::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $this->actingAs($owner)->post('/admin/stock/recipes', [
            'recipeable_type' => 'product_variant',
            'recipeable_id' => $variant->id,
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 10]],
        ])->assertRedirect();

        $this->actingAs($owner)->post('/admin/stock/recipes', [
            'recipeable_type' => 'modifier',
            'recipeable_id' => $modifier->id,
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 20]],
        ])->assertRedirect();

        $this->assertDatabaseHas('recipes', [
            'recipeable_type' => ProductVariant::class,
            'recipeable_id' => $variant->id,
        ]);
        $this->assertDatabaseHas('recipes', [
            'recipeable_type' => Modifier::class,
            'recipeable_id' => $modifier->id,
        ]);
    }
}
