<?php

namespace App\Modules\Catalog\Models;

use Database\Factories\ModifierGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'min_select', 'max_select', 'is_required', 'sort_order'])]
class ModifierGroup extends Model
{
    /** @use HasFactory<ModifierGroupFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_modifier_groups')
            ->withPivot('sort_order');
    }
}
