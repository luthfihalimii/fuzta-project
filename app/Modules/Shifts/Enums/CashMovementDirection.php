<?php

namespace App\Modules\Shifts\Enums;

enum CashMovementDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Masuk',
            self::Out => 'Keluar',
        };
    }
}
