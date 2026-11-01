<?php

namespace App\Http\Requests;

use App\Modules\Customers\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerAddressRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:40'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^08\d{8,12}$/'],
            'address' => ['required', 'string', 'max:500'],
            'area_id' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => 'Label alamat wajib diisi.',
            'recipient_name.required' => 'Nama penerima wajib diisi.',
            'phone.required' => 'Nomor HP wajib diisi.',
            'phone.regex' => 'Format nomor HP tidak valid (contoh: 081234567890).',
            'address.required' => 'Alamat wajib diisi.',
            'area_id.required' => 'Area pengiriman wajib dipilih.',
        ];
    }
}
