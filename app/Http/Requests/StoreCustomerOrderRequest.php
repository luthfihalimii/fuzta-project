<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'fulfillment' => ['required', 'string', Rule::in(['pickup', 'delivery'])],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'regex:/^08[0-9]{8,13}$/'],
            'note' => ['nullable', 'string', 'max:500'],
            'delivery_address' => ['required_if:fulfillment,delivery', 'string', 'max:500'],
            'delivery_area_id' => ['nullable', 'string', 'max:64', 'required_if:fulfillment,delivery'],
            'courier_name' => ['nullable', 'string', 'max:100', 'required_if:fulfillment,delivery'],
            'courier_service' => ['nullable', 'string', 'max:100', 'required_if:fulfillment,delivery'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.modifier_ids' => ['sometimes', 'array'],
            'items.*.modifier_ids.*' => ['integer', 'distinct', 'exists:modifiers,id'],
            'items.*.quantity' => ['required', 'integer', 'between:1,99'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fulfillment.in' => 'Jenis pesanan harus pickup atau delivery.',
            'customer_phone.regex' => 'Format nomor HP tidak valid (contoh: 081234567890).',
        ];
    }
}
