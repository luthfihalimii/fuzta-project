<?php

namespace App\Modules\Shifts\Data;

use Carbon\CarbonImmutable;

final readonly class ShiftWindowData
{
    public function __construct(
        public string $key,
        public string $label,
        public CarbonImmutable $startUtc,
        public CarbonImmutable $endUtc,
    ) {}
}
