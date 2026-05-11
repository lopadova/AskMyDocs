<?php

declare(strict_types=1);

namespace App\Connectors\Support;

use App\Services\Kb\Chunking\Support\RecencyBucketer;

/**
 * Builds the v4.5/W5.5 rich-metadata envelope every connector hands to
 * IngestDocumentJob so source-aware chunkers and the reranker can do
 * their job downstream.
 *
 * Two slots:
 *
 *   - `metadata.converter_hints.<source>` carries the raw vendor-
 *     namespaced bag (notion: { database_id, properties: {...} },
 *     confluence: { space_key, labels: [...], version, ancestor_titles
 *     }, ...). DocumentIngestor lifts the whole bag into
 *     ConvertedDocument::extractionMeta so chunkers can read it.
 *
 *   - `metadata.converter_hints._derived` carries the four normalised
 *     reranker-facing signals (search_tags, status_active,
 *     recency_bucket, owner). Always present — connectors fill what
 *     they have; missing signals safely degrade to no-boost at
 *     retrieval time.
 *
 * The wrapper is stateless and deterministic so connectors call it
 * inline at write-time without DI gymnastics. RecencyBucketer is
 * default-instantiated for the same reason; tests can swap it.
 */
final class SourceAwareMetadataBuilder
{
    private RecencyBucketer $recency;

    public function __construct(?RecencyBucketer $recency = null)
    {
        $this->recency = $recency ?? new RecencyBucketer();
    }

    /**
     * Build the full `metadata` array a connector passes to
     * `IngestDocumentJob::dispatch(metadata: ...)`.
     *
     * @param  array<string,mixed>  $base           Connector-level metadata (connector key, installation_id, ...).
     * @param  string               $sourceKey      The per-source namespace key (e.g. 'notion', 'confluence', 'evernote', 'fabric', 'google_drive', 'onedrive').
     * @param  array<string,mixed>  $sourceFields   The raw vendor-shaped fields to publish under that namespace.
     * @param  list<string>         $tags           Search tags lifted by the connector (Notion multi_select, Confluence labels, Evernote tags, ...).
     * @param  bool|null            $statusActive   Whether the source row is currently "active" (status != Done, status != Archived, etc.).
     *                                              Null when the source has no concept of "status" — degrades to false.
     * @param  mixed                $lastModified   The source's last-modified timestamp (string/DateTimeInterface) — bucketed to recency.
     * @param  string|null          $owner          Single-owner email (or null when shared/unknown).
     * @return array<string,mixed>
     */
    public function build(
        array $base,
        string $sourceKey,
        array $sourceFields,
        array $tags = [],
        ?bool $statusActive = null,
        mixed $lastModified = null,
        ?string $owner = null,
    ): array {
        $cleanTags = array_values(array_unique(array_filter(
            array_map(static fn ($t) => is_string($t) ? trim($t) : '', $tags),
            static fn (string $t): bool => $t !== '',
        )));

        $derived = [
            'search_tags' => $cleanTags,
            'status_active' => (bool) $statusActive,
            'recency_bucket' => $this->recency->bucket($lastModified),
            'owner' => $owner,
        ];

        $hints = [
            $sourceKey => $sourceFields,
            '_derived' => $derived,
        ];

        return array_merge($base, [
            'converter_hints' => $hints,
            // Flat-projected `_derived` mirrors the hints map so any
            // downstream reader that bypasses `converter_hints` (legacy
            // shape, future jobs that take a `_derived` arg directly)
            // still resolves the same signals.
            '_derived' => $derived,
        ]);
    }
}
