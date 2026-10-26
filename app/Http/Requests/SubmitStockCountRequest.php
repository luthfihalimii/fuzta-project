<?php

namespace App\Http\Requests;

use App\Modules\Identity\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

class SubmitStockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role(), [Role::Owner, Role::Kitchen], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'counted_items' => ['required', 'array', 'min:1'],
            'counted_items.*.ingredient_id' => ['required', 'integer', 'distinct', 'exists:ingredients,id'],
            'counted_items.*.counted_quantity' => ['required', 'integer', 'min:0'],
        ];
    }
}
