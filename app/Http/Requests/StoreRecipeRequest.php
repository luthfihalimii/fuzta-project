<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecipeRequest extends FormRequest
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
        $table = match ($this->input('recipeable_type')) {
            'product' => 'products',
            'product_variant' => 'product_variants',
            'modifier' => 'modifiers',
            default => null,
        };

        return [
            'recipeable_type' => ['required', Rule::in(['product', 'product_variant', 'modifier'])],
            'recipeable_id' => array_filter([
                'required',
                'integer',
                $table ? Rule::exists($table, 'id') : null,
            ]),
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
