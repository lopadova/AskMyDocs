<?php

namespace App\Http\Requests\Admin;

use App\Support\RoleAssignmentGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * POST /api/admin/users — admin creates a user.
 *
 * Authorisation is enforced at the route layer (role:admin|super-admin);
 * this form only validates. Email uniqueness is checked across the live
 * + trashed set to prevent a soft-deleted ghost from blocking re-creation
 * while still catching genuine duplicates on the active table.
 */
class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'password' => ['required', 'string', Password::defaults()],
            'is_active' => ['nullable', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ];
    }

    /**
     * Privilege-escalation ceiling: an actor cannot grant a role carrying a
     * permission they do not themselves hold (e.g. a plain `admin` assigning
     * `super-admin`). See RoleAssignmentGuard.
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
