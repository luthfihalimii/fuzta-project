<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUnitConversionRequest extends FormRequest
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
            'from_unit_id' => ['required', 'integer', 'exists:units,id'],
            'to_unit_id' => ['required', 'integer', 'exists:units,id', 'different:from_unit_id'],
            'from_quantity' => ['required', 'integer', 'min:1'],
            'to_quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
