<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Purchasing\Models\Purchase;
use App\Modules\Purchasing\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseItem>
 */
class PurchaseItemFactory extends Factory
{
    protected $model = PurchaseItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_id' => Purchase::factory(),
            'ingredient_id' => Ingredient::factory(),
            'unit_id' => Unit::factory(),
            'quantity' => 1,
            'unit_price' => 0,
            'line_total' => 0,
        ];
    }
}
