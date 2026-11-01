<?php

namespace App\Modules\Branches\Support;

use Carbon\CarbonInterface;

class OpeningHours
{
    /**
     * Port dari resources/js/lib/opening-hours.ts: bandingkan H:i leksikografis
     * (zero-padded, sehingga aman), opens_at inklusif, closes_at eksklusif,
     * range terbalik (closes_at <= opens_at) selalu tutup (guard data kosongan/DB manual).
     */
    public static function isOpen(string $opensAt, string $closesAt, ?CarbonInterface $now = null): bool
    {
        $now ??= now('Asia/Jakarta');

        $current = $now->timezone('Asia/Jakarta')->format('H:i');

        return $closesAt > $opensAt && $current >= $opensAt && $current < $closesAt;
    }
}
