<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation contract for POST /login (Blade) and POST /api/auth/login
 * (JSON). Keeping a single FormRequest prevents the two flows from drifting
 * apart — rule changes land in both places automatically.
 */
class LoginRequest extends FormRequest
{
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
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => (string) $this->input('email'),
            'password' => (string) $this->input('password'),
        ];
    }

    /**
     * Throttle bucket key used by both the Blade and JSON flows.
     * Pairs the hashed email with the client IP so a single malicious actor
     * can't lock out a legitimate user simply by hammering their email.
     */
    public function throttleKey(): string
    {
        return mb_strtolower((string) $this->input('email')).'|'.$this->ip();
    }
}
