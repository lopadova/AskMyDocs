<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation contract for POST /login (Blade) and POST /api/auth/login
 * (JSON). Keeping a single FormRequest prevents the two flows from drifting
 * apart — rule changes land in both places automatically.
 */
class LoginRequest extends FormRequest
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
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Credentials subset passed to Auth::attempt().
     *
     * Laravel's Eloquent user provider accepts a closure constraint. Using it
     * lets the model select email_normalized after the additive migration and
     * a case-insensitive legacy email lookup before it.
     *
     * @return array{email: \Closure(Builder<User>): void, password: string, is_active: bool}
     */
    public function credentials(): array
    {
        $email = User::normalizeEmail((string) $this->input('email'));

        return [
            'email' => static function (Builder $query) use ($email): void {
                $query->whereEmailIdentity($email);
            },
            'password' => (string) $this->input('password'),
            'is_active' => true,
        ];
    }

    /**
     * Throttle bucket key used by both the Blade and JSON flows.
     * Pairs the lower-cased email with the client IP so a single malicious
     * actor can't lock out a legitimate user simply by hammering their email
     * (case variants collapse to the same bucket).
     */
    public function throttleKey(): string
    {
        return mb_strtolower((string) $this->input('email')).'|'.$this->ip();
    }
}
