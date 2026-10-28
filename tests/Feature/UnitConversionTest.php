<?php

namespace Tests\Feature;

use App\Modules\Inventory\Actions\ConvertUnit;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\UnitConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UnitConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_forward_and_reverse_conversion_use_stored_edge_both_ways(): void
    {
        $gram = Unit::factory()->create(['code' => 'g']);
        $kilogram = Unit::factory()->create(['code' => 'kg']);
        UnitConversion::factory()->create([
            'from_unit_id' => $kilogram->id,
            'to_unit_id' => $gram->id,
            'from_quantity' => 1,
            'to_quantity' => 1000,
        ]);

        $this->assertSame(3000, app(ConvertUnit::class)($kilogram->id, 3, $gram->id));
        $this->assertSame(0, app(ConvertUnit::class)($gram->id, 500, $kilogram->id));
    }

    public function test_same_unit_returns_quantity_unchanged(): void
    {
        $gram = Unit::factory()->create(['code' => 'g']);

        $this->assertSame(7, app(ConvertUnit::class)($gram->id, 7, $gram->id));
    }

    public function test_no_conversion_path_throws_validation_exception(): void
    {
        $gram = Unit::factory()->create(['code' => 'g']);
        $piece = Unit::factory()->create(['code' => 'pcs']);

        $this->expectException(ValidationException::class);

        app(ConvertUnit::class)($gram->id, 1, $piece->id);
    }
}
