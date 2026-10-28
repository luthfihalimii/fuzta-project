<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Support\Facades\DB;

class CreateStockTransfer
{
    public function __construct(
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(User $actor, array $data): StockTransfer
    {
        return DB::transaction(function () use ($actor, $data): StockTransfer {
            $transfer = StockTransfer::create([
                'origin_branch_id' => $data['origin_branch_id'],
                'destination_branch_id' => $data['destination_branch_id'],
                'status' => TransferStatus::Draft,
                'created_by' => $actor->id,
            ]);

            $transfer->items()->createMany($data['items']);

            $transfer->load(['originBranch', 'destinationBranch', 'items.ingredient']);

            ($this->writeAuditLog)(
                $actor,
                'transfer.created',
                $transfer,
                [],
                [
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
