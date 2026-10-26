<?php

namespace App\Http\Requests;

use App\Modules\Inventory\Enums\StockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'quantity' => ['required', 'integer', 'not_in:0'],
            'type' => ['required', Rule::in([
                StockMovementType::Opening->value,
                StockMovementType::Receipt->value,
                StockMovementType::Adjustment->value,
            ])],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
