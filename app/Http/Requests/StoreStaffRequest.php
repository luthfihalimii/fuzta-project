<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::default() ?? Password::min(8)],
            'role' => ['required', Rule::enum(Role::class)],
            'branch_id' => [
                'nullable',
                'integer',
                'exists:branches,id',
                Rule::requiredIf(fn () => $this->input('role') !== Role::Owner->value),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
