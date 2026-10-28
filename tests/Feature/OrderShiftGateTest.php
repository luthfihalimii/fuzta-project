<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Ordering\Models\Order;
use App\Modules\Shifts\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderShiftGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Bekukan jam ke 10:00 WIB hari berjalan: window factory 09:00–15:00 tetap
        // mencakup now(), sehingga gate cutoff tidak bergantung wall-clock.
        CarbonImmutable::setTestNow(CarbonImmutable::now('Asia/Jakarta')->startOfDay()->addHours(10));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function productFor(Branch $branch): Product
    {
        $product = Product::factory()->for(Category::factory())->create(['base_price' => 50000]);
        $product->branches()->attach($branch, ['is_available' => true]);

        return $product;
    }

    private function payload(int $productId, string $key): array
    {
        return [
            'channel' => 'takeaway',
            'items' => [['product_id' => $productId, 'quantity' => 1]],
            'idempotency_key' => $key,
        ];
    }

    public function test_order_without_shift_is_rejected(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        $product = $this->productFor($branch);

        $this->actingAs($cashier)->postJson('/pos/orders', $this->payload($product->id, 'key-1'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift');

        $this->assertSame(0, Order::count());
    }

    public function test_order_gets_shift_id(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $product = $this->productFor($shift->branch);

        $this->actingAs($shift->user)->postJson('/pos/orders', $this->payload($product->id, 'key-2'))
            ->assertCreated();

        $this->assertSame($shift->id, Order::firstOrFail()->shift_id);
    }

    public function test_order_after_cutoff_is_rejected(): void
    {
        $shift = CashierShift::factory()->open()->create([
            'scheduled_end_at' => CarbonImmutable::now()->subMinutes(16),
        ]);
        $product = $this->productFor($shift->branch);

        $this->actingAs($shift->user)->postJson('/pos/orders', $this->payload($product->id, 'key-3'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift');
    }

    public function test_owner_needs_shift_in_target_branch(): void
    {
        $owner = User::factory()->owner()->create();
        $branch = Branch::factory()->create();
        $product = $this->productFor($branch);

        $this->actingAs($owner)->postJson('/pos/orders', [
            ...$this->payload($product->id, 'key-4'),
            'branch_id' => $branch->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('shift');
    }

    public function test_orders_page_lists_active_shift_orders_across_dates(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $old = Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $shift->id,
            'placed_by_id' => $shift->user_id,
        ]);
        $old->forceFill(['created_at' => now()->subDay()])->save();

        $this->actingAs($shift->user)->get('/pos/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos/Orders')
                ->where('shift.id', $shift->id)
                ->has('orders', 1)
                ->where('orders.0.id', $old->id));
    }

    public function test_owner_can_view_orders_for_a_specific_shift(): void
    {
        $shift = CashierShift::factory()->closed()->create();
        $order = Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $shift->id,
        ]);
        $order->forceFill(['created_at' => now()->subDays(3)])->save();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get("/pos/orders?branch={$shift->branch_id}&shift={$shift->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('orders', 1)
                ->where('orders.0.id', $order->id));
    }
}
