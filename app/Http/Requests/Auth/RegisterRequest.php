<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/auth/register (the React SPA sign-up).
 *
 * Registration is INVITE-ONLY: `invite_code` is always required regardless of
 * the `invitations.invitation_required` flag (product decision). The code's
 * actual validity (active / not expired / not exhausted / eligible) is checked
 * against the invite engine in RegisterController — this layer only enforces
 * that a non-empty, well-formed value was supplied.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // `confirmed` pairs with `password_confirmation` from the form.
            'password' => ['required', 'confirmed', 'string', 'min:8'],
            'invite_code' => ['required', 'string', 'max:128'],
        ];
    }
}
