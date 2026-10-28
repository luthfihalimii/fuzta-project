<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Kitchen\Actions\TransitionOrderStatus;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderConsumptionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_preparing_consumes_stock_per_ingredient(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();
        $ingredientA = Ingredient::factory()->create(['name' => 'Tepung']);
        $ingredientB = Ingredient::factory()->create(['name' => 'Saus']);
        $product = Product::factory()->create();
        $this->recipeFor($product, [$ingredientA->id => 2, $ingredientB->id => 5]);
        $order = $this->paidOrder($branch, $product, 3);
        StockBalance::factory()->for($branch)->for($ingredientA, 'ingredient')->create(['quantity' => 100]);
        StockBalance::factory()->for($branch)->for($ingredientB, 'ingredient')->create(['quantity' => 100]);

        $this->transition($order, $kitchen, 'accepted')->assertOk();
        $this->transition($order, $kitchen, 'preparing')
            ->assertOk()
            ->assertJsonPath('order.status', 'preparing');

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);

        $movementA = StockMovement::query()
            ->where('ingredient_id', $ingredientA->id)
            ->where('type', StockMovementType::Consumption)
            ->sole();

        $this->assertSame(-6, $movementA->quantity);
        $this->assertSame($branch->id, $movementA->branch_id);
        $this->assertSame($ingredientA->base_unit_id, $movementA->unit_id);
        $this->assertSame(Order::class, $movementA->reference_type);
        $this->assertSame($order->id, $movementA->reference_id);
        $this->assertSame($kitchen->id, $movementA->actor_id);
        $this->assertSame("Konsumsi pesanan {$order->order_number}", $movementA->reason);

        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $ingredientB->id,
            'type' => 'consumption',
            'quantity' => -15,
            'unit_id' => $ingredientB->base_unit_id,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'actor_id' => $kitchen->id,
        ]);

        $this->assertSame(94, $this->balanceOf($branch, $ingredientA));
        $this->assertSame(85, $this->balanceOf($branch, $ingredientB));
    }

    public function test_order_without_recipe_transitions_without_consumption(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();
        $product = Product::factory()->create();
        $order = $this->paidOrder($branch, $product, 2);

        $this->transition($order, $kitchen, 'accepted')->assertOk();
        $this->transition($order, $kitchen, 'preparing')->assertOk();

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_cancel_queued_unpaid_order_creates_no_consumption(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        $ingredient = Ingredient::factory()->create();
        $product = Product::factory()->create();
        $this->recipeFor($product, [$ingredient->id => 4]);
        $order = Order::factory()->unpaid()->for($branch)->create();
        OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2]);

        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/cancel", ['reason' => 'Customer batal'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(0, StockMovement::where('type', StockMovementType::Consumption)->count());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_void_after_preparing_reverses_consumption(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Tepung']);
        $product = Product::factory()->create();
        $this->recipeFor($product, [$ingredient->id => 2]);
        $order = $this->paidOrder($branch, $product, 3);
        StockBalance::factory()->for($branch)->for($ingredient, 'ingredient')->create(['quantity' => 100]);

        $this->transition($order, $kitchen, 'accepted')->assertOk();
        $this->transition($order, $kitchen, 'preparing')->assertOk();

        $this->assertSame(94, $this->balanceOf($branch, $ingredient));

        $this->actingAs($owner)
            ->post("/orders/{$order->id}/void", ['reason' => 'Salah input item'])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);

        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $ingredient->id,
            'type' => 'consumption',
            'quantity' => -6,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);

        $reversal = StockMovement::query()
            ->where('type', StockMovementType::ConsumptionReversal)
            ->sole();

        $this->assertSame(6, $reversal->quantity);
        $this->assertSame($branch->id, $reversal->branch_id);
        $this->assertSame($ingredient->id, $reversal->ingredient_id);
        $this->assertSame($ingredient->base_unit_id, $reversal->unit_id);
        $this->assertSame(Order::class, $reversal->reference_type);
        $this->assertSame($order->id, $reversal->reference_id);
        $this->assertSame($owner->id, $reversal->actor_id);
        $this->assertSame("Pembalikan konsumsi pesanan {$order->order_number} (void)", $reversal->reason);

        $this->assertSame(100, $this->balanceOf($branch, $ingredient));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_void_paid_queued_order_creates_no_reversal(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();
        $product = Product::factory()->create();
        $this->recipeFor($product, [$ingredient->id => 5]);
        $order = $this->paidOrder($branch, $product, 1);
        StockBalance::factory()->for($branch)->for($ingredient, 'ingredient')->create(['quantity' => 100]);

        $this->actingAs($owner)
            ->post("/orders/{$order->id}/void", ['reason' => 'Salah input'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(100, $this->balanceOf($branch, $ingredient));
    }

    public function test_insufficient_stock_rejects_preparing_and_rolls_back(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();
        $ingredientA = Ingredient::factory()->create(['name' => 'Tepung']);
        $ingredientB = Ingredient::factory()->create(['name' => 'Saus']);
        $product = Product::factory()->create();
        $this->recipeFor($product, [$ingredientA->id => 2, $ingredientB->id => 5]);
        $order = $this->paidOrder($branch, $product, 3);
        StockBalance::factory()->for($branch)->for($ingredientA, 'ingredient')->create(['quantity' => 5]);
        StockBalance::factory()->for($branch)->for($ingredientB, 'ingredient')->create(['quantity' => 100]);

        $this->transition($order, $kitchen, 'accepted')->assertOk();

        $this->transition($order, $kitchen, 'preparing')
            ->assertStatus(422)
            ->assertJsonPath('errors.stock.0', 'Stok Tepung tidak cukup untuk memulai pesanan.');

        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(5, $this->balanceOf($branch, $ingredientA));
        $this->assertSame(100, $this->balanceOf($branch, $ingredientB));
    }

    public function test_double_preparing_is_rejected_without_double_consumption(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();
        $ingredient = Ingredient::factory()->create();
        $product = Product::factory()->create();
        $this->recipeFor($product, [$ingredient->id => 2]);
        $order = $this->paidOrder($branch, $product, 3);
        StockBalance::factory()->for($branch)->for($ingredient, 'ingredient')->create(['quantity' => 100]);

        $this->transition($order, $kitchen, 'accepted')->assertOk();
        $this->transition($order, $kitchen, 'preparing')->assertOk();

        $this->transition($order, $kitchen, 'preparing')->assertStatus(422);

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Consumption)->count());
        $this->assertSame(94, $this->balanceOf($branch, $ingredient));
    }

    public function test_concurrent_preparing_only_one_order_consumes(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();
        $ingredient = Ingredient::factory()->create();
        $product = Product::factory()->create();
        $this->recipeFor($product, [$ingredient->id => 5]);
        StockBalance::factory()->for($branch)->for($ingredient, 'ingredient')->create(['quantity' => 8]);

        $orderA = $this->paidOrder($branch, $product, 1, OrderStatus::Accepted);
        $orderB = $this->paidOrder($branch, $product, 1, OrderStatus::Accepted);

        $pids = [];

        foreach ([$orderA, $orderB] as $forkOrder) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                DB::purge();
                DB::reconnect();

                try {
                    app(TransitionOrderStatus::class)(
                        Order::findOrFail($forkOrder->id),
                        User::findOrFail($kitchen->id),
                        OrderStatus::Preparing,
                    );
                } catch (ValidationException) {
                    exit(1);
                }

                exit(0);
            }

            $pids[] = $pid;
        }

        $exitCodes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        sort($exitCodes);

        $this->assertSame([0, 1], $exitCodes);
        $this->assertSame(3, $this->balanceOf($branch, $ingredient));
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Consumption)->count());
    }

    /**
     * @param  array<int, int>  $quantities  ingredient_id => quantity per unit
     */
    private function recipeFor(Product $product, array $quantities): Recipe
    {
        $recipe = Recipe::factory()->for($product, 'recipeable')->create();

        foreach ($quantities as $ingredientId => $quantity) {
            $recipe->items()->create(['ingredient_id' => $ingredientId, 'quantity' => $quantity]);
        }

        return $recipe;
    }

    private function paidOrder(
        Branch $branch,
        Product $product,
        int $quantity,
        OrderStatus $status = OrderStatus::Queued,
    ): Order {
        $order = Order::factory()->paid()->for($branch)->create(['status' => $status]);
        OrderItem::factory()->for($order)->for($product)->create(['quantity' => $quantity]);

        return $order;
    }

    private function transition(Order $order, User $actor, string $status): TestResponse
    {
        return $this->actingAs($actor)
            ->patchJson("/kitchen/orders/{$order->id}/status", ['status' => $status]);
    }

    private function balanceOf(Branch $branch, Ingredient $ingredient): int
    {
        return (int) StockBalance::query()
            ->where('branch_id', $branch->id)
            ->where('ingredient_id', $ingredient->id)
            ->value('quantity');
    }
}
