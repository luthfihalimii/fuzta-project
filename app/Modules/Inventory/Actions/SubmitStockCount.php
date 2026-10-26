<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Identity\Enums\Role;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockCount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitStockCount
{
    public function __construct(
        private readonly RecordStockMovement $recordStockMovement,
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    /**
     * @param  list<array{ingredient_id:int, counted_quantity:int}>  $countedItems
     */
    public function __invoke(
        User $actor,
        int $branchId,
        array $countedItems,
        ?string $note = null,
    ): StockCount {
        if (! $actor->hasBranchAccess($branchId)) {
            throw new AuthorizationException('Anda tidak dapat menghitung stok cabang lain.');
        }

        if (! in_array($actor->role(), [Role::Owner, Role::Kitchen], true)) {
            throw new AuthorizationException('Anda tidak dapat menghitung stok.');
        }

        $items = $this->validateItems($countedItems);

        return DB::transaction(function () use ($actor, $branchId, $items, $note): StockCount {
            if (! Branch::query()->whereKey($branchId)->exists()) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Cabang tidak ditemukan.',
                ]);
            }

            $count = StockCount::create([
                'branch_id' => $branchId,
                'submitted_by_id' => $actor->id,
                'note' => $note,
            ]);

            $auditItems = [];

            foreach ($items as $item) {
                $ingredient = Ingredient::query()->find($item['ingredient_id']);

                if ($ingredient === null) {
                    throw ValidationException::withMessages([
                        'counted_items' => 'Bahan tidak ditemukan.',
                    ]);
                }

                $now = now();

                StockBalance::query()->insertOrIgnore([
                    'branch_id' => $branchId,
                    'ingredient_id' => $ingredient->id,
                    'quantity' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $balance = StockBalance::query()
                    ->where('branch_id', $branchId)
                    ->where('ingredient_id', $ingredient->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $systemQuantity = (int) $balance->quantity;
                $difference = $item['counted_quantity'] - $systemQuantity;

                $count->items()->create([
                    'ingredient_id' => $ingredient->id,
                    'system_quantity' => $systemQuantity,
                    'counted_quantity' => $item['counted_quantity'],
                    'difference' => $difference,
                ]);

                if ($difference !== 0) {
                    ($this->recordStockMovement)(
                        $actor,
                        $branchId,
                        $ingredient->id,
                        $difference,
                        StockMovementType::CountCorrection,
                        'Opname tutup',
                        StockCount::class,
                        $count->id,
                    );
                }

                $auditItems[] = [
                    'ingredient_id' => $ingredient->id,
                    'ingredient_name' => $ingredient->name,
                    'system_quantity' => $systemQuantity,
                    'counted_quantity' => $item['counted_quantity'],
                    'difference' => $difference,
                ];
            }

            ($this->writeAuditLog)(
                $actor,
                'stock.count_submitted',
                $count,
                [],
                [
                    'note' => $note,
                    'items' => $auditItems,
                ],
                $note,
            );

            return $count->load('items');
        });
    }

    /**
     * @param  array<mixed>  $countedItems
     * @return list<array{ingredient_id:int, counted_quantity:int}>
     */
    private function validateItems(array $countedItems): array
    {
        if ($countedItems === []) {
            throw ValidationException::withMessages([
                'counted_items' => 'Masukkan minimal satu bahan.',
            ]);
        }

        $validated = [];
        $seen = [];

        foreach ($countedItems as $index => $item) {
            if (! is_array($item) || ! isset($item['ingredient_id'], $item['counted_quantity'])) {
                throw ValidationException::withMessages([
                    "counted_items.{$index}" => 'Data hitung tidak valid.',
                ]);
            }

            $ingredientId = $item['ingredient_id'];
            $countedQuantity = $item['counted_quantity'];

            if (! is_int($ingredientId) || ! is_int($countedQuantity) || $countedQuantity < 0) {
                throw ValidationException::withMessages([
                    "counted_items.{$index}" => 'Data hitung tidak valid.',
                ]);
            }

            if (isset($seen[$ingredientId])) {
                throw ValidationException::withMessages([
                    "counted_items.{$index}.ingredient_id" => 'Bahan tidak boleh duplikat.',
                ]);
            }

            $seen[$ingredientId] = true;
            $validated[] = [
                'ingredient_id' => $ingredientId,
                'counted_quantity' => $countedQuantity,
            ];
        }

        return $validated;
    }
}
