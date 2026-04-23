<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stub FormRequest for the two-factor verify endpoint. Kept so the JSON
 * controller has a typed input contract even while the feature flag is
 * disabled — a later PR will replace the stub with the real flow.
 */
class TwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:6'],
        ];
    }
}
