<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Support\Kb\SourceType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest for `POST /api/kb/chat` (T2.2 — enterprise filters).
 *
 * Accepts the pre-T2.2 shape (`question` + optional legacy
 * `project_key`) AND the new optional `filters` object that maps to
 * {@see RetrievalFilters}. When `filters.project_keys` is present it
 * takes precedence over the legacy `project_key`; when absent, the
 * legacy single-project key is wrapped into a single-element array
 * so back-compat is preserved bit-for-bit for every callers' current
 * payloads.
 *
 * Validation rules use `SourceType::cases()` to derive the allowed
 * source-type tokens — keeps R6 (docs and config must stay coupled):
 * adding a new SourceType case automatically extends the validator
 * without a separate edit here.
 */
final class KbChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $sourceTypeValues = collect(SourceType::cases())
            ->reject(fn (SourceType $t) => $t === SourceType::UNKNOWN)
            ->map(fn (SourceType $t) => $t->value)
            ->all();
        $sourceTypeRule = 'in:' . implode(',', $sourceTypeValues);

        return [
            'question' => ['required', 'string', 'max:10000'],

            // Legacy single-project key — kept for back-compat with every
            // pre-T2.2 caller. When `filters.project_keys` is also passed,
            // the latter wins (see toFilters()).
            'project_key' => ['nullable', 'string', 'max:120'],

            // New rich-filters payload (v3.0+). Every dimension is optional.
            'filters' => ['nullable', 'array'],

            'filters.project_keys' => ['nullable', 'array'],
            'filters.project_keys.*' => ['string', 'max:120'],

            'filters.tag_slugs' => ['nullable', 'array'],
            'filters.tag_slugs.*' => ['string', 'max:120'],

            'filters.source_types' => ['nullable', 'array'],
            'filters.source_types.*' => ['string', $sourceTypeRule],

            'filters.canonical_types' => ['nullable', 'array'],
            'filters.canonical_types.*' => ['string', 'max:120'],

            'filters.connector_types' => ['nullable', 'array'],
            'filters.connector_types.*' => ['string', 'max:120'],

            'filters.doc_ids' => ['nullable', 'array'],
            'filters.doc_ids.*' => ['integer', 'min:1'],

            'filters.folder_globs' => ['nullable', 'array'],
            'filters.folder_globs.*' => ['string', 'max:255'],

            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date', 'after_or_equal:filters.date_from'],

            'filters.languages' => ['nullable', 'array'],
            'filters.languages.*' => ['string', 'size:2'],
        ];
    }

    /**
     * Builds the {@see RetrievalFilters} DTO for this request.
     *
     * Resolution: explicit `filters.project_keys` wins; otherwise the
     * legacy `project_key` is wrapped into a single-element array so
     * pre-T2.2 single-tenant chat payloads keep producing the same
     * tenant scope.
     */
    public function toFilters(): RetrievalFilters
    {
        $f = $this->input('filters', []) ?? [];
        $legacyProject = $this->input('project_key');

        $projectKeys = $f['project_keys'] ?? null;
        if (! is_array($projectKeys) || $projectKeys === []) {
            $projectKeys = ($legacyProject !== null && $legacyProject !== '')
                ? [$legacyProject]
                : [];
        }

        return new RetrievalFilters(
            projectKeys: array_values(array_map('strval', $projectKeys)),
            tagSlugs: array_values(array_map('strval', $f['tag_slugs'] ?? [])),
            sourceTypes: array_values(array_map('strval', $f['source_types'] ?? [])),
            canonicalTypes: array_values(array_map('strval', $f['canonical_types'] ?? [])),
            connectorTypes: array_values(array_map('strval', $f['connector_types'] ?? [])),
            docIds: array_values(array_map('intval', $f['doc_ids'] ?? [])),
            folderGlobs: array_values(array_map('strval', $f['folder_globs'] ?? [])),
            dateFrom: $this->normaliseDate($f['date_from'] ?? null),
            dateTo: $this->normaliseDate($f['date_to'] ?? null),
            languages: array_values(array_map(
                fn ($v) => strtolower((string) $v),
                $f['languages'] ?? [],
            )),
        );
    }

    /**
     * Resolves the effective project key for the legacy meta payload —
     * the first element of `filters.project_keys` (when present and
     * non-empty), otherwise the legacy `project_key` field, otherwise
     * null. Used by the controller to populate the chat-log + the meta
     * `project_key` response field.
     */
    public function effectiveProjectKey(): ?string
    {
        $filtersProjects = $this->input('filters.project_keys');
        if (is_array($filtersProjects) && $filtersProjects !== []) {
            return (string) $filtersProjects[0];
        }
        $legacy = $this->input('project_key');
        return $legacy === null || $legacy === '' ? null : (string) $legacy;
    }

    private function normaliseDate(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        return $raw;
    }
}
