<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\RecipeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeItem>
 */
class RecipeItemFactory extends Factory
{
    protected $model = RecipeItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'ingredient_id' => Ingredient::factory(),
            'quantity' => 100,
        ];
    }
}
