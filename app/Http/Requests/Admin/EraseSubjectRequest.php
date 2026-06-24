<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v8.23 (Ciclo 4) — validate a GDPR Art.17 erasure request: one or more PII
 * value(s) (the subject's identifiers, e.g. email) to crypto-shred from the
 * tenant's reversible token vault.
 */
final class EraseSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-group middleware + the controller's pii.erase permission check
        // gate access; nothing per-row to authorize here.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'values' => ['required', 'array', 'min:1', 'max:100'],
            'values.*' => ['required', 'string', 'max:255'],
        ];
    }
}
