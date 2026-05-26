<?php

declare(strict_types=1);

namespace App\Services\Kb;

use App\Ai\EmbeddingsResponse;
use App\Jobs\CanonicalIndexerJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Canonical\CanonicalParsedDocument;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\PipelineRegistry;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Document ingestion pipeline.
 *
 * v3.0 (T1.4) introduces a polymorphic {@see ingest()} entry point that
 * walks every source through the {@see PipelineRegistry}: pick a Converter
 * by MIME, normalise to {@see Pipeline\ConvertedDocument}, pick a Chunker
 * by source-type, persist the resulting {@see ChunkDraft}[] alongside the
 * embedding cache. The pre-v3 {@see ingestMarkdown()} entry point is now
 * a thin facade that synthesises a markdown SourceDocument and delegates
 * to `ingest()` — the IngestDocumentJob keeps using it bit-for-bit.
 *
 * Canonical compilation (Phase 2 / R10): markdown frontmatter is parsed
 * once per ingest and projected into the canonical columns + the
 * `kb_nodes` / `kb_edges` graph (via {@see CanonicalIndexerJob} dispatched
 * post-commit). Malformed frontmatter degrades gracefully — the document
 * is still ingested as non-canonical, the failure is logged.
 */
class DocumentIngestor
{
    protected CanonicalParser $canonicalParser;

    public function __construct(
        protected PipelineRegistry $registry,
        protected EmbeddingCacheService $embeddingCache,
        ?CanonicalParser $canonicalParser = null,
    ) {
        // CanonicalParser is stateless and has no deps of its own, so we
        // can default-instantiate it when callers don't wire it explicitly
        // (e.g. legacy unit tests built before Phase 2).
        $this->canonicalParser = $canonicalParser ?? new CanonicalParser();
    }

    /**
     * v3 polymorphic entry point. Routes the source through the
     * {@see PipelineRegistry}: Converter → ConvertedDocument → Chunker
     * → ChunkDraft[] → persist + embed + canonical project + dispatch.
     *
     * `$source->sourcePath` is normalised through {@see KbPath::normalize()}
     * here as a safety net so every consumer (HTTP, CLI, jobs, future
     * connectors) lands on identical idempotency keys regardless of how
     * carefully the caller pre-normalised. Per R1 in CLAUDE.md.
     *
     * @param  array<string,mixed>  $extraMetadata  merged on top of $source->metadata
     */
    public function ingest(
        string $projectKey,
        SourceDocument $source,
        string $title,
        array $extraMetadata = [],
    ): KnowledgeDocument {
        $normalizedPath = KbPath::normalize($source->sourcePath);
        $normalizedSource = $source->sourcePath === $normalizedPath
            ? $source
            : new SourceDocument(
                sourcePath: $normalizedPath,
                mimeType: $source->mimeType,
                bytes: $source->bytes,
                externalUrl: $source->externalUrl,
                externalId: $source->externalId,
                connectorType: $source->connectorType,
                metadata: $source->metadata,
            );

        $converter = $this->registry->resolveConverter($normalizedSource->mimeType);
        $converted = $converter->convert($normalizedSource);

        $sourceType = $this->resolveSourceType($normalizedSource->mimeType);
        $chunker = $this->registry->resolveChunker($sourceType);

        // v4.5/W5.5 — projects the resolved source-type token AND every
        // connector-supplied namespaced block (notion/confluence/...) +
        // the `_derived` map into `extractionMeta` so the chunker can
        // dispatch on the token AND read the connector signals without
        // needing a second constructor parameter on ChunkerInterface.
        // The connector packs the data under `SourceDocument::metadata['converter_hints']`
        // for transport; here we merge it into `extractionMeta` so the
        // chunker reads a single, uniform surface.
        $converted = $this->projectChunkerHints($converted, $normalizedSource, $sourceType);
        $chunkDrafts = $chunker->chunk($converted);

        $combinedMetadata = array_merge($normalizedSource->metadata, $extraMetadata, [
            'connector' => $normalizedSource->connectorType,
            'external_url' => $normalizedSource->externalUrl,
            'external_id' => $normalizedSource->externalId,
            'converter' => $converted->extractionMeta,
        ]);

        return $this->persistFromDrafts(
            projectKey: $projectKey,
            sourcePath: $normalizedSource->sourcePath,
            title: $title,
            mimeType: $normalizedSource->mimeType,
            sourceType: $sourceType,
            markdown: $converted->markdown,
            chunkDrafts: $chunkDrafts,
            metadata: $combinedMetadata,
        );
    }

