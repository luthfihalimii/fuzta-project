<?php

namespace App\Modules\Inventory\Models;

use Database\Factories\UnitConversionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['from_unit_id', 'to_unit_id', 'from_quantity', 'to_quantity'])]
class UnitConversion extends Model
{
    /** @use HasFactory<UnitConversionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_quantity' => 'integer',
            'to_quantity' => 'integer',
        ];
    }

    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'from_unit_id');
    }

    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'to_unit_id');
    }
}
