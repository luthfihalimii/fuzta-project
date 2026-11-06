<?php

namespace App\Modules\Payments\Models;

use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id',
    'shift_id',
    'method',
    'amount',
    'cash_received',
    'change_amount',
    'status',
    'paid_at',
    'voided_at',
    'provider',
    'provider_reference',
    'payment_url',
    'expires_at',
])]
class Payment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'cash_received' => 'integer',
            'change_amount' => 'integer',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<CashierShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'shift_id');
    }

    /**
     * @return HasMany<PaymentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }
}
