<?php

namespace App\Modules\Ordering\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'order_number',
    'branch_id',
    'placed_by_id',
    'channel',
    'table_number',
    'customer_name',
    'note',
    'status',
    'payment_status',
    'subtotal',
    'total',
    'idempotency_key',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => OrderChannel::class,
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal' => 'integer',
            'total' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function canBeCancelled(): bool
    {
        return $this->status === OrderStatus::Queued
            && $this->payment_status === PaymentStatus::Unpaid;
    }

    public function scopeVisibleToKitchen(Builder $query, ?int $branchId = null): Builder
    {
        return $query
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->where('payment_status', PaymentStatus::Paid)
            ->where('status', '!=', OrderStatus::Cancelled);
    }
}
