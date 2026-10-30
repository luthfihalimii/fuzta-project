<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Identity\Enums\Role;
use App\Modules\Inventory\Actions\ConvertUnit;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Purchasing\Data\RecordPurchaseData;
use App\Modules\Purchasing\Models\Purchase;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPurchase
{
    public function __construct(
        private readonly ConvertUnit $convertUnit,
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(User $actor, RecordPurchaseData $data): Purchase
    {
        if (! in_array($actor->role(), [Role::Owner, Role::Cashier, Role::Kitchen], true)) {
            throw new AuthorizationException('Anda tidak dapat mencatat pembelian.');
        }

        if (! $actor->hasBranchAccess($data->branchId)) {
            throw new AuthorizationException('Anda tidak dapat mencatat pembelian untuk cabang lain.');
        }

        $existing = $this->findExisting($data);

        if ($existing !== null) {
            return $existing;
        }

        $supplier = Supplier::query()->findOrFail($data->supplierId);

        if (! $supplier->is_active) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Supplier tidak tersedia.',
            ]);
        }

        $branch = Branch::query()->find($data->branchId);

        if ($branch === null || ! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang tidak aktif.',
            ]);
        }

        if ($data->items === []) {
            throw ValidationException::withMessages([
                'items' => 'Minimal satu baris.',
            ]);
        }

        if (count(array_unique(array_column($data->items, 'ingredient_id'))) !== count($data->items)) {
            throw ValidationException::withMessages([
                'items' => 'Bahan tidak boleh duplikat dalam satu dokumen.',
            ]);
        }

        $lines = [];

        foreach ($data->items as $i => $row) {
            $ingredient = Ingredient::query()->find($row['ingredient_id']);

            if ($ingredient === null) {
                throw ValidationException::withMessages([
                    "items.{$i}.ingredient_id" => 'Bahan tidak ditemukan.',
                ]);
            }

            $quantity = (int) $row['quantity'];

            try {
                $baseQuantity = ($this->convertUnit)(
                    (int) $row['unit_id'],
                    $quantity,
                    (int) $ingredient->base_unit_id,
                );
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    "items.{$i}.unit_id" => 'Definisikan konversi unit terlebih dahulu.',
                ]);
            }

            // intdiv floors non-exact results; reject them so the ledger never silently loses quantity.
            $roundTrip = ($this->convertUnit)(
                (int) $ingredient->base_unit_id,
                $baseQuantity,
                (int) $row['unit_id'],
            );

            if ($roundTrip !== $quantity) {
                throw ValidationException::withMessages([
                    "items.{$i}.quantity" => 'Hasil konversi bukan bilangan bulat.',
                ]);
            }

            $unit = Unit::query()->findOrFail($row['unit_id']);

            $lines[] = [
                'ingredient' => $ingredient,
                'unit_id' => (int) $unit->id,
                'unit_code' => $unit->code,
                'quantity' => $quantity,
                'unit_price' => (int) $row['unit_price'],
                'line_total' => $quantity * (int) $row['unit_price'],
                'base_quantity' => $baseQuantity,
            ];
        }

        $total = array_sum(array_column($lines, 'line_total'));

        try {
            return DB::transaction(function () use ($actor, $data, $supplier, $lines, $total): Purchase {
                $existing = $this->findExisting($data, lock: true);

                if ($existing !== null) {
                    return $existing;
                }

                $purchase = Purchase::create([
                    'branch_id' => $data->branchId,
                    'supplier_id' => $supplier->id,
                    'received_at' => $data->receivedAt,
                    'reference' => $data->reference,
                    'total' => $total,
                    'recorded_by' => $actor->id,
                    'idempotency_key' => $data->idempotencyKey,
                ]);

                foreach ($lines as $line) {
                    $purchase->items()->create([
                        'ingredient_id' => $line['ingredient']->id,
                        'unit_id' => $line['unit_id'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'line_total' => $line['line_total'],
                    ]);
                }

                $now = now();

                foreach (collect($lines)->sortBy(fn (array $line): int => $line['ingredient']->id) as $line) {
                    $ingredient = $line['ingredient'];

                    StockBalance::query()->insertOrIgnore([
                        'branch_id' => $data->branchId,
                        'ingredient_id' => $ingredient->id,
                        'quantity' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $balance = StockBalance::query()
                        ->where('branch_id', $data->branchId)
                        ->where('ingredient_id', $ingredient->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $balance->update(['quantity' => (int) $balance->quantity + $line['base_quantity']]);

                    StockMovement::create([
                        'branch_id' => $data->branchId,
                        'ingredient_id' => $ingredient->id,
                        'unit_id' => $ingredient->base_unit_id,
                        'quantity' => $line['base_quantity'],
                        'type' => StockMovementType::Receipt,
                        'reason' => "Penerimaan pembelian #{$purchase->id} — {$supplier->name}",
                        'reference_type' => Purchase::class,
                        'reference_id' => $purchase->id,
                        'actor_id' => $actor->id,
                    ]);
                }

                ($this->writeAuditLog)(
                    $actor,
                    'purchase.received',
                    $purchase,
                    [],
                    $this->snapshot($purchase, $supplier, $lines),
                );

                return $purchase->load('items');
            });
        } catch (QueryException $exception) {
            if ($this->isIdempotencyViolation($exception)) {
                $existing = $this->findExisting($data);

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }

    private function findExisting(RecordPurchaseData $data, bool $lock = false): ?Purchase
    {
        $query = Purchase::query()
            ->with('items')
            ->where('branch_id', $data->branchId)
            ->where('idempotency_key', $data->idempotencyKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function isIdempotencyViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505'
            && str_contains($exception->getMessage(), 'purchases_branch_id_idempotency_key_unique');
    }

    /**
     * @param  list<array{ingredient: Ingredient, unit_id: int, unit_code: string, quantity: int, unit_price: int, line_total: int, base_quantity: int}>  $lines
     * @return array<string, mixed>
     */
    private function snapshot(Purchase $purchase, Supplier $supplier, array $lines): array
    {
        return [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'branch_id' => $purchase->branch_id,
            'total' => $purchase->total,
            'items' => array_map(fn (array $line): array => [
                'ingredient' => $line['ingredient']->name,
                'unit_code' => $line['unit_code'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
                'base_quantity' => $line['base_quantity'],
            ], $lines),
        ];
    }
}
