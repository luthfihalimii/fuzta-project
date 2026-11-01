<?php

namespace App\Modules\Payments\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case QrisStatic = 'qris_static';
    case DokuQris = 'doku_qris';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::QrisStatic => 'QRIS statis',
            self::DokuQris => 'QRIS online (DOKU)',
        };
    }
}
