<?php

namespace App\Http\Requests;

use App\Modules\Customers\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class LoginCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalisasi HP sebelum validasi (pola sama dengan register).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => PhoneNumber::normalize((string) $this->input('phone', '')),
        ]);
    }

    /**
     * Tanpa aturan password di login: jangan bocorkan policy password.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'Nomor HP wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ];
    }
}
