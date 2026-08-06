<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class CompleteCompanyOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:200'],
            'tenant_slug' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/'],
            'project_key' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/'],
        ];
    }
}
