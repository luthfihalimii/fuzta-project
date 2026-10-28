<?php

namespace Tests\Feature;

use App\Modules\Shifts\Actions\ResolveShiftWindow;
use App\Modules\Shifts\Data\ShiftWindowData;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ShiftWindowTest extends TestCase
{
    private function at(string $jakarta): ?ShiftWindowData
    {
        return app(ResolveShiftWindow::class)(
            CarbonImmutable::parse($jakarta, 'Asia/Jakarta'),
        );
    }

    public function test_morning_window_is_active_between_start_and_end(): void
    {
        $window = $this->at('2026-10-28 09:00:00');

        $this->assertSame('morning', $window?->key);
        $this->assertSame('2026-10-28 02:00:00', $window?->startUtc->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-28 08:00:00', $window?->endUtc->format('Y-m-d H:i:s'));
    }

    public function test_morning_end_is_exclusive_and_evening_starts(): void
    {
        $this->assertSame('evening', $this->at('2026-10-28 15:00:00')?->key);
    }

    public function test_fourteen_fifty_is_still_morning(): void
    {
        $this->assertSame('morning', $this->at('2026-10-28 14:50:00')?->key);
    }

    public function test_early_open_fifteen_minutes_is_allowed(): void
    {
        $this->assertSame('morning', $this->at('2026-10-28 08:45:00')?->key);
    }

    public function test_twenty_minutes_early_is_rejected(): void
    {
        $this->assertNull($this->at('2026-10-28 08:40:00')?->key);
    }

    public function test_afternoon_early_open_still_resolves_to_morning(): void
    {
        $this->assertSame('morning', $this->at('2026-10-28 14:45:00')?->key);
    }

    public function test_after_last_window_is_null(): void
    {
        $this->assertNull($this->at('2026-10-28 21:00:00'));
    }
}
