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
     * Normalisasi HP sebelum validasi (pola sama dengan register). Hanya
     * string: tipe lain (array/null) dibiarkan agar aturan `string` yang
     * menjawab (422, bukan 500).
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone)) {
            $this->merge([
                'phone' => PhoneNumber::normalize($phone),
            ]);
        }
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
