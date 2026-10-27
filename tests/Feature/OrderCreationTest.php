<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Ordering\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_total_uses_server_prices_and_ignores_client_price(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        [$product, $large, $cheese] = $this->pizzaForBranch($branch);

        $this->actingAs($cashier)->post('/pos/orders', [
            'branch_id' => $branch->id,
            'channel' => 'dine_in',
            'table_number' => 'A-12',
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $large->id,
                'modifier_ids' => [$cheese->id],
                'quantity' => 2,
                'note' => 'tanpa bawang',
                'unit_price' => 1,
            ]],
            'idempotency_key' => 'checkout-001',
        ])->assertCreated();

        $order = Order::firstOrFail();
        $item = $order->items()->firstOrFail();

        $this->assertSame(170000, $order->total);
        $this->assertSame(170000, $order->subtotal);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(2, $item->quantity);
        $this->assertSame(85000, $item->unit_price);
        $this->assertSame('Pizza Margherita', $item->product_name);
        $this->assertSame('Large', $item->variant_name);
        $this->assertDatabaseHas('order_item_modifiers', [
            'order_item_id' => $item->id,
            'modifier_name' => 'Extra Cheese',
            'price_delta' => 10000,
        ]);
        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'queued',
            'actor_id' => $cashier->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order.created',
            'target_id' => $order->id,
            'actor_id' => $cashier->id,
        ]);
    }

    public function test_cashier_cannot_create_order_for_another_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branchA)->create();
        [$product, $large, $cheese] = $this->pizzaForBranch($branchB);

        $this->actingAs($cashier)->post('/pos/orders', [
            'branch_id' => $branchB->id,
            'channel' => 'takeaway',
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $large->id,
                'modifier_ids' => [$cheese->id],
                'quantity' => 1,
            ]],
            'idempotency_key' => 'checkout-003',
        ])->assertForbidden();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_same_idempotency_key_returns_same_order(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        [$product, $large, $cheese] = $this->pizzaForBranch($branch);

        $payload = [
            'branch_id' => $branch->id,
            'channel' => 'takeaway',
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $large->id,
                'modifier_ids' => [$cheese->id],
                'quantity' => 1,
            ]],
            'idempotency_key' => 'checkout-002',
        ];

        $first = $this->actingAs($cashier)->post('/pos/orders', $payload)
            ->assertCreated()
            ->json('order.id');
        $second = $this->actingAs($cashier)->post('/pos/orders', $payload)
            ->assertOk()
            ->json('order.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_cashier_omitting_branch_uses_own_branch(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        [$product, $large, $cheese] = $this->pizzaForBranch($branch);

        $response = $this->actingAs($cashier)->post('/pos/orders', [
            'channel' => 'takeaway',
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $large->id,
                'modifier_ids' => [$cheese->id],
                'quantity' => 1,
            ]],
            'idempotency_key' => 'checkout-own-branch',
        ])->assertCreated();

        $this->assertSame($branch->id, $response->json('order.branch_id'));
    }

    public function test_owner_can_create_order_for_a_chosen_branch(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        [$product, $large, $cheese] = $this->pizzaForBranch($branch);

        $this->actingAs($owner)->post('/pos/orders', [
            'branch_id' => $branch->id,
            'channel' => 'takeaway',
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $large->id,
                'modifier_ids' => [$cheese->id],
                'quantity' => 1,
            ]],
            'idempotency_key' => 'checkout-owner',
        ])->assertCreated();

        $this->assertDatabaseHas('orders', ['branch_id' => $branch->id]);
    }

    public function test_cashier_can_open_pos_and_kitchen_cannot(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('pos/Index'));

        $this->actingAs($kitchen)->get('/pos')->assertForbidden();
    }

    public function test_kitchen_cannot_create_orders(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();

        $this->actingAs($kitchen)->post('/pos/orders', [
            'branch_id' => $branch->id,
            'channel' => 'takeaway',
            'items' => [],
            'idempotency_key' => 'kitchen-try',
        ])->assertForbidden();
    }

    public function test_product_must_be_available_at_the_order_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branchB)->create();
        [$product, $large, $cheese] = $this->pizzaForBranch($branchA);

        $this->actingAs($cashier)->post('/pos/orders', [
            'branch_id' => $branchB->id,
            'channel' => 'takeaway',
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $large->id,
                'modifier_ids' => [$cheese->id],
                'quantity' => 1,
            ]],
            'idempotency_key' => 'checkout-unavailable',
        ])->assertSessionHasErrors('items.0.product_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_required_modifier_group_must_be_selected(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['base_price' => 50000]);
        $group = ModifierGroup::factory()->create([
            'name' => 'Crust',
            'min_select' => 1,
            'max_select' => 1,
            'is_required' => true,
        ]);
        $group->modifiers()->create(['name' => 'Thin', 'price_delta' => 0]);
        $product->modifierGroups()->attach($group);
        $product->branches()->attach($branch, ['is_available' => true]);

        $this->actingAs($cashier)->post('/pos/orders', [
            'branch_id' => $branch->id,
            'channel' => 'takeaway',
            'items' => [[
                'product_id' => $product->id,
                'modifier_ids' => [],
                'quantity' => 1,
            ]],
            'idempotency_key' => 'checkout-required-modifier',
        ])->assertSessionHasErrors('items.0.modifier_ids');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_modifier_group_maximum_cannot_be_exceeded(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['base_price' => 50000]);
        $group = ModifierGroup::factory()->create([
            'name' => 'Topping',
            'min_select' => 0,
            'max_select' => 1,
            'is_required' => false,
        ]);
        $cheese = $group->modifiers()->create(['name' => 'Cheese', 'price_delta' => 10000]);
        $pepperoni = $group->modifiers()->create(['name' => 'Pepperoni', 'price_delta' => 15000]);
        $product->modifierGroups()->attach($group);
        $product->branches()->attach($branch, ['is_available' => true]);

        $this->actingAs($cashier)->post('/pos/orders', [
            'branch_id' => $branch->id,
            'channel' => 'takeaway',
            'items' => [[
                'product_id' => $product->id,
                'modifier_ids' => [$cheese->id, $pepperoni->id],
                'quantity' => 1,
            ]],
            'idempotency_key' => 'checkout-max-modifier',
        ])->assertSessionHasErrors('items.0.modifier_ids');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_variant_must_belong_to_product(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['base_price' => 50000]);
        $otherProduct = Product::factory()->for($category)->create();
        $foreignVariant = $otherProduct->variants()->create([
            'name' => 'Large',
            'price' => 75000,
        ]);
        $product->branches()->attach($branch, ['is_available' => true]);

        $this->actingAs($cashier)->post('/pos/orders', [
            'branch_id' => $branch->id,
            'channel' => 'takeaway',
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $foreignVariant->id,
                'quantity' => 1,
            ]],
            'idempotency_key' => 'checkout-foreign-variant',
        ])->assertSessionHasErrors('items.0.variant_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_quantity_outside_range_is_rejected(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        [$product] = $this->pizzaForBranch($branch);

        $this->actingAs($cashier)->post('/pos/orders', [
            'branch_id' => $branch->id,
            'channel' => 'takeaway',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 0,
            ]],
            'idempotency_key' => 'checkout-zero-quantity',
        ])->assertSessionHasErrors('items.0.quantity');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_product_in_inactive_category_cannot_be_ordered(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        $category = Category::factory()->create(['is_active' => false]);
        $product = Product::factory()->for($category)->create(['base_price' => 50000]);
        $product->branches()->attach($branch, ['is_available' => true]);

        $this->actingAs($cashier)->post('/pos/orders', [
            'branch_id' => $branch->id,
            'channel' => 'takeaway',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
            'idempotency_key' => 'checkout-inactive-category',
        ])->assertSessionHasErrors('items.0.product_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_product_without_active_variants_uses_base_price(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create([
            'name' => 'Pizza Polos',
            'base_price' => 50000,
        ]);
        $product->branches()->attach($branch, ['is_available' => true]);

        $this->actingAs($cashier)->post('/pos/orders', [
            'branch_id' => $branch->id,
            'channel' => 'takeaway',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
            ]],
            'idempotency_key' => 'checkout-base-price',
        ])->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame(100000, $order->total);
        $this->assertSame(50000, $order->items()->firstOrFail()->unit_price);
    }

    /**
     * @return array{0: Product, 1: ProductVariant, 2: Modifier}
     */
    private function pizzaForBranch(Branch $branch): array
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create([
            'name' => 'Pizza Margherita',
            'base_price' => 50000,
        ]);
        $large = $product->variants()->create([
            'name' => 'Large',
            'price' => 75000,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $group = ModifierGroup::factory()->create([
            'name' => 'Cheese',
            'min_select' => 0,
            'max_select' => 1,
            'is_required' => false,
        ]);
        $cheese = $group->modifiers()->create([
            'name' => 'Extra Cheese',
            'price_delta' => 10000,
            'is_active' => true,
        ]);
        $product->modifierGroups()->attach($group);
        $product->branches()->attach($branch, ['is_available' => true]);

        return [$product, $large, $cheese];
    }
}
