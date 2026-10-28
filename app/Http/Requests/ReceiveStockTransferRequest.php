<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isOwner();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'received_items' => ['required', 'array'],
            'received_items.*.item_id' => ['required', 'integer', 'distinct', 'exists:stock_transfer_items,id'],
            'received_items.*.received_quantity' => ['required', 'integer', 'min:0'],
            'received_items.*.discrepancy_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
