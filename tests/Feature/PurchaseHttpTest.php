<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\UnitConversion;
use App\Modules\Purchasing\Http\Controllers\PurchaseController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use App\Modules\Purchasing\Models\Purchase;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PurchaseHttpTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private User $kitchen;

    private User $cashier;

    private User $owner;

    private Supplier $supplier;

    private Unit $kg;

    private Unit $g;

    private Ingredient $ingredient;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->branchA, $this->branchB] = Branch::factory()->count(2)->create();
        $this->kitchen = User::factory()->kitchen()->for($this->branchA)->create();
        $this->cashier = User::factory()->cashier()->for($this->branchA)->create();
        $this->owner = User::factory()->owner()->create();
        $this->supplier = Supplier::factory()->create(['name' => 'CV Sumber Pangan']);
        $this->kg = Unit::factory()->create(['code' => 'kg', 'name' => 'Kilogram']);
        $this->g = Unit::factory()->create(['code' => 'g', 'name' => 'Gram']);
        UnitConversion::factory()->create([
            'from_unit_id' => $this->kg->id,
            'to_unit_id' => $this->g->id,
            'from_quantity' => 1,
            'to_quantity' => 1000,
        ]);
        $this->ingredient = Ingredient::factory()->create([
            'base_unit_id' => $this->g->id,
            'name' => 'Tepung',
        ]);
    }

    public function test_purchase_page_routes_are_registered(): void
    {
        // GET /200 coverage for these pages lands with the .vue files in Tasks 4–5.
        foreach ([
            'pos.purchases.create',
            'pos.purchases.store',
            'kitchen.purchases.create',
            'kitchen.purchases.store',
            'admin.purchases.index',
            'admin.purchases.create',
            'admin.purchases.store',
            'admin.purchases.show',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Route [{$name}] is not registered.");
        }

        $this->assertSame('admin/purchases/create', Route::getRoutes()->getByName('admin.purchases.create')->uri());
        $this->assertSame('admin/purchases/{purchase}', Route::getRoutes()->getByName('admin.purchases.show')->uri());
    }

    public function test_route_actions_and_registration_order(): void
    {
        foreach ([
            'pos.purchases.create' => PurchaseController::class.'@posCreate',
            'pos.purchases.store' => PurchaseController::class.'@store',
            'kitchen.purchases.create' => PurchaseController::class.'@kitchenCreate',
            'kitchen.purchases.store' => PurchaseController::class.'@store',
            'admin.purchases.index' => PurchaseController::class.'@index',
            'admin.purchases.create' => PurchaseController::class.'@create',
            'admin.purchases.store' => PurchaseController::class.'@store',
            'admin.purchases.show' => PurchaseController::class.'@show',
            'admin.suppliers.index' => SupplierController::class.'@index',
            'admin.suppliers.store' => SupplierController::class.'@store',
            'admin.suppliers.update' => SupplierController::class.'@update',
        ] as $name => $action) {
            $this->assertSame($action, Route::getRoutes()->getByName($name)?->getActionName(), "Route [{$name}] resolves to the wrong action.");
        }

        $names = array_map(
            fn ($route) => $route->getName(),
            Route::getRoutes()->getRoutes(),
        );

        $this->assertLessThan(
            array_search('admin.purchases.show', $names, true),
            array_search('admin.purchases.create', $names, true),
            'admin.purchases.create must be registered before admin.purchases.show',
        );
    }

    public function test_kitchen_form_and_submit_records_purchase_with_ledger_and_audit(): void
    {
        $this->actingAs($this->kitchen)
            ->post('/kitchen/purchases', $this->payload())
            ->assertRedirect();

        $purchase = Purchase::sole();
        $this->assertSame($this->branchA->id, $purchase->branch_id);
        $this->assertSame($this->supplier->id, $purchase->supplier_id);
        $this->assertSame($this->kitchen->id, $purchase->recorded_by);
        $this->assertSame(300000, $purchase->total);

        $movement = StockMovement::sole();
        $this->assertSame(StockMovementType::Receipt, $movement->type);
        $this->assertSame(25000, $movement->quantity);
        $this->assertSame(Purchase::class, $movement->reference_type);
        $this->assertSame($purchase->id, $movement->reference_id);

        $this->assertSame(25000, StockBalance::sole()->quantity);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'purchase.received',
            'target_type' => Purchase::class,
            'target_id' => $purchase->id,
            'actor_id' => $this->kitchen->id,
            'branch_id' => $this->branchA->id,
        ]);
    }

    public function test_cashier_submits_via_pos(): void
    {
        $this->actingAs($this->cashier)
            ->post('/pos/purchases', $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('purchases', [
            'branch_id' => $this->branchA->id,
            'recorded_by' => $this->cashier->id,
        ]);
    }

    public function test_owner_records_purchase_via_admin(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/purchases', $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('purchases', [
            'branch_id' => $this->branchA->id,
            'recorded_by' => $this->owner->id,
        ]);
    }

    public function test_show_returns_404_for_missing_purchase(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/purchases/999999')
            ->assertNotFound();
    }

    public static function forbiddenMatrix(): array
    {
        return [
            'kitchen cannot post via pos' => ['kitchen', 'post', '/pos/purchases', 403],
            'kitchen cannot post other branch' => ['kitchen', 'post', '/kitchen/purchases', 403],
            'cashier cannot open kitchen form' => ['cashier', 'get', '/kitchen/purchases', 403],
            'kitchen cannot open admin purchases' => ['kitchen', 'get', '/admin/purchases', 403],
            'cashier cannot open admin purchases' => ['cashier', 'get', '/admin/purchases', 403],
            'kitchen cannot open admin suppliers' => ['kitchen', 'get', '/admin/suppliers', 403],
        ];
    }

    #[DataProvider('forbiddenMatrix')]
    public function test_role_and_branch_matrix(string $role, string $method, string $uri, int $status): void
    {
        $user = $role === 'kitchen' ? $this->kitchen : $this->cashier;

        if ($method === 'post') {
            $branchId = $uri === '/kitchen/purchases' ? $this->branchB->id : $this->branchA->id;

            $this->actingAs($user)
                ->post($uri, $this->payload(['branch_id' => $branchId]))
                ->assertStatus($status);

            $this->assertDatabaseCount('purchases', 0);

            return;
        }

        $this->actingAs($user)->get($uri)->assertStatus($status);
    }

    public static function invalidPayloads(): array
    {
        return [
            'quantity zero' => ['quantity zero', 'items.0.quantity'],
            'quantity negative' => ['quantity negative', 'items.0.quantity'],
            'unit price negative' => ['unit price negative', 'items.0.unit_price'],
            'empty items' => ['empty items', 'items'],
            'duplicate ingredient' => ['duplicate ingredient', 'items'],
            'inactive supplier' => ['inactive supplier', 'supplier_id'],
            'received tomorrow' => ['received tomorrow', 'received_at'],
            'received 91 days ago' => ['received 91 days ago', 'received_at'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_failures(string $case, string $errorKey): void
    {
        $this->actingAs($this->kitchen)
            ->postJson('/kitchen/purchases', $this->invalidPayload($case))
            ->assertStatus(422)
            ->assertJsonValidationErrors($errorKey);

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_received_exactly_90_days_ago_is_accepted(): void
    {
        $this->actingAs($this->kitchen)
            ->post('/kitchen/purchases', $this->payload([
                'received_at' => now()->subDays(90)->toDateString(),
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_missing_supplier_is_404(): void
    {
        $this->actingAs($this->kitchen)
            ->post('/kitchen/purchases', $this->payload(['supplier_id' => 999999]))
            ->assertNotFound();

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_http_idempotent_replay(): void
    {
        $payload = $this->payload(['idempotency_key' => 'purchase-http-key-1']);

        $this->actingAs($this->kitchen)->post('/kitchen/purchases', $payload)->assertRedirect();
        $this->actingAs($this->kitchen)->post('/kitchen/purchases', $payload)->assertRedirect();

        $this->assertSame(1, Purchase::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(25000, StockBalance::sole()->quantity);
    }

    public function test_manual_receipt_still_owner_only(): void
    {
        $payload = [
            'ingredient_id' => $this->ingredient->id,
            'quantity' => 5,
            'type' => 'receipt',
            'reason' => 'Stok awal manual',
        ];

        $this->actingAs($this->owner)->post('/admin/stock/movements', $payload)->assertRedirect();
        $this->actingAs($this->kitchen)->post('/admin/stock/movements', $payload)->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branchA->id,
            'supplier_id' => $this->supplier->id,
            'received_at' => now()->toDateString(),
            'reference' => 'INV-2026-001',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [$this->line()],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{ingredient_id: int, unit_id: int, quantity: int, unit_price: int}
     */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'ingredient_id' => $this->ingredient->id,
            'unit_id' => $this->kg->id,
            'quantity' => 25,
            'unit_price' => 12000,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidPayload(string $case): array
    {
        return match ($case) {
            'quantity zero' => $this->payload(['items' => [$this->line(['quantity' => 0])]]),
            'quantity negative' => $this->payload(['items' => [$this->line(['quantity' => -5])]]),
            'unit price negative' => $this->payload(['items' => [$this->line(['unit_price' => -1])]]),
            'empty items' => $this->payload(['items' => []]),
            'duplicate ingredient' => $this->payload(['items' => [$this->line(), $this->line(['quantity' => 2])]]),
            'inactive supplier' => $this->payload([
                'supplier_id' => Supplier::factory()->create(['is_active' => false])->id,
            ]),
            'received tomorrow' => $this->payload(['received_at' => now()->addDay()->toDateString()]),
            'received 91 days ago' => $this->payload(['received_at' => now()->subDays(91)->toDateString()]),
        };
    }
}