    /**
     * Project the source-type token plus every connector-supplied hint
     * onto the converter output so source-aware chunkers can read a
     * single uniform surface.
     *
     * Recognised keys lifted from `SourceDocument::metadata`:
     *  - `converter_hints`: free-form namespaced bag, e.g.
     *      `['notion' => [...], '_derived' => [...]]` written by the
     *      v4.5 connectors per DESIGN-v4.5-W5.5 §Layer 1.
     *  - `_derived`: top-level shorthand for connectors that bypass
     *      the `converter_hints` wrapper.
     *
     * Any non-array hint payload is silently dropped — the chunker's
     * DerivedMetadataReader guards against missing/malformed shapes.
     */
    private function projectChunkerHints(
        \App\Services\Kb\Pipeline\ConvertedDocument $converted,
        \App\Services\Kb\Pipeline\SourceDocument $source,
        string $sourceType,
    ): \App\Services\Kb\Pipeline\ConvertedDocument {
        $extra = ['source_type' => $sourceType];

        $hints = $source->metadata['converter_hints'] ?? null;
        if (is_array($hints)) {
            foreach ($hints as $key => $value) {
                if (is_string($key) && $key !== '') {
                    $extra[$key] = $value;
                }
            }
        }

        $derived = $source->metadata['_derived'] ?? null;
        if (is_array($derived) && ! isset($extra['_derived'])) {
            $extra['_derived'] = $derived;
        }

        if ($extra === ['source_type' => $sourceType] && ! isset($converted->extractionMeta['source_type'])) {
            // No connector hints to merge — only tag the source_type so
            // chunkers that dispatch on it have the token available.
            return new \App\Services\Kb\Pipeline\ConvertedDocument(
                markdown: $converted->markdown,
                mediaItems: $converted->mediaItems,
                extractionMeta: array_merge($converted->extractionMeta, $extra),
                sourceMimeType: $converted->sourceMimeType,
            );
        }

        return new \App\Services\Kb\Pipeline\ConvertedDocument(
            markdown: $converted->markdown,
            mediaItems: $converted->mediaItems,
            extractionMeta: array_merge($converted->extractionMeta, $extra),
            sourceMimeType: $converted->sourceMimeType,
        );
    }

    /**
     * Maps a MIME type to the source-type token used for chunker resolution.
     *
     * Throws an actionable RuntimeException when the mapping is missing —
     * the failure mode of "fall back to 'unknown' then fail at chunker
     * lookup with 'No chunker registered for source type: unknown'" is
     * misleading and points the operator at the wrong file. Surfaces the
     * MIME type AND the config file the operator must edit.
     */
    private function resolveSourceType(string $mimeType): string
    {
        /** @var array<string, mixed> $map */
        $map = (array) config('kb-pipeline.mime_to_source_type', []);
        if (! array_key_exists($mimeType, $map)) {
            throw new RuntimeException(sprintf(
                'Missing MIME→source-type mapping for "%s". Add it to config/kb-pipeline.php under "mime_to_source_type".',
                $mimeType,
            ));
        }
        return (string) $map[$mimeType];
    }

    /**
     * Pre-v3 facade — kept for IngestDocumentJob, KbIngestController, and
     * the consumer GitHub Action that still POST plain markdown bytes.
     * Synthesises a `text/markdown` SourceDocument and delegates to
     * {@see ingest()} so behaviour is now driven by the registry path.
     */
    public function ingestMarkdown(
        string $projectKey,
        string $sourcePath,
        string $title,
        string $markdown,
        array $metadata = [],
    ): KnowledgeDocument {
        return $this->ingest(
            projectKey: $projectKey,
            source: new SourceDocument(
                sourcePath: $sourcePath,
                mimeType: 'text/markdown',
                bytes: $markdown,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: $metadata,
            ),
            title: $title,
        );
    }

    // -----------------------------------------------------------------
    // lookup
    // -----------------------------------------------------------------

