<?php

namespace Tests\Unit;

use App\Modules\Branches\Support\OpeningHours;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class OpeningHoursTest extends TestCase
{
    /**
     * @return callable(string): CarbonImmutable
     */
    private function at(): callable
    {
        return fn (string $time): CarbonImmutable => CarbonImmutable::parse($time, 'Asia/Jakarta');
    }

    public function test_is_open_boundaries(): void
    {
        $at = $this->at();

        $this->assertTrue(OpeningHours::isOpen('09:00', '21:00', $at('2026-11-01 09:00')));
        $this->assertTrue(OpeningHours::isOpen('09:00', '21:00', $at('2026-11-01 21:00')->subMinute()));
        $this->assertFalse(OpeningHours::isOpen('09:00', '21:00', $at('2026-11-01 21:00')));
        $this->assertFalse(OpeningHours::isOpen('09:00', '21:00', $at('2026-11-01 08:59')));
    }

    public function test_h_i_zero_padded_lexicographic_order_matches_clock_order(): void
    {
        $at = $this->at();

        // H:i selalu zero-padded 2 digit, sehingga urutan leksikografis = urutan waktu.
        $this->assertTrue(OpeningHours::isOpen('09:00', '10:00', $at('2026-11-01 09:59')));
        $this->assertFalse(OpeningHours::isOpen('09:00', '10:00', $at('2026-11-01 10:00')));
        $this->assertTrue(OpeningHours::isOpen('20:00', '22:30', $at('2026-11-01 22:29')));
        $this->assertFalse(OpeningHours::isOpen('20:00', '22:30', $at('2026-11-01 22:30')));
    }

    public function test_reversed_or_zero_range_always_closed(): void
    {
        $at = $this->at();

        $this->assertFalse(OpeningHours::isOpen('21:00', '09:00', $at('2026-11-01 12:00')));
        $this->assertFalse(OpeningHours::isOpen('09:00', '09:00', $at('2026-11-01 09:30')));
    }

    public function test_instant_with_other_timezone_converted_to_asia_jakarta(): void
    {
        // WIB = UTC+7.
        $this->assertFalse(OpeningHours::isOpen('09:00', '21:00', CarbonImmutable::parse('2026-11-01 01:59', 'UTC'))); // 08:59 WIB
        $this->assertTrue(OpeningHours::isOpen('09:00', '21:00', CarbonImmutable::parse('2026-11-01 08:00', 'UTC'))); // 15:00 WIB
        $this->assertFalse(OpeningHours::isOpen('09:00', '21:00', CarbonImmutable::parse('2026-11-01 14:00', 'UTC'))); // 21:00 WIB
    }
}
