<?php

namespace App\Modules\Payments\Enums;

enum PaymentEventType: string
{
    case Paid = 'paid';
    case Voided = 'voided';
}
