<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransferItem>
 */
class StockTransferItemFactory extends Factory
{
    protected $model = StockTransferItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transfer_id' => StockTransfer::factory(),
            'ingredient_id' => Ingredient::factory(),
            'quantity' => 10,
        ];
    }
}
