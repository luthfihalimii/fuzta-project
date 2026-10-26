<?php

namespace App\Modules\Identity\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Cashier = 'cashier';
    case Kitchen = 'kitchen';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Pemilik',
            self::Cashier => 'Kasir',
            self::Kitchen => 'Dapur',
        };
    }
}
