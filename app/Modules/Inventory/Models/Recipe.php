<?php

namespace App\Modules\Inventory\Models;

use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['recipeable_type', 'recipeable_id'])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    public function recipeable(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }
}
