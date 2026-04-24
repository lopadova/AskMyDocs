<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * POST /api/admin/users — admin creates a user.
 *
 * Authorisation is enforced at the route layer (role:admin|super-admin);
 * this form only validates. Email uniqueness is checked against LIVE rows
 * only (`whereNull('deleted_at')`) so a soft-deleted user does NOT block
 * re-creating the address. Trade-off: if the soft-deleted ghost is later
 * restored while the email has been reused, the restore path is
 * responsible for surfacing the conflict (or merging / reassigning). We
 * accept that cost because the common case — readmin sees "email
 * available" on a screen and re-invites the same address — would
 * otherwise fail 422 with a stale conflict that is not visible in the UI.
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
}
