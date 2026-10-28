<?php

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'counted_cash' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'cash_deposited' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'closing_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
