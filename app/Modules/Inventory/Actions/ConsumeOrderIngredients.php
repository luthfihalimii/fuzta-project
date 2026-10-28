<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Ordering\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsumeOrderIngredients
{
    public function __construct(
        private readonly ResolveOrderRequirements $resolveRequirements,
    ) {}

    public function __invoke(Order $order, User $actor): void
    {
        $requirements = ($this->resolveRequirements)($order->loadMissing(['items.modifiers']));

        if ($requirements->isEmpty()) {
            return;
        }

        $ingredients = Ingredient::query()
            ->findMany($requirements->keys())
            ->keyBy('id');

        DB::transaction(function () use ($order, $actor, $requirements, $ingredients): void {
            foreach ($requirements->sortKeys() as $ingredientId => $quantity) {
                $ingredient = $ingredients->get((int) $ingredientId);
                $quantity = (int) $quantity;
                $now = now();

                StockBalance::query()->insertOrIgnore([
                    'branch_id' => $order->branch_id,
                    'ingredient_id' => $ingredientId,
                    'quantity' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $balance = StockBalance::query()
                    ->where('branch_id', $order->branch_id)
                    ->where('ingredient_id', $ingredientId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $after = (int) $balance->quantity - $quantity;

                if ($after < 0) {
                    throw ValidationException::withMessages([
                        'stock' => "Stok {$ingredient->name} tidak cukup untuk memulai pesanan.",
                    ]);
                }

                $balance->update(['quantity' => $after]);

                StockMovement::create([
                    'branch_id' => $order->branch_id,
                    'ingredient_id' => $ingredientId,
                    'unit_id' => $ingredient->base_unit_id,
                    'quantity' => -$quantity,
                    'type' => StockMovementType::Consumption,
                    'reason' => "Konsumsi pesanan {$order->order_number}",
                    'reference_type' => Order::class,
                    'reference_id' => $order->id,
                    'actor_id' => $actor->id,
                ]);
            }
        });
    }
}
