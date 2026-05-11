<?php

declare(strict_types=1);

namespace App\Services\Kb\Chunking\Support;

use App\Services\Kb\Pipeline\ConvertedDocument;

/**
 * Reads the v4.5/W5.5 `_derived` block and the per-source namespaced
 * block from `ConvertedDocument::extractionMeta`. Shared by every
 * source-aware chunker so the metadata propagation shape stays
 * consistent across chunkers.
 *
 * `_derived` is the normalised, queryable layer the reranker consumes.
 * The per-source namespace (e.g. `notion.properties.status`) carries
 * raw vendor signals for debugging / future enrichers but is NOT
 * load-bearing for retrieval — the reranker only reads `_derived`.
 */
final class DerivedMetadataReader
{
    /**
     * @return array{
     *     search_tags: list<string>,
     *     status_active: bool,
     *     recency_bucket: string|null,
     *     owner: string|null,
     * }
     */
    public function read(ConvertedDocument $doc): array
    {
        $derived = $doc->extractionMeta['_derived'] ?? [];
        if (! is_array($derived)) {
            $derived = [];
        }

        $tags = $derived['search_tags'] ?? [];
        if (! is_array($tags)) {
            $tags = [];
        }
        $normTags = array_values(array_unique(array_filter(
            array_map(static fn ($t) => is_string($t) ? trim($t) : '', $tags),
            static fn (string $t): bool => $t !== '',
        )));

        $owner = $derived['owner'] ?? null;
        $bucket = $derived['recency_bucket'] ?? null;

        return [
            'search_tags' => $normTags,
            'status_active' => (bool) ($derived['status_active'] ?? false),
            'recency_bucket' => is_string($bucket) && $bucket !== '' ? $bucket : null,
            'owner' => is_string($owner) && $owner !== '' ? $owner : null,
        ];
    }
}
