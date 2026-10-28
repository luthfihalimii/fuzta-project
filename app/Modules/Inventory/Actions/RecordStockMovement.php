<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Identity\Enums\Role;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordStockMovement
{
    public function __construct(
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(
        User $actor,
        int $branchId,
        int $ingredientId,
        int $quantity,
        StockMovementType $type,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        if (! $actor->hasBranchAccess($branchId)) {
            throw new AuthorizationException('Anda tidak dapat mengubah stok cabang lain.');
        }

        if (! in_array($actor->role(), [Role::Owner, Role::Kitchen], true)) {
            throw new AuthorizationException('Anda tidak dapat mengubah stok.');
        }

        if ($type->requiresOwner() && ! $actor->isOwner()) {
            throw new AuthorizationException('Hanya pemilik yang dapat mencatat transaksi stok ini.');
        }

        $this->validateMovement($quantity, $type, $reason);

        return DB::transaction(function () use (
            $actor,
            $branchId,
            $ingredientId,
            $quantity,
            $type,
            $reason,
            $referenceType,
            $referenceId,
        ): StockMovement {
            if (! Branch::query()->whereKey($branchId)->exists()) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Cabang tidak ditemukan.',
                ]);
            }

            $ingredient = Ingredient::query()->find($ingredientId);

            if ($ingredient === null) {
                throw ValidationException::withMessages([
                    'ingredient_id' => 'Bahan tidak ditemukan.',
                ]);
            }

            $now = now();

            StockBalance::query()->insertOrIgnore([
                'branch_id' => $branchId,
                'ingredient_id' => $ingredientId,
                'quantity' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $balance = StockBalance::query()
                ->where('branch_id', $branchId)
                ->where('ingredient_id', $ingredientId)
                ->lockForUpdate()
                ->firstOrFail();

            $before = (int) $balance->quantity;
            $after = $before + $quantity;

            if ($after < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Stok tidak boleh negatif.',
                ]);
            }

            $balance->update(['quantity' => $after]);

            $movement = StockMovement::create([
                'branch_id' => $branchId,
                'ingredient_id' => $ingredientId,
                'unit_id' => $ingredient->base_unit_id,
                'quantity' => $quantity,
                'type' => $type,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'actor_id' => $actor->id,
            ]);

            if ($type === StockMovementType::Adjustment) {
                ($this->writeAuditLog)(
                    $actor,
                    'stock.adjusted',
                    $movement,
                    ['quantity' => $before],
                    [
                        'quantity' => $after,
                        'delta' => $quantity,
                        'type' => $type->value,
                    ],
                    $reason,
                );
            }

            return $movement;
        });
    }

    private function validateMovement(
        int $quantity,
        StockMovementType $type,
        string $reason,
    ): void {
        if ($quantity === 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Jumlah stok tidak boleh nol.',
            ]);
        }

        if (in_array($type, [StockMovementType::Opening, StockMovementType::Receipt], true) && $quantity < 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Jumlah stok harus lebih dari nol.',
            ]);
        }

        if ($type === StockMovementType::Waste && $quantity > 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Jumlah rusak harus mengurangi stok.',
            ]);
        }

        if ($type === StockMovementType::Consumption && $quantity > 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Jumlah konsumsi harus mengurangi stok.',
            ]);
        }

        if ($type === StockMovementType::ConsumptionReversal && $quantity < 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Jumlah pembalikan konsumsi harus menambah stok.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan wajib diisi.',
            ]);
        }
    }
}
