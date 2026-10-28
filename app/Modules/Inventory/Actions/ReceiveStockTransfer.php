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

class ReceiveStockTransfer
{
    public function __construct(
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(User $actor, int $transferId, array $receivedItems): StockTransfer
    {
        return DB::transaction(function () use ($actor, $transferId, $receivedItems): StockTransfer {
            $transfer = StockTransfer::query()
                ->whereKey($transferId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->status !== TransferStatus::Dispatched) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya transfer yang sedang dikirim yang dapat diterima.',
                ]);
            }

            $items = $transfer->items()->orderBy('id')->get();

            $rowsByItemId = [];
            foreach (array_values($receivedItems) as $pos => $row) {
                $rowsByItemId[(int) $row['item_id']] = [$pos, $row];
            }

            if (count($receivedItems) !== $items->count()
                || $items->contains(fn ($item) => ! isset($rowsByItemId[$item->id]))) {
                throw ValidationException::withMessages([
                    'received_items' => 'Semua baris transfer harus diterima sekali.',
                ]);
            }

            $ingredients = Ingredient::query()
                ->findMany($items->pluck('ingredient_id'))
                ->keyBy('id');

            $now = now();
            $snapshot = [];

            foreach ($items->sortBy('ingredient_id') as $item) {
                [$pos, $row] = $rowsByItemId[$item->id];
                $ingredient = $ingredients->get((int) $item->ingredient_id);

                $sent = (int) $item->quantity;
                $received = (int) $row['received_quantity'];
                $trimmed = trim((string) ($row['discrepancy_reason'] ?? ''));

                if ($received < $sent && $trimmed === '') {
                    throw ValidationException::withMessages([
                        "received_items.{$pos}.discrepancy_reason" => 'Alasan selisih wajib diisi.',
                    ]);
                }

                $reason = $trimmed === '' ? null : $trimmed;

                $item->update([
                    'received_quantity' => $received,
                    'discrepancy_reason' => $reason,
                ]);

                if ($received > 0) {
                    StockBalance::query()->insertOrIgnore([
                        'branch_id' => $transfer->destination_branch_id,
                        'ingredient_id' => $item->ingredient_id,
                        'quantity' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $balance = StockBalance::query()
                        ->where('branch_id', $transfer->destination_branch_id)
                        ->where('ingredient_id', $item->ingredient_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $balance->update(['quantity' => (int) $balance->quantity + $received]);

                    StockMovement::create([
                        'branch_id' => $transfer->destination_branch_id,
                        'ingredient_id' => $item->ingredient_id,
                        'unit_id' => $ingredient->base_unit_id,
                        'quantity' => $received,
                        'type' => StockMovementType::TransferIn,
                        'reason' => "Penerimaan transfer #{$transfer->id}"
                            .($reason === null ? '' : " (selisih: {$reason})"),
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $transfer->id,
                        'actor_id' => $actor->id,
                    ]);
                }

                $snapshot[] = [
                    'ingredient' => $ingredient?->name,
                    'sent' => $sent,
                    'received' => $received,
                    'discrepancy' => $reason,
                ];
            }

            $transfer->update([
                'status' => TransferStatus::Received,
                'received_at' => $now,
            ]);

            ($this->writeAuditLog)(
                $actor,
                'transfer.received',
                $transfer,
                ['status' => TransferStatus::Dispatched->value],
                [
                    'status' => TransferStatus::Received->value,
                    'items' => $snapshot,
                ],
            );

            return $transfer;
        });
    }
}