    /**
     * R30/R31 — tenant-scoped lookup. Two tenants may legitimately ingest the
     * same `(project_key, source_path)` with the same content (and therefore
     * the same SHA-256 `version_hash`); without the tenant filter the second
     * tenant's ingest would find tenant A's row, treat it as an idempotent
     * no-op, and bump tenant A's `indexed_at` instead of creating tenant B's
     * row. Closes the cross-tenant leak Copilot flagged on PR #115 iteration 2.
     */
    private function findExistingVersion(string $projectKey, string $sourcePath, string $versionHash): ?KnowledgeDocument
    {
        return KnowledgeDocument::forTenant(app(TenantContext::class)->current())
            ->where('project_key', $projectKey)
            ->where('source_path', $sourcePath)
            ->where('version_hash', $versionHash)
            ->first();
    }

    // -----------------------------------------------------------------
    // canonical awareness (gracefully degrades on parse/validation errors)
    // -----------------------------------------------------------------

    private function tryParseCanonical(string $projectKey, string $sourcePath, string $markdown): ?CanonicalParsedDocument
    {
        if (! (bool) config('kb.canonical.enabled', true)) {
            return null;
        }
        $parsed = $this->canonicalParser->parse($markdown);
        if ($parsed === null) {
            return null;
        }

        $validation = $this->canonicalParser->validate($parsed);
        if ($validation->valid) {
            return $parsed;
        }

        Log::warning('Canonical frontmatter present but invalid; ingesting as non-canonical.', [
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'errors' => $validation->errors,
        ]);
        return null;
    }

    // -----------------------------------------------------------------
    // public persistence (v4.2 / W2 — Flow-orchestrated entry point)
    // -----------------------------------------------------------------

    /**
     * Public persistence path for the {@see \App\Flow\Definitions\IngestDocumentFlow}
     * `persist-chunks` step.
     *
     * Mirrors the semantics of {@see persistFromDrafts()} (idempotent on
     * version_hash, transactional, archives prior versions) but accepts
     * the canonical parse result and the embeddings response from earlier
     * Flow steps instead of computing them internally. The post-commit
     * canonical-indexer dispatch is INTENTIONALLY left out — the flow's
     * `maybe-dispatch-canonical-indexer` step owns it so a compensator
     * can short-circuit if the indexer is mocked-to-fail in a saga test.
     *
     * @param  list<ChunkDraft>     $chunkDrafts
     * @param  array<string,mixed>  $metadata
     */
    public function persistDrafts(
        string $projectKey,
        string $sourcePath,
        string $title,
        string $mimeType,
        string $sourceType,
        string $markdown,
        array $chunkDrafts,
        array $metadata,
        EmbeddingsResponse $embeddingResponse,
        ?CanonicalParsedDocument $canonical,
    ): KnowledgeDocument {
        $documentHash = hash('sha256', $markdown);
        $versionHash = $documentHash;

        $existing = $this->findExistingVersion($projectKey, $sourcePath, $versionHash);
        if ($existing !== null) {
            $existing->update(['indexed_at' => now()]);
            return $existing;
        }

        return DB::transaction(fn () => $this->persistDocumentAndChunks(
            $projectKey,
            $sourcePath,
            $title,
            $mimeType,
            $sourceType,
            $metadata,
            $documentHash,
            $versionHash,
            $chunkDrafts,
            $embeddingResponse,
            $canonical,
        ));
    }

    // -----------------------------------------------------------------
    // unified persistence (v3 — accepts ChunkDraft[])
    // -----------------------------------------------------------------

    /**
     * Single persistence path for both the v3 polymorphic {@see ingest()}
     * AND the legacy markdown facade. Holds the SHA-256 versioning,
     * canonical projection, chunks insert, embedding cache, and
     * post-commit job dispatch — the join point any new converter
     * (T1.5 PDF, T1.6 DOCX) plugs into via {@see ingest()}.
     *
     * @param  list<ChunkDraft>     $chunkDrafts
     * @param  array<string,mixed>  $metadata
     */
    private function persistFromDrafts(
        string $projectKey,
        string $sourcePath,
        string $title,
        string $mimeType,
        string $sourceType,
        string $markdown,
        array $chunkDrafts,
        array $metadata,
    ): KnowledgeDocument {
        $documentHash = hash('sha256', $markdown);
        $versionHash = $documentHash;

        $existing = $this->findExistingVersion($projectKey, $sourcePath, $versionHash);
        if ($existing !== null) {
            $existing->update(['indexed_at' => now()]);
            return $existing;
        }

        $canonical = $this->tryParseCanonical($projectKey, $sourcePath, $markdown);
        $embeddingResponse = $this->embeddingCache->generate(
            array_map(fn (ChunkDraft $d) => $d->text, $chunkDrafts),
        );

        $document = DB::transaction(fn () => $this->persistDocumentAndChunks(
            $projectKey,
            $sourcePath,
            $title,
            $mimeType,
            $sourceType,
            $metadata,
            $documentHash,
            $versionHash,
            $chunkDrafts,
            $embeddingResponse,
            $canonical,
        ));

        $this->dispatchCanonicalIndexerIfCanonical($document);

        return $document;
    }

