<?php

namespace App\Modules\Inventory\Enums;

enum TransferStatus: string
{
    case Draft = 'draft';
    case Dispatched = 'dispatched';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
