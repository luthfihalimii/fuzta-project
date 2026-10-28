<?php

namespace App\Modules\Shifts\Actions;

use App\Models\User;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Shifts\Enums\ShiftCloseType;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseShift
{
    public function __construct(
        private readonly CalculateShiftTotals $calculateTotals,
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

            if ($locked->closed_at !== null) {
                if ($locked->isAutoClosedUnreconciled()) {
                    throw ValidationException::withMessages([
                        'shift' => 'Shift ditutup otomatis. Gunakan rekonsiliasi.',
                    ]);
                }

                return $locked;
            }

            $now = now();
            $early = $now->lessThan($locked->scheduled_end_at);

            if ($early && ! $actor->isOwner()) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift belum berakhir. Hubungi pemilik untuk menutup lebih awal.',
                ]);
            }

            if ($early && blank($note)) {
                throw ValidationException::withMessages([
                    'closing_note' => 'Alasan tutup lebih awal wajib diisi.',
                ]);
            }

            if ($cashDeposited > $countedCash) {
                throw ValidationException::withMessages([
                    'cash_deposited' => 'Setoran tidak boleh melebihi kas dihitung.',
                ]);
            }

            $unpaid = $locked->orders()
                ->where('payment_status', 'unpaid')
                ->where('status', '!=', 'cancelled')
                ->count();

            if ($unpaid > 0) {
                throw ValidationException::withMessages([
                    'shift' => "Masih ada {$unpaid} pesanan belum dibayar.",
                ]);
            }

            $totals = ($this->calculateTotals)($locked);
            $variance = $countedCash - $totals['expected_cash'];

            if ($variance !== 0 && blank($note)) {
                throw ValidationException::withMessages([
                    'closing_note' => 'Catatan wajib diisi jika ada selisih kas.',
                ]);
            }

            $before = [
                'status' => 'open',
                'expected_cash' => null,
            ];

            $locked->fill([
                ...$totals,
                'counted_cash' => $countedCash,
                'variance' => $variance,
                'cash_deposited' => $cashDeposited,
                'closing_note' => $note,
                'closed_at' => $now,
                'closed_by_id' => $actor->id,
                'close_type' => ShiftCloseType::Manual,
                'reconciled_at' => $now,
                'reconciled_by_id' => $actor->id,
            ])->save();

            ($this->writeAuditLog)(
                $actor,
                'shift.closed',
                $locked,
                $before,
                [
                    'expected_cash' => $totals['expected_cash'],
                    'counted_cash' => $countedCash,
                    'variance' => $variance,
                    'cash_deposited' => $cashDeposited,
                    'close_type' => ShiftCloseType::Manual->value,
                ],
                $note,
            );

            return $locked->refresh();
        });
    }
}
