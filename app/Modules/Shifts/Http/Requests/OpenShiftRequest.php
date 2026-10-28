<?php

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenShiftRequest extends FormRequest
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
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('is_active', true),
                Rule::requiredIf(fn () => $this->user()?->isOwner() ?? false),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $user = $this->user();

                    if ($user !== null && ! $user->isOwner() && (int) $value !== (int) $user->branch_id) {
                        $fail('Anda tidak dapat membuka shift untuk cabang lain.');
                    }
                },
            ],
            'opening_cash_confirmed' => ['required', 'accepted'],
        ];
    }
}
