<?php

namespace App\Modules\Payments\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case Paid = 'paid';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Belum dibayar',
            self::Pending => 'Menunggu pembayaran',
            self::Paid => 'Lunas',
            self::Voided => 'Dibatalkan',
        };
    }
}
