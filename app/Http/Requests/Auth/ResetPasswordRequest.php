<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation for POST /reset-password (Blade) and POST
 * /api/auth/reset-password (JSON).
 */
class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'string', 'min:8'],
        ];
    }
}
