<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\UnitConversion;
use App\Modules\Purchasing\Models\Supplier;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_suppliers_and_kg_to_g_conversion(): void
    {
        $this->seed(UnitSeeder::class);
        $this->seed(SupplierSeeder::class);

        $this->assertSame(2, Supplier::count());

        $this->assertDatabaseHas('suppliers', [
            'name' => 'CV Tepung Nusantara',
            'phone' => '081230000001',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('suppliers', [
            'name' => 'Susu Segar Jaya',
            'phone' => '081230000002',
            'is_active' => true,
        ]);

        $kg = Unit::where('code', 'kg')->firstOrFail();
        $g = Unit::where('code', 'g')->firstOrFail();

        $conversion = UnitConversion::where('from_unit_id', $kg->id)
            ->where('to_unit_id', $g->id)
            ->firstOrFail();

        $this->assertSame(1, $conversion->from_quantity);
        $this->assertSame(1000, $conversion->to_quantity);
    }
}
