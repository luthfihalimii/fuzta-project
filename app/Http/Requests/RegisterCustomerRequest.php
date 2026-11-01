<?php

namespace App\Http\Requests;

use App\Modules\Customers\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalisasi HP sebelum validasi agar `+62`/`62`/spasi tetap lolos
     * regex `08...` dan unique check. Hanya string: tipe lain (array/null)
     * dibiarkan agar aturan `string` yang menjawab (422, bukan 500).
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^08\d{8,12}$/', 'unique:customers,phone'],
            // Eksplisit: jangan Password::default() (prod bisa menambah syarat).
            'password' => ['required', 'string', Password::min(8)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Format nomor HP tidak valid (contoh: 081234567890).',
            'phone.unique' => 'Nomor HP sudah terdaftar.',
            'password.min' => 'Password minimal 8 karakter.',
        ];
    }
}
