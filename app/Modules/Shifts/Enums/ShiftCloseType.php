<?php

namespace App\Modules\Shifts\Enums;

enum ShiftCloseType: string
{
    case Manual = 'manual';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Auto => 'Otomatis',
        };
    }
}
