<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Canonical\CanonicalType;
use App\Support\Kb\SourceType;

/** Shared validation contract for text and realtime AgentRun scope. */
final class AgentChatScopeRules
{
    /** @return array<string,array<int,string>> */
    public static function rules(): array
    {
        $sourceTypes = collect(SourceType::cases())
            ->reject(fn (SourceType $type): bool => $type === SourceType::UNKNOWN)
            ->map(fn (SourceType $type): string => $type->value)
            ->all();
        $canonicalTypes = array_map(
            static fn (CanonicalType $type): string => $type->value,
            CanonicalType::cases(),
        );

        return [
            'filters' => ['nullable', 'array'],
            'filters.project_keys' => ['nullable', 'array'],
            'filters.project_keys.*' => ['string', 'max:120'],
            'filters.tag_slugs' => ['nullable', 'array'],
            'filters.tag_slugs.*' => ['string', 'max:120'],
            'filters.source_types' => ['nullable', 'array'],
            'filters.source_types.*' => ['string', 'in:'.implode(',', $sourceTypes)],
            'filters.canonical_types' => ['nullable', 'array'],
            'filters.canonical_types.*' => ['string', 'in:'.implode(',', $canonicalTypes)],
            'filters.connector_types' => ['nullable', 'array'],
            'filters.connector_types.*' => ['string', 'max:120'],
            'filters.doc_ids' => ['nullable', 'array'],
            'filters.doc_ids.*' => ['integer', 'min:1'],
            'filters.collection_id' => ['nullable', 'integer', 'min:1'],
            'filters.folder_globs' => ['nullable', 'array'],
            'filters.folder_globs.*' => ['string', 'max:255'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date', 'after_or_equal:filters.date_from'],
            'filters.languages' => ['nullable', 'array'],
            'filters.languages.*' => ['string', 'size:2'],
            'live_sources' => ['sometimes', 'array'],
            'live_sources.api' => ['sometimes', 'array', 'max:250'],
            'live_sources.api.*' => ['string', 'max:180'],
            'live_sources.mcp' => ['sometimes', 'array', 'max:250'],
            'live_sources.mcp.*' => ['string', 'max:180'],
        ];
    }
}
