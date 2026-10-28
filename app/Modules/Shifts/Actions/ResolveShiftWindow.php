<?php

namespace App\Modules\Shifts\Actions;

use App\Modules\Shifts\Data\ShiftWindowData;
use Carbon\CarbonImmutable;

class ResolveShiftWindow
{
    public function __invoke(?CarbonImmutable $now = null): ?ShiftWindowData
    {
        $timezone = (string) config('shifts.timezone', 'Asia/Jakarta');
        $now = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $earlyOpenMinutes = (int) config('shifts.early_open_minutes', 15);

        $windows = $this->windows($now, $timezone);

        foreach ($windows as $window) {
            if ($now->greaterThanOrEqualTo($window['start']) && $now->lessThan($window['end'])) {
                return $window['data'];
            }
        }

        foreach ($windows as $window) {
            $earlyStart = $window['start']->subMinutes($earlyOpenMinutes);

            if ($now->greaterThanOrEqualTo($earlyStart) && $now->lessThan($window['start'])) {
                return $window['data'];
            }
        }

        return null;
    }

    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, data: ShiftWindowData}>
     */
    private function windows(CarbonImmutable $now, string $timezone): array
    {
        $windows = [];

        foreach ((array) config('shifts.windows', []) as $window) {
            $start = CarbonImmutable::parse($now->toDateString().' '.$window['start'], $timezone);
            $end = CarbonImmutable::parse($now->toDateString().' '.$window['end'], $timezone);

            $windows[] = [
                'start' => $start,
                'end' => $end,
                'data' => new ShiftWindowData(
                    key: (string) $window['key'],
                    label: (string) $window['label'],
                    startUtc: $start->utc(),
                    endUtc: $end->utc(),
                ),
            ];
        }

        return $windows;
    }
}
