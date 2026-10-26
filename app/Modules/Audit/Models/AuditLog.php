<?php

namespace App\Modules\Audit\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'actor_id',
    'branch_id',
    'action',
    'target_type',
    'target_id',
    'before',
    'after',
    'reason',
    'ip',
    'user_agent',
])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
