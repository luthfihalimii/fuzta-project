<?php

namespace App\Http\Requests;

use App\Modules\Identity\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('staff')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $staffId = $this->route('staff')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($staffId),
            ],
            'password' => ['sometimes', 'nullable', 'string', Password::default() ?? Password::min(8)],
            'role' => ['sometimes', Rule::enum(Role::class)],
            'branch_id' => [
                'nullable',
                'integer',
                'exists:branches,id',
                Rule::requiredIf(fn () => $this->has('role') && $this->input('role') !== Role::Owner->value),
            ],
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
