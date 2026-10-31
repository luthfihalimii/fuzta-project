<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Reporting\Queries\DailySalesQuery;
use App\Modules\Reporting\Queries\ProductSalesQuery;
use App\Modules\Reporting\Queries\StockReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_count_only_paid_non_voided_orders(): void
    {
        $branch = Branch::factory()->create();
        Order::factory()->paid()->for($branch)->create(['total' => 100000]);
        Order::factory()->unpaid()->for($branch)->create(['total' => 50000]);
        Order::factory()->voided()->for($branch)->create(['total' => 70000]);

        $result = app(DailySalesQuery::class)(
            CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
            CarbonImmutable::now('Asia/Jakarta')->endOfDay(),
            $branch->id,
        );

        $this->assertSame(1, $result['order_count']);
        $this->assertSame(100000, $result['gross_sales']);
        $this->assertSame(100000, $result['average_order_value']);
    }

    public function test_day_boundary_uses_asia_jakarta(): void
    {
        $branch = Branch::factory()->create();

        $beforeMidnight = Order::factory()->paid()->for($branch)->create([
            'total' => 100000,
        ]);
        $beforeMidnight->forceFill([
            'created_at' => CarbonImmutable::parse('2026-10-25 16:00:00', 'UTC'),
        ])->save();

        $afterMidnight = Order::factory()->paid()->for($branch)->create([
            'total' => 200000,
        ]);
        $afterMidnight->forceFill([
            'created_at' => CarbonImmutable::parse('2026-10-25 17:30:00', 'UTC'),
        ])->save();

        $result = app(DailySalesQuery::class)(
            CarbonImmutable::parse('2026-10-26', 'Asia/Jakarta')->startOfDay(),
            CarbonImmutable::parse('2026-10-26', 'Asia/Jakarta')->endOfDay(),
            $branch->id,
        );

        $this->assertSame(1, $result['order_count']);
        $this->assertSame(200000, $result['gross_sales']);
    }

    public function test_sales_can_be_filtered_by_branch_and_split_by_payment_method(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        Order::factory()->paid()->for($branchA)->create(['total' => 100000]);
        $qris = Order::factory()->paid()->for($branchA)->create(['total' => 50000]);
        $qris->payment->update(['method' => PaymentMethod::QrisStatic]);
        Order::factory()->paid()->for($branchB)->create(['total' => 90000]);

        $result = app(DailySalesQuery::class)(
            CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
            CarbonImmutable::now('Asia/Jakarta')->endOfDay(),
            $branchA->id,
        );

        $this->assertSame(2, $result['order_count']);
        $this->assertSame(150000, $result['gross_sales']);
        $this->assertSame(75000, $result['average_order_value']);
        $this->assertSame([
            [
                'method' => 'cash',
                'label' => 'Tunai',
                'order_count' => 1,
                'amount' => 100000,
            ],
            [
                'method' => 'qris_static',
                'label' => 'QRIS statis',
                'order_count' => 1,
                'amount' => 50000,
            ],
        ], $result['by_payment_method']);
    }

    public function test_daily_sales_includes_by_channel_breakdown_matching_gross_sales(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        Order::factory()->paid()->for($branchA)->create(['total' => 120000, 'channel' => OrderChannel::DineIn]);
        Order::factory()->paid()->for($branchA)->create(['total' => 80000]); // default factory: takeaway
        Order::factory()->paid()->for($branchB)->create(['total' => 90000]); // branch lain, jangan bocor
        Order::factory()->unpaid()->for($branchA)->create(['total' => 5000, 'channel' => OrderChannel::DineIn]);
        Order::factory()->voided()->for($branchA)->create(['total' => 7000, 'channel' => OrderChannel::DineIn]);

        $result = app(DailySalesQuery::class)(
            CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
            CarbonImmutable::now('Asia/Jakarta')->endOfDay(),
            $branchA->id,
        );

        $this->assertSame([
            ['channel' => 'dine_in', 'label' => 'Dine-in', 'order_count' => 1, 'amount' => 120000],
            ['channel' => 'takeaway', 'label' => 'Takeaway', 'order_count' => 1, 'amount' => 80000],
        ], $result['by_channel']);
        $this->assertSame(
            array_sum(array_column($result['by_channel'], 'amount')),
            $result['gross_sales'],
        );
    }

    public function test_by_channel_falls_back_to_raw_value_for_unknown_channel(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->paid()->for($branch)->create(['total' => 10000]);
        DB::table('orders')->where('id', $order->id)->update(['channel' => 'whatsapp']);

        $result = app(DailySalesQuery::class)(
            CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
            CarbonImmutable::now('Asia/Jakarta')->endOfDay(),
            $branch->id,
        );

        $this->assertSame([
            ['channel' => 'whatsapp', 'label' => 'whatsapp', 'order_count' => 1, 'amount' => 10000],
        ], $result['by_channel']);
    }

    public function test_by_channel_is_empty_array_when_no_qualifying_orders(): void
    {
        $branch = Branch::factory()->create();

        $result = app(DailySalesQuery::class)(
            CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
            CarbonImmutable::now('Asia/Jakarta')->endOfDay(),
            $branch->id,
        );

        $this->assertSame([], $result['by_channel']);
    }

    public function test_product_sales_only_include_paid_orders_and_weight_modifiers_by_item_quantity(): void
    {
        $branch = Branch::factory()->create();
        $pizza = Product::factory()->create(['name' => 'Pizza Keju']);
        $modifier = Modifier::factory()->create(['name' => 'Ekstra Keju']);

        $paid = Order::factory()->paid()->for($branch)->create(['total' => 120000]);
        $item = OrderItem::factory()->for($paid)->for($pizza)->create([
            'product_name' => 'Pizza Keju',
            'quantity' => 2,
            'line_total' => 120000,
        ]);
        $item->modifiers()->create([
            'modifier_id' => $modifier->id,
            'modifier_name' => 'Ekstra Keju',
            'price_delta' => 10000,
        ]);

        $unpaid = Order::factory()->unpaid()->for($branch)->create(['total' => 300000]);
        OrderItem::factory()->for($unpaid)->for($pizza)->create([
            'product_name' => 'Pizza Keju',
            'quantity' => 5,
            'line_total' => 300000,
        ]);

        $result = app(ProductSalesQuery::class)(
            CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
            CarbonImmutable::now('Asia/Jakarta')->endOfDay(),
            $branch->id,
        );

        $this->assertSame([[
            'product_id' => $pizza->id,
            'product_name' => 'Pizza Keju',
            'quantity' => 2,
            'line_total' => 120000,
        ]], $result['products']);
        $this->assertSame([[
            'modifier_name' => 'Ekstra Keju',
            'quantity' => 2,
            'revenue' => 20000,
        ]], $result['modifiers']);
    }

    public function test_stock_report_returns_balances_low_stock_and_movements_for_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $low = Ingredient::factory()->create([
            'name' => 'Mozzarella',
            'low_stock_threshold' => 100,
        ]);
        $healthy = Ingredient::factory()->create([
            'name' => 'Tepung',
            'low_stock_threshold' => 100,
        ]);

        app(RecordStockMovement::class)($owner, $branchA->id, $low->id, 25, StockMovementType::Opening, 'Stok awal');
        app(RecordStockMovement::class)($owner, $branchA->id, $healthy->id, 500, StockMovementType::Opening, 'Stok awal');
        app(RecordStockMovement::class)($owner, $branchB->id, $low->id, 900, StockMovementType::Opening, 'Stok awal');

        $result = app(StockReportQuery::class)($branchA->id);

        $this->assertCount(2, $result['balances']);
        $this->assertCount(1, $result['low_stock']);
        $this->assertSame($low->id, $result['low_stock'][0]['ingredient_id']);
        $this->assertSame(25, $result['low_stock'][0]['quantity']);
        $this->assertSame('Mozzarella', $result['low_stock'][0]['ingredient_name']);
        $this->assertCount(2, $result['movements']);
        $this->assertSame($branchA->id, $result['movements'][0]['branch_id']);
    }

    public function test_owner_can_view_sales_and_stock_report_pages(): void
    {
        $owner = User::factory()->owner()->create();
        $branch = Branch::factory()->create();
        $order = Order::factory()->paid()->for($branch)->create(['total' => 100000]);
        $product = Product::factory()->create(['name' => 'Pizza Fruzta']);
        OrderItem::factory()->for($order)->for($product)->create([
            'product_name' => 'Pizza Fruzta',
            'quantity' => 1,
            'line_total' => 100000,
        ]);

        $this->actingAs($owner)
            ->get('/admin/reports/sales')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/reports/Sales')
                ->where('sales.order_count', 1)
                ->where('sales.gross_sales', 100000)
                ->has('products', 1)
                ->where('products.0.product_name', 'Pizza Fruzta')
                ->has('branches')
            );

        $this->actingAs($owner)
            ->get('/admin/reports/stock')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/reports/Stock')
                ->has('balances')
                ->has('low_stock')
                ->has('movements')
            );
    }

    public function test_non_owner_cannot_view_reports_or_audit(): void
    {
        $cashier = User::factory()->cashier()->for(Branch::factory())->create();

        $this->actingAs($cashier)->get('/admin/reports/sales')->assertForbidden();
        $this->actingAs($cashier)->get('/admin/reports/stock')->assertForbidden();
        $this->actingAs($cashier)->get('/admin/audit')->assertForbidden();
    }
}
