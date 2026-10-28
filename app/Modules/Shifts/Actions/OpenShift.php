<?php

namespace App\Modules\Shifts\Actions;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenShift
{
    public function __construct(
        private readonly ResolveShiftWindow $resolveWindow,
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(User $actor, int $branchId): CashierShift
    {
        if (! $actor->hasBranchAccess($branchId)) {
            throw new AuthorizationException('Anda tidak dapat membuka shift untuk cabang lain.');
        }

        $branch = Branch::query()->find($branchId);

        if ($branch === null || ! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang tidak aktif.',
            ]);
        }

        $window = ($this->resolveWindow)();

        if ($window === null) {
            throw ValidationException::withMessages([
                'shift' => 'Di luar jam shift.',
            ]);
        }

        try {
            return DB::transaction(function () use ($actor, $branch, $window): CashierShift {
                $open = CashierShift::query()
                    ->where('user_id', $actor->id)
                    ->where('branch_id', $branch->id)
                    ->whereNull('closed_at')
                    ->lockForUpdate()
                    ->first();

                if ($open !== null) {
                    $sameWindow = $open->window_key === $window->key
                        && $open->scheduled_start_at->equalTo($window->startUtc)
                        && $open->scheduled_end_at->equalTo($window->endUtc);

                    if ($sameWindow) {
                        return $open;
                    }

                    throw ValidationException::withMessages([
                        'shift' => 'Tutup shift sebelumnya terlebih dahulu.',
                    ]);
                }

                $alreadyClosed = CashierShift::query()
                    ->where('user_id', $actor->id)
                    ->where('branch_id', $branch->id)
                    ->where('window_key', $window->key)
                    ->where('scheduled_start_at', $window->startUtc)
                    ->where('scheduled_end_at', $window->endUtc)
                    ->exists();

                if ($alreadyClosed) {
                    throw ValidationException::withMessages([
                        'shift' => 'Shift untuk jendela ini sudah ditutup.',
                    ]);
                }

                $openShift = CashierShift::query()
                    ->where('user_id', $actor->id)
                    ->where('branch_id', $branch->id)
                    ->whereNull('closed_at')
                    ->lockForUpdate()
                    ->first();

                if ($openShift !== null) {
                    throw ValidationException::withMessages([
                        'shift' => 'Tutup shift sebelumnya terlebih dahulu.',
                    ]);
                }

                $shift = CashierShift::create([
                    'branch_id' => $branch->id,
                    'user_id' => $actor->id,
                    'window_key' => $window->key,
                    'scheduled_start_at' => $window->startUtc,
                    'scheduled_end_at' => $window->endUtc,
                    'opened_at' => now(),
                    'opening_cash' => $branch->cash_float,
                ]);

                ($this->writeAuditLog)(
                    $actor,
                    'shift.opened',
                    $shift,
                    [],
                    [
                        'window_key' => $shift->window_key,
                        'scheduled_start_at' => $shift->scheduled_start_at?->toIso8601String(),
                        'scheduled_end_at' => $shift->scheduled_end_at?->toIso8601String(),
                        'opening_cash' => $shift->opening_cash,
                    ],
                );

                return $shift;
            });
        } catch (QueryException $exception) {
            $open = CashierShift::query()
                ->where('user_id', $actor->id)
                ->where('branch_id', $branch->id)
                ->whereNull('closed_at')
                ->first();

            if ($open !== null) {
                return $open;
            }

            throw $exception;
        }
    }
}