    // -----------------------------------------------------------------
    // persistence (wrapped in transaction by the caller)
    // -----------------------------------------------------------------

    /**
     * @param  list<ChunkDraft>     $chunkDrafts
     * @param  array<string,mixed>  $metadata
     */
    private function persistDocumentAndChunks(
        string $projectKey,
        string $sourcePath,
        string $title,
        string $mimeType,
        string $sourceType,
        array $metadata,
        string $documentHash,
        string $versionHash,
        array $chunkDrafts,
        $embeddingResponse,
        ?CanonicalParsedDocument $canonical,
    ): KnowledgeDocument {
        // If this is a canonical re-ingest with changed content, previous
        // versions still hold the (project_key, slug) / (project_key, doc_id)
        // unique slots. We must vacate those slots BEFORE the updateOrCreate
        // below, otherwise the insert violates `uq_kb_doc_slug` / `uq_kb_doc_doc_id`.
        if ($canonical !== null) {
            $this->vacateCanonicalIdentifiersOnPreviousVersions($projectKey, $sourcePath, $versionHash);
        }

        $tenantId = app(TenantContext::class)->current();

        $attributes = $this->buildDocumentAttributes(
            $title,
            $mimeType,
            $sourceType,
            $metadata,
            $documentHash,
            $canonical,
        );
        // R30/R31 — tenant_id is part of the lookup keys so two tenants
        // ingesting the same `(project_key, source_path, version_hash)`
        // tuple produce two distinct rows instead of one tenant clobbering
        // the other. The BelongsToTenant trait would auto-fill tenant_id on
        // a fresh insert, but updateOrCreate's lookup phase ignores it
        // unless we pass it explicitly.
        $document = KnowledgeDocument::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'project_key' => $projectKey,
                'source_path' => $sourcePath,
                'version_hash' => $versionHash,
            ],
            $attributes,
        );

        $this->archivePreviousVersions($projectKey, $sourcePath, $document->id);
        $this->persistChunks($document, $projectKey, $chunkDrafts, $embeddingResponse);

        return $document;
    }

    /**
     * Only the latest (live) version of a canonical document holds its
     * `doc_id` / `slug` / `is_canonical=true` identity. Older versions for
     * the same (project_key, source_path) get their canonical identifiers
     * nulled here so the composite uniques `(project_key, slug)` and
     * `(project_key, doc_id)` can accept the new version.
     *
     * Without this step, re-ingesting a canonical doc with changed content
     * would try to INSERT a new row that collides on the unique slots still
     * occupied by the archived-but-not-yet-vacated sibling row.
     */
    private function vacateCanonicalIdentifiersOnPreviousVersions(
        string $projectKey,
        string $sourcePath,
        string $newVersionHash,
    ): void {
        // R30/R31 — scope by tenant_id so vacating a re-ingest's prior
        // versions never accidentally nulls another tenant's canonical
        // identifiers (slug + doc_id are tenant-scoped per CLAUDE.md R10).
        KnowledgeDocument::forTenant(app(TenantContext::class)->current())
            ->where('project_key', $projectKey)
            ->where('source_path', $sourcePath)
            ->where('version_hash', '!=', $newVersionHash)
            ->update([
                'doc_id' => null,
                'slug' => null,
                'canonical_status' => null,
                'is_canonical' => false,
                // `canonical_type` is preserved on the archived row so
                // audit/history queries can still reconstruct its type.
                // `frontmatter_json` is preserved for the same reason.
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDocumentAttributes(
        string $title,
        string $mimeType,
        string $sourceType,
        array $metadata,
        string $documentHash,
        ?CanonicalParsedDocument $canonical,
    ): array {
        $base = [
            'source_type' => $sourceType,
            'title' => $title,
            'mime_type' => $mimeType,
            // `??` would let `null` fall through to the default but would NOT
            // catch empty-string or non-string values that connectors might
            // emit. Defensive normalisation prevents blank or invalid values
            // from being persisted for these fields and overriding the
            // intended defaults / domain invariants (`'it'`, `'internal'`).
            'language' => $this->normalizeStringMeta($metadata['language'] ?? null, 'it'),
            'access_scope' => $this->normalizeStringMeta($metadata['access_scope'] ?? null, 'internal'),
            'status' => 'active',
            'document_hash' => $documentHash,
            'metadata' => $metadata,
            'indexed_at' => now(),
        ];
        if ($canonical === null) {
            return array_merge($base, [
                'is_canonical' => false,
                'doc_id' => null,
                'slug' => null,
                'canonical_type' => null,
                'canonical_status' => null,
                'retrieval_priority' => 50,
                'frontmatter_json' => null,
            ]);
        }

        return array_merge($base, [
            'is_canonical' => true,
            'doc_id' => $canonical->docId,
            'slug' => $canonical->slug,
            'canonical_type' => $canonical->type?->value,
            'canonical_status' => $canonical->status?->value,
            'retrieval_priority' => $canonical->retrievalPriority,
            'frontmatter_json' => array_merge($canonical->frontmatter, [
                '_derived' => [
                    'related_slugs' => $canonical->relatedSlugs,
                    'supersedes_slugs' => $canonical->supersedesSlugs,
                    'superseded_by_slugs' => $canonical->supersededBySlugs,
                    'tags' => $canonical->tags,
                    'owners' => $canonical->owners,
                    'summary' => $canonical->summary,
                ],
            ]),
        ]);
    }

    /**
     * Returns `$default` for any non-string OR empty/whitespace-only string.
     * Used by {@see buildDocumentAttributes()} to keep metadata-backed
     * fields such as `language` and `access_scope` from being overridden by
     * connector payloads that send `null`, `''`, or `'   '` for those keys —
     * the bare `??` operator would preserve those blank strings instead of
     * preserving the intended defaults / required domain values.
     */
    private function normalizeStringMeta(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $default;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? $default : $trimmed;
    }

    private function archivePreviousVersions(string $projectKey, string $sourcePath, int $currentDocumentId): void
    {
        // R30/R31 — scope by tenant_id so a re-ingest under tenant A never
        // archives the same `(project_key, source_path)` row owned by
        // tenant B. project_key + source_path are NOT globally unique.
        KnowledgeDocument::query()
            ->forTenant(app(TenantContext::class)->current())
            ->where('project_key', $projectKey)
            ->where('source_path', $sourcePath)
            ->where('id', '!=', $currentDocumentId)
            ->where('status', '!=', 'archived')
            ->update(['status' => 'archived']);
    }

    /**
     * Persists each ChunkDraft. `chunk_order` comes from the DTO's own
     * `$chunk->order` (the SOT) so future chunkers (e.g. PdfPageChunker
     * in T1.7) can emit non-sequential or page-numbered orders without
     * losing them at persistence time. Embeddings stay positional —
     * EmbeddingCacheService::generate() returned them in the same order
     * we passed the draft texts, so `$index` is the correct embedding
     * lookup key even when `$chunk->order` is unrelated.
     *
     * @param  list<ChunkDraft>  $chunkDrafts
     */
    private function persistChunks(
        KnowledgeDocument $document,
        string $projectKey,
        array $chunkDrafts,
        $embeddingResponse,
    ): void {
        foreach ($chunkDrafts as $index => $chunk) {
            KnowledgeChunk::updateOrCreate(
                [
                    'knowledge_document_id' => $document->id,
                    'chunk_hash' => hash('sha256', $chunk->text),
                ],
                [
                    'project_key' => $projectKey,
                    'chunk_order' => $chunk->order,
                    'heading_path' => $chunk->headingPath,
                    'chunk_text' => $chunk->text,
                    'metadata' => $chunk->metadata,
                    'embedding' => $embeddingResponse->embeddings[$index],
                ]
            );
        }
    }

    // -----------------------------------------------------------------
    // post-commit job dispatch
    // -----------------------------------------------------------------

    private function dispatchCanonicalIndexerIfCanonical(KnowledgeDocument $document): void
    {
        if (! $document->is_canonical) {
            return;
        }
        // PR #115 review iteration 1 — capture the active tenant at
        // dispatch time so the queue worker re-binds it before any
        // tenant-aware Eloquent query runs in CanonicalIndexerJob.
        $tenantId = app(TenantContext::class)->current();
        CanonicalIndexerJob::dispatch($document->id, $tenantId);
    }
}
