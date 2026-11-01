<?php

namespace App\Http\Requests;

use App\Modules\Customers\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerAddressRequest extends FormRequest
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
     * Semantik PATCH: semua field `sometimes`, hanya yang dikirim yang
     * divalidasi dan ditulis.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:40'],
            'recipient_name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'regex:/^08\d{8,12}$/'],
            'address' => ['sometimes', 'string', 'max:500'],
            'area_id' => ['sometimes', 'string', 'max:64'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Format nomor HP tidak valid (contoh: 081234567890).',
        ];
    }
}
