<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Kb\Pii\SubjectErasureService;
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
            'values' => ['required', 'array', 'min:1', 'max:'.SubjectErasureService::MAX_VALUES],
            // `regex:/\S/` rejects whitespace-only values, which would otherwise
            // pass validation yet normalise to an empty set — a misleading
            // no-op "success" instead of an explicit 422.
            'values.*' => ['required', 'string', 'max:'.SubjectErasureService::MAX_VALUE_LENGTH, 'regex:/\S/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'values.*.regex' => 'Each value must contain a non-whitespace character.',
        ];
    }
}
