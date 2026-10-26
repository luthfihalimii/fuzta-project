<?php

namespace Database\Seeders;

use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['code' => 'g', 'name' => 'Gram'],
            ['code' => 'kg', 'name' => 'Kilogram'],
            ['code' => 'ml', 'name' => 'Mililiter'],
            ['code' => 'l', 'name' => 'Liter'],
            ['code' => 'pcs', 'name' => 'Buah'],
            ['code' => 'pack', 'name' => 'Pak'],
        ];

        foreach ($units as $unit) {
            Unit::query()->firstOrCreate(['code' => $unit['code']], ['name' => $unit['name']]);
        }
    }
}
