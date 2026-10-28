<?php

namespace App\Modules\Shifts\Actions;

use App\Modules\Shifts\Models\CashierShift;
use Carbon\CarbonImmutable;

class BuildShiftReminder
{
    /**
     * @return array{level: string, message: string}|null
     */
    public function __invoke(?CashierShift $shift, ?CarbonImmutable $now = null): ?array
    {
        if ($shift === null || ! $shift->isOpen()) {
            return null;
        }

        $now = $now ?? CarbonImmutable::now();
        $end = $shift->scheduled_end_at;
        $reminder = (int) config('shifts.reminder_minutes', 30);
        $grace = (int) config('shifts.auto_close_grace_minutes', 15);

        if ($now->lessThan($end->subMinutes($reminder))) {
            return null;
        }

        if ($now->lessThan($end)) {
            return [
                'level' => 'ending',
                'message' => 'Shift berakhir dalam '.$reminder.' menit.',
            ];
        }

        if ($now->lessThan($end->addMinutes($grace))) {
            return [
                'level' => 'overdue',
                'message' => 'Shift sudah melewati jadwal. Tutup shift.',
            ];
        }

        return [
            'level' => 'cutoff',
            'message' => 'Batas pesanan sudah lewat. Tutup shift.',
        ];
    }
}
