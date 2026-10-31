<?php

namespace App\Http\Requests;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
        /** @var Product|null $product */
        $product = $this->route('product');

        return [
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'base_price' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['sometimes', 'boolean'],
            'variants' => ['sometimes', 'nullable', 'array'],
            'variants.*.id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where('product_id', $product?->id),
            ],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.price' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'modifier_group_ids' => ['sometimes', 'nullable', 'array'],
            'modifier_group_ids.*' => ['integer', 'exists:modifier_groups,id'],
            'branch_availability' => ['sometimes', 'nullable', 'array'],
            'branch_availability.*.branch_id' => ['required', 'integer', 'exists:branches,id'],
            'branch_availability.*.is_available' => ['sometimes', 'boolean'],
        ];
    }
}
