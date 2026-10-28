<?php

namespace App\Modules\Inventory\Enums;

enum StockMovementType: string
{
    case Opening = 'opening';
    case Receipt = 'receipt';
    case Waste = 'waste';
    case Adjustment = 'adjustment';
    case CountCorrection = 'count_correction';
    case Consumption = 'consumption';
    case ConsumptionReversal = 'consumption_reversal';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Stok awal',
            self::Receipt => 'Penerimaan',
            self::Waste => 'Rusak',
            self::Adjustment => 'Koreksi',
            self::CountCorrection => 'Koreksi opname',
            self::Consumption => 'Konsumsi pesanan',
            self::ConsumptionReversal => 'Pembalikan konsumsi',
            self::TransferOut => 'Pengiriman antar cabang',
            self::TransferIn => 'Penerimaan antar cabang',
        };
    }

    public function requiresOwner(): bool
    {
        return in_array($this, [self::Opening, self::Receipt, self::Adjustment], true);
    }
}
