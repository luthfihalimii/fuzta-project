<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Ordering\Models\Order;
use Illuminate\Support\Facades\DB;

class ReverseOrderConsumption
{
    public function __invoke(Order $order, User $actor): void
    {
        DB::transaction(function () use ($order, $actor): void {
            $movements = StockMovement::query()
                ->where('reference_type', Order::class)
                ->where('reference_id', $order->id)
                ->where('type', StockMovementType::Consumption)
                ->orderBy('ingredient_id')
                ->orderBy('id')
                ->get();

            foreach ($movements as $movement) {
                $quantity = -(int) $movement->quantity;
                $now = now();

                StockBalance::query()->insertOrIgnore([
                    'branch_id' => $movement->branch_id,
                    'ingredient_id' => $movement->ingredient_id,
                    'quantity' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $balance = StockBalance::query()
                    ->where('branch_id', $movement->branch_id)
                    ->where('ingredient_id', $movement->ingredient_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $balance->update(['quantity' => (int) $balance->quantity + $quantity]);

                StockMovement::create([
                    'branch_id' => $movement->branch_id,
                    'ingredient_id' => $movement->ingredient_id,
                    'unit_id' => $movement->unit_id,
                    'quantity' => $quantity,
                    'type' => StockMovementType::ConsumptionReversal,
                    'reason' => "Pembalikan konsumsi pesanan {$order->order_number} (void)",
                    'reference_type' => Order::class,
                    'reference_id' => $order->id,
                    'actor_id' => $actor->id,
                ]);
            }
        });
    }
}
