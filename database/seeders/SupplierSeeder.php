<?php

namespace Database\Seeders;

use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\UnitConversion;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::query()->firstOrCreate(
            ['name' => 'CV Tepung Nusantara'],
            ['phone' => '081230000001', 'is_active' => true],
        );

        Supplier::query()->firstOrCreate(
            ['name' => 'Susu Segar Jaya'],
            ['phone' => '081230000002', 'is_active' => true],
        );

        $kg = Unit::query()->where('code', 'kg')->firstOrFail();
        $g = Unit::query()->where('code', 'g')->firstOrFail();

        UnitConversion::query()->firstOrCreate(
            ['from_unit_id' => $kg->id, 'to_unit_id' => $g->id],
            ['from_quantity' => 1, 'to_quantity' => 1000],
        );
    }
}
