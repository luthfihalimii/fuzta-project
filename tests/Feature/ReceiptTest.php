<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Receipt\ReceiptPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_payload_uses_order_snapshots_after_catalog_change(): void
    {
        $product = Product::factory()->create(['name' => 'Pizza Margherita']);
        $modifier = Modifier::factory()->create(['name' => 'Extra Cheese']);
        $order = Order::factory()->paid()->create(['total' => 170000]);
        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_name' => 'Pizza Margherita',
            'variant_name' => 'Large',
            'unit_price' => 85000,
            'quantity' => 2,
            'line_total' => 170000,
        ]);
        $item->modifiers()->create([
            'modifier_id' => $modifier->id,
            'modifier_name' => 'Extra Cheese',
            'price_delta' => 0,
        ]);

        $product->update(['name' => 'Renamed']);
        $modifier->update(['name' => 'Renamed modifier']);

        $payload = ReceiptPayload::fromOrder($order->fresh('items.modifiers'));

        $this->assertSame('Pizza Margherita', $payload['items'][0]['product_name']);
        $this->assertSame('Extra Cheese', $payload['items'][0]['modifiers'][0]['modifier_name']);
        $this->assertSame(170000, $payload['total']);
    }

    public function test_receipt_payload_contains_branch_cashier_and_payment_details(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'Pizza Fruzta Pusat',
            'address' => 'Jl. Merdeka 1',
            'phone' => '021-123456',
        ]);
        $cashier = User::factory()->cashier()->for($branch)->create(['name' => 'Sari']);
        $product = Product::factory()->create();
        $modifier = Modifier::factory()->create();
        $order = Order::factory()->paid()->for($branch)->create([
            'placed_by_id' => $cashier->id,
            'channel' => OrderChannel::DineIn,
            'table_number' => 'A-04',
            'total' => 170000,
        ]);
        $order->payment->update([
            'method' => 'cash',
            'cash_received' => 200000,
            'change_amount' => 30000,
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_name' => 'Pizza Margherita',
            'variant_name' => 'Large',
            'unit_price' => 85000,
            'quantity' => 2,
            'line_total' => 170000,
            'note' => 'Tanpa bawang',
        ]);
        $item->modifiers()->create([
            'modifier_id' => $modifier->id,
            'modifier_name' => 'Extra Cheese',
            'price_delta' => 5000,
        ]);

        $payload = ReceiptPayload::fromOrder($order->fresh([
            'branch',
            'placedBy',
            'payment',
            'items.modifiers',
        ]));

        $this->assertSame('Pizza Fruzta Pusat', $payload['branch']['name']);
        $this->assertSame('Jl. Merdeka 1', $payload['branch']['address']);
        $this->assertSame('021-123456', $payload['branch']['phone']);
        $this->assertSame('dine_in', $payload['channel']);
        $this->assertSame('A-04', $payload['table_number']);
        $this->assertSame('Sari', $payload['cashier']);
        $this->assertSame('cash', $payload['payment_method']);
        $this->assertSame(200000, $payload['cash_received']);
        $this->assertSame(30000, $payload['change']);
        $this->assertSame('Tanpa bawang', $payload['items'][0]['note']);
    }

    public function test_cashier_can_view_own_branch_receipt_page(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();
        $order = Order::factory()->paid()->for($branch)->create(['total' => 170000]);

        $this->actingAs($cashier)
            ->get("/orders/{$order->id}/receipt")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('pos/Receipt')
                ->where('receipt.order_number', $order->order_number)
                ->where('receipt.total', 170000)
                ->has('receipt.items', 0));
    }

    public function test_cashier_cannot_view_another_branch_receipt(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branchA)->create();
        $order = Order::factory()->paid()->for($branchB)->create();

        $this->actingAs($cashier)
            ->get("/orders/{$order->id}/receipt")
            ->assertForbidden();
    }

    public function test_owner_can_view_any_branch_receipt(): void
    {
        $owner = User::factory()->owner()->create();
        $order = Order::factory()->paid()->create();

        $this->actingAs($owner)
            ->get("/orders/{$order->id}/receipt")
            ->assertOk();
    }

    public function test_kitchen_cannot_view_receipt(): void
    {
        $branch = Branch::factory()->create();
        $kitchen = User::factory()->kitchen()->for($branch)->create();
        $order = Order::factory()->paid()->for($branch)->create();

        $this->actingAs($kitchen)
            ->get("/orders/{$order->id}/receipt")
            ->assertForbidden();
    }

    public function test_unpaid_receipt_has_no_payment_details(): void
    {
        $order = Order::factory()->unpaid()->create(['total' => 170000]);
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        $payload = ReceiptPayload::fromOrder($order->fresh(['branch', 'placedBy', 'payment', 'items.modifiers']));

        $this->assertNull($payload['payment_method']);
        $this->assertNull($payload['cash_received']);
        $this->assertNull($payload['change']);

        $this->actingAs($cashier)
            ->get("/orders/{$order->id}/receipt")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('pos/Receipt')
                ->where('receipt.payment_method', null));
    }
}
