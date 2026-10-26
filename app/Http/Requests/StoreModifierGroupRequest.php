<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreModifierGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'min_select' => ['required', 'integer', 'min:0'],
            'max_select' => ['required', 'integer', 'min:0'],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'modifiers' => ['nullable', 'array'],
            'modifiers.*.name' => ['required_with:modifiers', 'string', 'max:255'],
            'modifiers.*.price_delta' => ['required_with:modifiers', 'integer'],
            'modifiers.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'modifiers.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ((int) $this->input('max_select') < (int) $this->input('min_select')) {
                    $validator->errors()->add('max_select', 'Pilihan maksimum harus lebih besar atau sama dengan minimum.');
                }
            },
        ];
    }
}
