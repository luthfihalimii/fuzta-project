<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockTransferRequest extends FormRequest
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
            'origin_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'destination_branch_id' => ['required', 'integer', 'exists:branches,id', 'different:origin_branch_id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct', 'exists:ingredients,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
