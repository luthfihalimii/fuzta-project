<?php

namespace App\Modules\Shifts\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Shifts\Enums\ShiftCloseType;
use Database\Factories\CashierShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id',
    'user_id',
    'window_key',
    'scheduled_start_at',
    'scheduled_end_at',
    'opened_at',
    'closed_at',
    'opening_cash',
    'cash_sales_total',
    'qris_sales_total',
    'cash_in_total',
    'cash_out_total',
    'expected_cash',
    'counted_cash',
    'variance',
    'cash_deposited',
    'closing_note',
    'closed_by_id',
    'close_type',
    'reconciled_at',
    'reconciled_by_id',
    'reconciliation_note',
])]
class CashierShift extends Model
{
    /** @use HasFactory<CashierShiftFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'integer',
            'cash_sales_total' => 'integer',
            'qris_sales_total' => 'integer',
            'cash_in_total' => 'integer',
            'cash_out_total' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'variance' => 'integer',
            'cash_deposited' => 'integer',
            'close_type' => ShiftCloseType::class,
            'reconciled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_id');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'cashier_shift_id')->orderBy('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'shift_id')->orderByDesc('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'shift_id');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function isAutoClosedUnreconciled(): bool
    {
        return $this->close_type === ShiftCloseType::Auto && $this->reconciled_at === null;
    }

    public function isReconciled(): bool
    {
        return $this->reconciled_at !== null;
    }
}
