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
 * that a non-empty value was supplied.
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
            'email' => ['required', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email')],
            // `confirmed` pairs with `password_confirmation` from the form.
            'password' => ['required', 'confirmed', 'string', 'min:8'],
            'invite_code' => ['required', 'string', 'max:128'],
            // Only the Bearer flow (POST /api/auth/register-token) sends this;
            // the session flow ignores it. Optional label for the minted token.
            'device_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Human-readable label for the personal access token minted by the Bearer
     * sign-up flow. Mirrors {@see \App\Http\Requests\Auth\TokenRequest::deviceName()}:
     * a blank/absent value falls back to the server-side default in
     * {@see \App\Support\DesktopToken}.
     */
    public function deviceName(): ?string
    {
        $name = trim((string) $this->input('device_name', ''));

        return $name !== '' ? $name : null;
    }
}
