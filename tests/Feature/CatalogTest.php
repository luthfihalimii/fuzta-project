<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_product_with_variant_and_modifier_group(): void
    {
        $owner = User::factory()->owner()->create();
        $category = Category::factory()->create();
        $group = ModifierGroup::factory()->create(['name' => 'Crust', 'min_select' => 1, 'max_select' => 1]);

        $this->actingAs($owner)->post('/admin/catalog/products', [
            'category_id' => $category->id,
            'name' => 'Pizza Margherita',
            'base_price' => 50000,
            'variants' => [
                ['name' => 'Regular', 'price' => 50000],
                ['name' => 'Large', 'price' => 75000],
            ],
            'modifier_group_ids' => [$group->id],
        ])->assertRedirect();

        $product = Product::where('name', 'Pizza Margherita')->firstOrFail();
        $this->assertSame(2, $product->variants()->count());
        $this->assertTrue($product->modifierGroups->contains($group));
    }

    public function test_cashier_cannot_manage_catalog(): void
    {
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)->get('/admin/catalog/products')->assertForbidden();
    }

    public function test_cashier_cannot_open_product_create(): void
    {
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)->get('/admin/catalog/products/create')->assertForbidden();
    }

    public function test_product_availability_is_branch_specific(): void
    {
        $product = Product::factory()->create();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $product->branches()->attach($branchA, ['is_available' => true]);

        $this->assertTrue($product->isAvailableAt($branchA->id));
        $this->assertFalse($product->isAvailableAt($branchB->id));
    }

    public function test_owner_can_open_catalog_pages(): void
    {
        $owner = User::factory()->owner()->create();
        $category = Category::factory()->create();
        $group = ModifierGroup::factory()->create();
        $product = Product::factory()->for($category)->create();

        $this->actingAs($owner)
            ->get('/admin/catalog/categories')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/catalog/Categories'));

        $this->actingAs($owner)
            ->get('/admin/catalog/products')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/catalog/Products'));

        $this->actingAs($owner)
            ->get('/admin/catalog/products/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/catalog/ProductEdit')
                ->where('product', null)
                ->has('categories')
                ->has('modifierGroups')
                ->has('branches'));

        $this->actingAs($owner)
            ->get("/admin/catalog/products/{$product->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/catalog/ProductEdit')
                ->where('product.id', $product->id));

        $this->actingAs($owner)
            ->get('/admin/catalog/modifier-groups')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/catalog/ModifierGroups'));
    }

    public function test_owner_can_create_and_update_category(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/admin/catalog/categories', [
            'name' => 'Pizza',
            'sort_order' => 1,
            'is_active' => true,
        ])->assertRedirect();

        $category = Category::where('name', 'Pizza')->firstOrFail();

        $this->actingAs($owner)->patch("/admin/catalog/categories/{$category->id}", [
            'name' => 'Pizza Klasik',
            'sort_order' => 2,
            'is_active' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Pizza Klasik',
            'is_active' => false,
        ]);
    }

    public function test_owner_can_create_modifier_group_with_modifiers(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/admin/catalog/modifier-groups', [
            'name' => 'Topping',
            'min_select' => 0,
            'max_select' => 3,
            'is_required' => false,
            'modifiers' => [
                ['name' => 'Extra Cheese', 'price_delta' => 10000],
                ['name' => 'Pepperoni', 'price_delta' => 15000],
            ],
        ])->assertRedirect();

        $group = ModifierGroup::where('name', 'Topping')->firstOrFail();
        $this->assertSame(2, $group->modifiers()->count());
    }

    public function test_owner_can_update_modifier_group_and_sync_modifiers(): void
    {
        $owner = User::factory()->owner()->create();
        $group = ModifierGroup::factory()->create();
        $kept = Modifier::factory()->for($group)->create(['name' => 'Kept']);
        $removed = Modifier::factory()->for($group)->create(['name' => 'Removed']);

        $this->actingAs($owner)->patch("/admin/catalog/modifier-groups/{$group->id}", [
            'name' => 'Topping Baru',
            'min_select' => 1,
            'max_select' => 2,
            'is_required' => true,
            'modifiers' => [
                ['id' => $kept->id, 'name' => 'Kept Updated', 'price_delta' => 12000],
                ['name' => 'Baru', 'price_delta' => 5000],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('modifiers', ['id' => $kept->id, 'name' => 'Kept Updated']);
        $this->assertDatabaseMissing('modifiers', ['id' => $removed->id]);
        $this->assertDatabaseHas('modifiers', ['modifier_group_id' => $group->id, 'name' => 'Baru']);
    }

    public function test_product_creation_rejects_negative_prices(): void
    {
        $owner = User::factory()->owner()->create();
        $category = Category::factory()->create();

        $this->actingAs($owner)->post('/admin/catalog/products', [
            'category_id' => $category->id,
            'name' => 'Pizza Salah',
            'base_price' => -1,
            'variants' => [
                ['name' => 'Regular', 'price' => -1],
            ],
        ])->assertSessionHasErrors(['base_price', 'variants.0.price']);

        $this->assertDatabaseMissing('products', ['name' => 'Pizza Salah']);
    }

    public function test_product_creation_rejects_unknown_modifier_group(): void
    {
        $owner = User::factory()->owner()->create();
        $category = Category::factory()->create();

        $this->actingAs($owner)->post('/admin/catalog/products', [
            'category_id' => $category->id,
            'name' => 'Pizza Salah',
            'base_price' => 50000,
            'modifier_group_ids' => [999999],
        ])->assertSessionHasErrors('modifier_group_ids.0');
    }

    public function test_owner_can_toggle_branch_availability_without_touching_variants(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::factory()->create();
        $variant = $product->variants()->create(['name' => 'Regular', 'price' => 50000]);
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $this->actingAs($owner)->patch("/admin/catalog/products/{$product->id}", [
            'branch_availability' => [
                ['branch_id' => $branchA->id, 'is_available' => true],
                ['branch_id' => $branchB->id, 'is_available' => false],
            ],
        ])->assertRedirect();

        $this->assertTrue($product->isAvailableAt($branchA->id));
        $this->assertFalse($product->isAvailableAt($branchB->id));
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
    }

    public function test_owner_can_update_product_and_sync_variants_groups_and_branches(): void
    {
        $owner = User::factory()->owner()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        $first = $product->variants()->create(['name' => 'Regular', 'price' => 50000, 'sort_order' => 0]);
        $removed = $product->variants()->create(['name' => 'Hapus', 'price' => 40000, 'sort_order' => 1]);
        $oldGroup = ModifierGroup::factory()->create();
        $newGroup = ModifierGroup::factory()->create();
        $product->modifierGroups()->attach($oldGroup);
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $this->actingAs($owner)->patch("/admin/catalog/products/{$product->id}", [
            'category_id' => $category->id,
            'name' => 'Pizza Diperbarui',
            'base_price' => 55000,
            'is_active' => true,
            'variants' => [
                ['id' => $first->id, 'name' => 'Regular', 'price' => 52000],
                ['name' => 'Large', 'price' => 78000],
            ],
            'modifier_group_ids' => [$newGroup->id],
            'branch_availability' => [
                ['branch_id' => $branchA->id, 'is_available' => true],
                ['branch_id' => $branchB->id, 'is_available' => false],
            ],
        ])->assertRedirect();

        $product->refresh();

        $this->assertSame('Pizza Diperbarui', $product->name);
        $this->assertDatabaseHas('product_variants', ['id' => $first->id, 'price' => 52000]);
        $this->assertDatabaseMissing('product_variants', ['id' => $removed->id]);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'name' => 'Large']);
        $this->assertTrue($product->modifierGroups->contains($newGroup));
        $this->assertFalse($product->modifierGroups->contains($oldGroup));
        $this->assertTrue($product->isAvailableAt($branchA->id));
        $this->assertFalse($product->isAvailableAt($branchB->id));
    }
}
