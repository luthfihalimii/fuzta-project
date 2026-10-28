<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\UnitConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class UnitAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_cannot_open_units_page(): void
    {
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)->get('/admin/stock/units')->assertForbidden();
    }

    public function test_owner_sees_units_page_with_props(): void
    {
        $owner = User::factory()->owner()->create();
        $unit = Unit::factory()->create();

        $this->actingAs($owner)
            ->get('/admin/stock/units')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/stock/Units')
                ->has('units', 1)
                ->has('conversions', 0));

        $this->assertNotNull($unit);
    }

    public function test_owner_can_store_unit(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/admin/stock/units', [
            'name' => 'Kilogram',
            'code' => 'kg',
        ])->assertRedirect();

        $this->assertDatabaseHas('units', ['name' => 'Kilogram', 'code' => 'kg']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'unit.created',
            'target_type' => Unit::class,
        ]);
    }

    public function test_store_unit_rejects_duplicate_code(): void
    {
        $owner = User::factory()->owner()->create();
        Unit::factory()->create(['code' => 'kg']);

        $this->actingAs($owner)
            ->from('/admin/stock/units')
            ->post('/admin/stock/units', [
                'name' => 'Kilo lain',
                'code' => 'kg',
            ])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Unit::count());
    }

    public function test_owner_can_update_unit_name_but_not_code(): void
    {
        $owner = User::factory()->owner()->create();
        $unit = Unit::factory()->create(['name' => 'Kilogram', 'code' => 'kg']);

        $this->actingAs($owner)->patch("/admin/stock/units/{$unit->id}", [
            'name' => 'Kilogram (gram base)',
            'code' => 'HACKED',
        ])->assertRedirect();

        $unit->refresh();
        $this->assertSame('Kilogram (gram base)', $unit->name);
        $this->assertSame('kg', $unit->code);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'unit.updated',
            'target_type' => Unit::class,
            'target_id' => $unit->id,
        ]);
    }

    public function test_owner_can_store_conversion(): void
    {
        $owner = User::factory()->owner()->create();
        $kg = Unit::factory()->create(['code' => 'kg']);
        $g = Unit::factory()->create(['code' => 'g']);

        $this->actingAs($owner)->post('/admin/stock/unit-conversions', [
            'from_unit_id' => $kg->id,
            'to_unit_id' => $g->id,
            'from_quantity' => 1,
            'to_quantity' => 1000,
        ])->assertRedirect();

        $this->assertDatabaseHas('unit_conversions', [
            'from_unit_id' => $kg->id,
            'to_unit_id' => $g->id,
            'from_quantity' => 1,
            'to_quantity' => 1000,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'unit_conversion.created',
            'target_type' => UnitConversion::class,
        ]);
    }

    public function test_store_conversion_rejects_same_from_and_to_unit(): void
    {
        $owner = User::factory()->owner()->create();
        $kg = Unit::factory()->create(['code' => 'kg']);

        $this->actingAs($owner)
            ->from('/admin/stock/units')
            ->post('/admin/stock/unit-conversions', [
                'from_unit_id' => $kg->id,
                'to_unit_id' => $kg->id,
                'from_quantity' => 1,
                'to_quantity' => 1,
            ])
            ->assertSessionHasErrors('to_unit_id');

        $this->assertSame(0, UnitConversion::count());
    }

    public function test_store_conversion_rejects_exact_direction_duplicate(): void
    {
        $owner = User::factory()->owner()->create();
        $kg = Unit::factory()->create(['code' => 'kg']);
        $g = Unit::factory()->create(['code' => 'g']);

        UnitConversion::create([
            'from_unit_id' => $kg->id,
            'to_unit_id' => $g->id,
            'from_quantity' => 1,
            'to_quantity' => 1000,
        ]);

        $this->actingAs($owner)
            ->from('/admin/stock/units')
            ->post('/admin/stock/unit-conversions', [
                'from_unit_id' => $kg->id,
                'to_unit_id' => $g->id,
                'from_quantity' => 1,
                'to_quantity' => 1000,
            ])
            ->assertSessionHasErrors('to_unit_id');

        $this->assertSame(1, UnitConversion::count());
    }

    public function test_store_conversion_rejects_reverse_direction_duplicate(): void
    {
        $owner = User::factory()->owner()->create();
        $kg = Unit::factory()->create(['code' => 'kg']);
        $g = Unit::factory()->create(['code' => 'g']);

        UnitConversion::create([
            'from_unit_id' => $g->id,
            'to_unit_id' => $kg->id,
            'from_quantity' => 1000,
            'to_quantity' => 1,
        ]);

        $this->actingAs($owner)
            ->from('/admin/stock/units')
            ->post('/admin/stock/unit-conversions', [
                'from_unit_id' => $kg->id,
                'to_unit_id' => $g->id,
                'from_quantity' => 1,
                'to_quantity' => 1000,
            ])
            ->assertSessionHasErrors('to_unit_id');

        $this->assertStringContainsString(
            'Konversi arah ini sudah ada.',
            session('errors')->first('to_unit_id'),
        );
        $this->assertSame(1, UnitConversion::count());
    }

    public function test_store_conversion_rejects_zero_and_negative_quantities(): void
    {
        $owner = User::factory()->owner()->create();
        $kg = Unit::factory()->create(['code' => 'kg']);
        $g = Unit::factory()->create(['code' => 'g']);

        $base = [
            'from_unit_id' => $kg->id,
            'to_unit_id' => $g->id,
            'from_quantity' => 1,
            'to_quantity' => 1000,
        ];

        $this->actingAs($owner)
            ->from('/admin/stock/units')
            ->post('/admin/stock/unit-conversions', [...$base, 'from_quantity' => 0])
            ->assertSessionHasErrors('from_quantity');

        $this->actingAs($owner)
            ->from('/admin/stock/units')
            ->post('/admin/stock/unit-conversions', [...$base, 'to_quantity' => -5])
            ->assertSessionHasErrors('to_quantity');

        $this->assertSame(0, UnitConversion::count());
    }

    public function test_delete_unit_used_by_ingredient_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();
        $unit = Unit::factory()->create();
        Ingredient::factory()->create(['base_unit_id' => $unit->id]);

        $this->actingAs($owner)
            ->from('/admin/stock/units')
            ->delete("/admin/stock/units/{$unit->id}")
            ->assertSessionHasErrors('unit');

        $this->assertStringContainsString(
            'Satuan sudah terpakai.',
            session('errors')->first('unit'),
        );
        $this->assertDatabaseHas('units', ['id' => $unit->id]);
    }

    public function test_delete_unit_used_by_stock_movement_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();
        $unit = Unit::factory()->create();
        $branch = Branch::factory()->create();
        $ingredient = Ingredient::factory()->create();

        StockMovement::create([
            'branch_id' => $branch->id,
            'ingredient_id' => $ingredient->id,
            'unit_id' => $unit->id,
            'quantity' => 100,
            'type' => StockMovementType::Adjustment,
            'reason' => 'Tes penggunaan satuan',
            'actor_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->from('/admin/stock/units')
            ->delete("/admin/stock/units/{$unit->id}")
            ->assertSessionHasErrors('unit');

        $this->assertDatabaseHas('units', ['id' => $unit->id]);
    }

    public function test_delete_unit_used_by_conversion_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();
        $unit = Unit::factory()->create();
        $other = Unit::factory()->create();

        UnitConversion::create([
            'from_unit_id' => $other->id,
            'to_unit_id' => $unit->id,
            'from_quantity' => 1000,
            'to_quantity' => 1,
        ]);

        $this->actingAs($owner)
            ->from('/admin/stock/units')
            ->delete("/admin/stock/units/{$unit->id}")
            ->assertSessionHasErrors('unit');

        $this->assertDatabaseHas('units', ['id' => $unit->id]);
    }

    public function test_owner_can_delete_clean_unit(): void
    {
        $owner = User::factory()->owner()->create();
        $unit = Unit::factory()->create();

        $this->actingAs($owner)
            ->delete("/admin/stock/units/{$unit->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('units', ['id' => $unit->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'unit.deleted',
            'target_type' => Unit::class,
            'target_id' => $unit->id,
        ]);
    }

    public function test_owner_can_delete_conversion(): void
    {
        $owner = User::factory()->owner()->create();
        $conversion = UnitConversion::factory()->create();

        $this->actingAs($owner)
            ->delete("/admin/stock/unit-conversions/{$conversion->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('unit_conversions', ['id' => $conversion->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'unit_conversion.deleted',
            'target_type' => UnitConversion::class,
            'target_id' => $conversion->id,
        ]);
    }
}
