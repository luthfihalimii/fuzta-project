<?php

namespace App\Modules\Shifts\Actions;

use App\Models\User;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Shifts\Enums\CashMovementDirection;
use App\Modules\Shifts\Models\CashierShift;
use App\Modules\Shifts\Models\CashMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordCashMovement
{
    public function __construct(
        private readonly CalculateShiftTotals $calculateTotals,
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(
        CashierShift $shift,
        User $owner,
        CashMovementDirection $direction,
        int $amount,
        string $reason,
        string $idempotencyKey,
    ): CashMovement {
        return DB::transaction(function () use ($shift, $owner, $direction, $amount, $reason, $idempotencyKey): CashMovement {
            $locked = CashierShift::query()->lockForUpdate()->findOrFail($shift->id);

            $existing = $locked->cashMovements()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift sudah ditutup.',
                ]);
            }

            if ($direction === CashMovementDirection::Out) {
                $available = ($this->calculateTotals)($locked)['expected_cash'];

                if ($amount > $available) {
                    throw ValidationException::withMessages([
                        'amount' => 'Kas keluar melebihi kas tersedia.',
                    ]);
                }
            }

            $movement = $locked->cashMovements()->create([
                'direction' => $direction,
                'amount' => $amount,
                'reason' => $reason,
                'actor_id' => $owner->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            ($this->writeAuditLog)(
                $owner,
                'shift.cash_movement',
                $locked,
                [],
                [
                    'cash_movement_id' => $movement->id,
                    'direction' => $direction->value,
                    'amount' => $amount,
                    'reason' => $reason,
                ],
            );

            return $movement;
        });
    }
}
