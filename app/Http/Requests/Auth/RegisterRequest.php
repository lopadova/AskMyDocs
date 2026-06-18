<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Public self-registration payload. The invite code is a HARD constraint when
 * `invite.invitation_required` is on (the default): no code, no account. When
 * the gate is off, a code is optional — supplied codes still provision the
 * account, absent ones create a plain user.
 *
 * The code is validated for redeemability in RegisterController (advisory
 * pre-check + the atomic redeem inside the registration transaction); here we
 * only enforce presence + shape so the gate can never be bypassed by omitting
 * the field.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'code' => [
                Rule::requiredIf(fn (): bool => $this->invitationRequired()),
                'nullable',
                'string',
                'max:128',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'An invite code is required to register.',
        ];
    }

    public function invitationRequired(): bool
    {
        return (bool) config('invite.invitation_required', true);
    }

    /**
     * The submitted invite code, or null when none was supplied. Normalization
     * is the CodeValidator/RedemptionService's job (one chokepoint) — we pass
     * the raw string through untouched.
     */
    public function inviteCode(): ?string
    {
        $code = trim((string) $this->input('code', ''));

        return $code === '' ? null : $code;
    }
}
