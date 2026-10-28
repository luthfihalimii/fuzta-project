<?php

namespace App\Modules\Shifts\Actions;

use App\Models\User;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Shifts\Enums\ShiftCloseType;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReconcileShift
{
    public function __construct(
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(
        CashierShift $shift,
        User $actor,
        int $countedCash,
        int $cashDeposited,
        ?string $note,
    ): CashierShift {
        return DB::transaction(function () use ($shift, $actor, $countedCash, $cashDeposited, $note): CashierShift {
            $locked = CashierShift::query()->lockForUpdate()->findOrFail($shift->id);

            if ($locked->close_type !== ShiftCloseType::Auto || $locked->reconciled_at !== null || $locked->expected_cash === null) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift ini tidak dapat direkonsiliasi.',
                ]);
            }

            if ($cashDeposited > $countedCash) {
                throw ValidationException::withMessages([
                    'cash_deposited' => 'Setoran tidak boleh melebihi kas dihitung.',
                ]);
            }

            $variance = $countedCash - $locked->expected_cash;

            if ($variance !== 0 && blank($note)) {
                throw ValidationException::withMessages([
                    'reconciliation_note' => 'Catatan wajib diisi jika ada selisih kas.',
                ]);
            }

            $before = [
                'counted_cash' => null,
                'variance' => null,
                'reconciled_at' => null,
            ];

            $locked->fill([
                'counted_cash' => $countedCash,
                'cash_deposited' => $cashDeposited,
                'variance' => $variance,
                'reconciliation_note' => $note,
                'reconciled_at' => now(),
                'reconciled_by_id' => $actor->id,
            ])->save();

            ($this->writeAuditLog)(
                $actor,
                'shift.reconciled',
                $locked,
                $before,
                [
                    'counted_cash' => $countedCash,
                    'cash_deposited' => $cashDeposited,
                    'variance' => $variance,
                ],
                $note,
            );

            return $locked->refresh();
        });
    }
}
