<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation contract for POST /api/auth/token — the Bearer-token issuance
 * endpoint used by non-browser clients (the Tauri desktop demo). Unlike
 * POST /api/auth/login it never opens a session: it verifies the credentials
 * and returns a Sanctum personal access token the client stores and sends as
 * `Authorization: Bearer <token>`.
 */
class TokenRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Human-readable label stored on the personal access token so a user can
     * tell devices apart in a future "manage sessions" view. Falls back to a
     * stable default when the client omits it.
     */
    public function deviceName(): string
    {
        $name = trim((string) $this->input('device_name', ''));

        return $name !== '' ? $name : 'desktop-demo';
    }

    /**
     * Throttle bucket, namespaced away from the login bucket so the two flows
     * count independently. Pairs the lower-cased email with the client IP.
     */
    public function throttleKey(): string
    {
        return 'token|'.mb_strtolower((string) $this->input('email')).'|'.$this->ip();
    }
}
