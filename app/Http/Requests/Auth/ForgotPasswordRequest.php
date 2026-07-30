<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation for POST /forgot-password (Blade) and POST
 * /api/auth/forgot-password (JSON).
 *
 * NOTE: `exists:users,email` is intentionally NOT enforced here — the
 * controller returns the same 204 whether the email exists or not to avoid
 * leaking account existence (anti-enumeration). Keeping this rule only
 * checks the shape of the address.
 */
class ForgotPasswordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => User::normalizeEmail((string) $this->input('email'))]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }
}
