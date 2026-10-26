<?php

namespace App\Http\Requests;

use App\Modules\Catalog\Models\ModifierGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateModifierGroupRequest extends FormRequest
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
        /** @var ModifierGroup|null $group */
        $group = $this->route('modifierGroup');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'min_select' => ['sometimes', 'required', 'integer', 'min:0'],
            'max_select' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'modifiers' => ['sometimes', 'nullable', 'array'],
            'modifiers.*.id' => [
                'nullable',
                'integer',
                Rule::exists('modifiers', 'id')->where('modifier_group_id', $group?->id),
            ],
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
                /** @var ModifierGroup|null $group */
                $group = $this->route('modifierGroup');

                $min = (int) $this->input('min_select', $group?->min_select ?? 0);
                $max = (int) $this->input('max_select', $group?->max_select ?? 0);

                if ($max < $min) {
                    $validator->errors()->add('max_select', 'Pilihan maksimum harus lebih besar atau sama dengan minimum.');
                }
            },
        ];
    }
}
