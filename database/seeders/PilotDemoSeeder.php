<?php

namespace Database\Seeders;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Seeder;

class PilotDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $pizza = Category::query()->firstOrCreate(
            ['name' => 'Pizza'],
            ['sort_order' => 0, 'is_active' => true],
        );

        $category = Category::query()->firstOrCreate(
            ['name' => 'Minuman'],
            ['sort_order' => 1, 'is_active' => true],
        );

        $addons = ModifierGroup::query()->firstOrCreate(
            ['name' => 'Tambahan'],
            ['min_select' => 0, 'max_select' => 3, 'is_required' => false, 'sort_order' => 0],
        );

        Modifier::query()->updateOrCreate(
            ['modifier_group_id' => $addons->id, 'name' => 'Extra Cheese'],
            ['price_delta' => 10000, 'sort_order' => 0, 'is_active' => true],
        );

        Modifier::query()->updateOrCreate(
            ['modifier_group_id' => $addons->id, 'name' => 'Extra Pepperoni'],
            ['price_delta' => 15000, 'sort_order' => 1, 'is_active' => true],
        );

        $margherita = Product::query()->updateOrCreate(
            ['name' => 'Pizza Margherita'],
            [
                'category_id' => $pizza->id,
                'description' => 'Pizza tomat dan keju mozzarella.',
                'base_price' => 50000,
                'is_active' => true,
            ],
        );

        foreach ([
            ['name' => 'Reguler', 'price' => 50000, 'sort_order' => 0],
            ['name' => 'Besar', 'price' => 75000, 'sort_order' => 1],
        ] as $variant) {
            ProductVariant::query()->updateOrCreate(
                ['product_id' => $margherita->id, 'name' => $variant['name']],
                $variant + ['is_active' => true],
            );
        }

        $margherita->modifierGroups()->syncWithoutDetaching([
            $addons->id => ['sort_order' => 0],
        ]);

        foreach (Branch::query()->pluck('id') as $branchId) {
            $margherita->branches()->syncWithoutDetaching([
                $branchId => ['is_available' => true],
            ]);
        }

        $water = Product::query()->updateOrCreate(
            ['name' => 'Air Mineral'],
            [
                'category_id' => $category->id,
                'description' => null,
                'base_price' => 5000,
                'is_active' => true,
            ],
        );

        foreach (Branch::query()->pluck('id') as $branchId) {
            $water->branches()->syncWithoutDetaching([
                $branchId => ['is_available' => true],
            ]);
        }

        $units = Unit::query()->pluck('id', 'code');

        foreach ([
            ['name' => 'Keju Mozzarella', 'code' => 'g', 'threshold' => 500],
            ['name' => 'Tepung Terigu', 'code' => 'g', 'threshold' => 1000],
            ['name' => 'Saus Tomat', 'code' => 'ml', 'threshold' => 800],
        ] as $row) {
            Ingredient::query()->firstOrCreate(
                ['name' => $row['name']],
                [
                    'base_unit_id' => $units[$row['code']],
                    'low_stock_threshold' => $row['threshold'],
                ],
            );
        }
    }
}
