<?php

namespace App\Modules\Shifts\Http\Requests;

use App\Modules\Shifts\Enums\CashMovementDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordCashMovementRequest extends FormRequest
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
            'direction' => ['required', Rule::enum(CashMovementDirection::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
