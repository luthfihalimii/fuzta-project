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
        $email = $this->input('email');

        if (is_string($phone)) {
            $this->merge([
                'phone' => PhoneNumber::normalize($phone),
            ]);
        }

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:customers,email'],
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
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah terdaftar.',
            'phone.regex' => 'Format nomor HP tidak valid (contoh: 081234567890).',
            'phone.unique' => 'Nomor HP sudah terdaftar.',
            'password.min' => 'Password minimal 8 karakter.',
        ];
    }
}
