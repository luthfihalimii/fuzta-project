<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DispatchStockTransfer
{
    public function __construct(
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(User $actor, int $transferId): StockTransfer
    {
        return DB::transaction(function () use ($actor, $transferId): StockTransfer {
            $transfer = StockTransfer::query()
                ->whereKey($transferId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->status !== TransferStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya transfer draft yang dapat dikirim.',
                ]);
            }

            $items = $transfer->items()->orderBy('ingredient_id')->get();

            $ingredients = Ingredient::query()
                ->findMany($items->pluck('ingredient_id'))
                ->keyBy('id');

            $now = now();

            foreach ($items as $item) {
                $ingredient = $ingredients->get((int) $item->ingredient_id);
                $quantity = (int) $item->quantity;

                StockBalance::query()->insertOrIgnore([
                    'branch_id' => $transfer->origin_branch_id,
                    'ingredient_id' => $item->ingredient_id,
                    'quantity' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $balance = StockBalance::query()
                    ->where('branch_id', $transfer->origin_branch_id)
                    ->where('ingredient_id', $item->ingredient_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $after = (int) $balance->quantity - $quantity;

                if ($after < 0) {
                    throw ValidationException::withMessages([
                        'stock' => "Stok {$ingredient->name} tidak cukup untuk transfer.",
                    ]);
                }

                $balance->update(['quantity' => $after]);

                StockMovement::create([
                    'branch_id' => $transfer->origin_branch_id,
                    'ingredient_id' => $item->ingredient_id,
                    'unit_id' => $ingredient->base_unit_id,
                    'quantity' => -$quantity,
                    'type' => StockMovementType::TransferOut,
                    'reason' => "Pengiriman transfer #{$transfer->id}",
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $transfer->id,
                    'actor_id' => $actor->id,
                ]);
            }

            $transfer->update([
                'status' => TransferStatus::Dispatched,
                'dispatched_at' => $now,
            ]);

            $transfer->load(['originBranch', 'destinationBranch', 'items.ingredient']);

            ($this->writeAuditLog)(
                $actor,
                'transfer.dispatched',
                $transfer,
                ['status' => TransferStatus::Draft->value],
                [
                    'status' => TransferStatus::Dispatched->value,
                    'origin' => ['id' => $transfer->originBranch->id, 'name' => $transfer->originBranch->name],
                    'destination' => ['id' => $transfer->destinationBranch->id, 'name' => $transfer->destinationBranch->name],
                    'items' => $transfer->items
                        ->map(fn ($item) => [
                            'ingredient' => $item->ingredient?->name,
                            'quantity' => $item->quantity,
                        ])
                        ->values()
                        ->all(),
                ],
            );

            return $transfer;
        });
    }
}
