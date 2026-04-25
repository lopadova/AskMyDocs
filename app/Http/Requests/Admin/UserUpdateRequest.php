<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * PATCH /api/admin/users/{user} — partial update.
 *
 * Every field is `sometimes` so consumers can PATCH a subset. Email
 * uniqueness excludes the current row AND any soft-deleted rows (so a
 * previously-deleted ghost doesn't block email reassignment for the
 * live user).
 */
class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at'),
            ],
            'password' => ['sometimes', 'nullable', 'string', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ];
    }
}
