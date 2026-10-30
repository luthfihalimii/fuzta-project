<?php

namespace App\Http\Requests;

use App\Modules\Identity\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role(), [Role::Owner, Role::Cashier, Role::Kitchen], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'supplier_id' => ['required', 'integer'], // exists di action → 404 sesuai spec
            'received_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today',
                'after_or_equal:'.now()->subDays(90)->toDateString()],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'items.*.unit_id' => ['required', 'integer', 'exists:units,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
        ];
    }
}
