<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\Kb\SourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Admin-only validation for a project retrieval profile. */
final class UpsertKbRetrievalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'project_key' => ['required', 'string', 'max:120', 'not_in:*'],
            'company_context' => ['required', 'string', 'min:20', 'max:8000'],
            'glossary' => ['nullable', 'array', 'max:80'],
            'glossary.*.term' => ['required', 'string', 'max:120'],
            'glossary.*.meaning' => ['required', 'string', 'max:600'],
            'glossary.*.aliases' => ['nullable', 'array', 'max:12'],
            'glossary.*.aliases.*' => ['string', 'max:120'],
            'relevant_entities' => ['nullable', 'array', 'max:80'],
            'relevant_entities.*' => ['string', 'max:160'],
            'expected_facts' => ['nullable', 'array', 'max:80'],
            'expected_facts.*' => ['string', 'max:240'],
            'preferred_source_types' => ['nullable', 'array', 'max:16'],
            'preferred_source_types.*' => ['string', Rule::in(array_map(
                static fn (SourceType $type): string => $type->value,
                SourceType::cases(),
            ))],
        ];
    }
}
