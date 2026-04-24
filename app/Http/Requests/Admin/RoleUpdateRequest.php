<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/admin/roles/{role} — rename + sync permissions.
 *
 * Role rename is allowed for non-system roles. The controller still
 * blocks any destructive touch on `super-admin` / `admin` (see
 * RoleController::update).
 */
class RoleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $roleId = $this->route('role')?->id;

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:120',
                Rule::unique('roles', 'name')->ignore($roleId),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }
}
