<?php

namespace App\Http\Requests;

use App\Modules\Identity\Enums\Role;
use App\Modules\Ordering\Enums\OrderChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->is_active) {
            return false;
        }

        if (! in_array($user->role(), [Role::Cashier, Role::Owner], true)) {
            return false;
        }

        if ($user->isOwner()) {
            return true;
        }

        $branchId = $this->input('branch_id');

        return blank($branchId) || (int) $branchId === $user->branch_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => [
                'nullable',
                'integer',
                'exists:branches,id',
                Rule::requiredIf(fn () => $this->user()?->isOwner() ?? false),
            ],
            'channel' => ['required', Rule::enum(OrderChannel::class)],
            'table_number' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.modifier_ids' => ['sometimes', 'array'],
            'items.*.modifier_ids.*' => ['integer', 'distinct', 'exists:modifiers,id'],
            'items.*.quantity' => ['required', 'integer', 'between:1,99'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
