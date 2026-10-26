<?php

namespace App\Http\Requests;

use App\Modules\Payments\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
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
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'cash_received' => [
                'nullable',
                'integer',
                'min:0',
                'max:1000000000',
                Rule::prohibitedIf(
                    fn (): bool => $this->input('method') === PaymentMethod::QrisStatic->value,
                ),
            ],
        ];
    }
}
