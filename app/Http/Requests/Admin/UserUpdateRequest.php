<?php

namespace App\Http\Requests\Admin;

use App\Support\RoleAssignmentGuard;
use Illuminate\Contracts\Validation\Validator;
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

    /**
     * Privilege-escalation ceiling: an actor cannot grant a role carrying a
     * permission they do not themselves hold (e.g. a plain `admin` promoting
     * an account — including their own — to `super-admin`). See
     * RoleAssignmentGuard.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('roles')) {
                return;
            }

            $disallowed = RoleAssignmentGuard::disallowedRoles(
                $this->user(),
                (array) $this->input('roles', []),
            );

            if ($disallowed !== []) {
                $validator->errors()->add(
                    'roles',
                    'You cannot assign a role with privileges you do not hold: '.implode(', ', $disallowed).'.',
                );
            }
        });
    }
}
