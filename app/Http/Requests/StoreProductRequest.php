<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
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
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_price' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'variants' => ['nullable', 'array'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.price' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'modifier_group_ids' => ['nullable', 'array'],
            'modifier_group_ids.*' => ['integer', 'exists:modifier_groups,id'],
            'branch_availability' => ['nullable', 'array'],
            'branch_availability.*.branch_id' => ['required', 'integer', 'exists:branches,id'],
            'branch_availability.*.is_available' => ['sometimes', 'boolean'],
        ];
    }
}
