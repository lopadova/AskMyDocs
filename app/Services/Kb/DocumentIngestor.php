<?php

declare(strict_types=1);

namespace App\Services\Kb;

use App\Ai\EmbeddingsResponse;
use App\Jobs\CanonicalIndexerJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Canonical\CanonicalParsedDocument;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Services\Kb\Access\SourceAclMirror;
use App\Services\Kb\Provenance\ProvenanceResolver;
use Padosoft\AskMyDocsConnectorBase\Access\SourceAccess;
use App\Services\Kb\Pii\ChunkRedactor;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\PipelineRegistry;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\Canonical\GenerationSource;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Services\Kb\Versioning\SourceRetentionResolver;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    protected ProvenanceResolver $provenanceResolver;

    public function __construct(
        protected PipelineRegistry $registry,
        protected EmbeddingCacheService $embeddingCache,
        ?CanonicalParser $canonicalParser = null,
        ?ProvenanceResolver $provenanceResolver = null,
    ) {
        // CanonicalParser is stateless and has no deps of its own, so we
        // can default-instantiate it when callers don't wire it explicitly
        // (e.g. legacy unit tests built before Phase 2).
        $this->canonicalParser = $canonicalParser ?? new CanonicalParser();
        // Same reason, plus: the resolver only needs the connector registry,
        // which the container already builds. Defaulting keeps every existing
        // direct-construction call site working unchanged.
        $this->provenanceResolver = $provenanceResolver ?? app(ProvenanceResolver::class);
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
        bool $forceReembed = false,
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
            forceReembed: $forceReembed,
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
        bool $replaceExisting = false,
    ): KnowledgeDocument {
        $documentHash = hash('sha256', $markdown);
        $versionHash = $documentHash;

        // v8.36 / ADR 0029 — a forced OCR re-run (`metadata.ocr.force`) that
        // produced byte-identical Markdown is still a NEW run: its chunks and
        // its `converter.ocr` block (run key, attempt, confidence) replace the
        // existing version's instead of being dropped by the same-hash guard
        // — otherwise the row would keep pointing at the previous run while
        // the new one was billed and recorded. Same mechanics as forceReembed.
        if (! $replaceExisting) {
            $existing = $this->findExistingVersion($projectKey, $sourcePath, $versionHash);
            if ($existing !== null) {
                $existing->update(['indexed_at' => now()]);
                $this->repairArtifactOfExistingVersion($existing, $markdown, $metadata);

                return $existing;
            }
        }

        // v8.36 / ADR 0030 §3 — the artifact temp file is written BEFORE the
        // transaction and moved into place only after commit; a failed
        // transaction discards this attempt's temp and nothing else.
        $artifact = $this->stageArtifact($projectKey, $sourcePath, $versionHash, $markdown, $metadata);
        try {
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
                $replaceExisting,
                $artifact,
            ));
        } catch (\Throwable $e) {
            $this->discardArtifact($artifact);
            throw $e;
        }
        $this->publishArtifactOrLog($artifact, $document, $sourceType, $metadata);

        return $document;
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
        bool $forceReembed = false,
    ): KnowledgeDocument {
        $documentHash = hash('sha256', $markdown);
        $versionHash = $documentHash;

        // v8.23 (Ciclo 4, PR5) — a `forceReembed` re-ingest (after a PII-policy
        // change) deliberately SKIPS the version_hash idempotency short-circuit:
        // the raw markdown — and thus the hash — is unchanged, but the chunks
        // must be re-derived + re-embedded under the NEW policy. persistDocument-
        // AndChunks then REPLACES the existing version's chunks rather than
        // accumulating them.
        if (! $forceReembed) {
            $existing = $this->findExistingVersion($projectKey, $sourcePath, $versionHash);
            if ($existing !== null) {
                $existing->update(['indexed_at' => now()]);
                $this->repairArtifactOfExistingVersion($existing, $markdown, $metadata);

                return $existing;
            }
        }

        // v8.23 (Ciclo 4) — PII redaction of the chunk text (direct path). Runs
        // here, AFTER the idempotency short-circuit above, so an identical
        // re-ingest is a pure no-op and never re-touches the token vault. The
        // Flow saga (the real HTTP/CLI path) redacts in ChunkDocumentStep
        // instead; both share App\Services\Kb\Pii\ChunkRedactor so the contract
        // is identical. No-op unless redaction is genuinely active.
        $chunkDrafts = app(ChunkRedactor::class)->redact($projectKey, $chunkDrafts);

        $canonical = $this->tryParseCanonical($projectKey, $sourcePath, $markdown);
        $embeddingResponse = $this->embeddingCache->generate(
            array_map(fn (ChunkDraft $d) => $d->text, $chunkDrafts),
        );

        // v8.36 / ADR 0030 §3 — temp before the transaction, move after commit,
        // this attempt's temp discarded on failure (one core, both paths).
        $artifact = $this->stageArtifact($projectKey, $sourcePath, $versionHash, $markdown, $metadata);
        try {
            $document = DB::transaction(function () use (
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
                $forceReembed,
                $artifact,
            ) {
                $document = $this->persistDocumentAndChunks(
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
                    $forceReembed,
                    $artifact,
                );

                // Inside the SAME transaction as the document itself, on purpose.
                // If the permission mirror cannot be written, the document must
                // not exist either: ingesting it anyway would publish to the
                // whole project a file the source shared with three people, which
                // is the exact failure ADR 0028 phase 2 removes. A failed ingest
                // retries; an over-shared document does not announce itself.
                $this->mirrorSourceAccess($document, $metadata);

                return $document;
            });
        } catch (\Throwable $e) {
            $this->discardArtifact($artifact);
            throw $e;
        }
        $this->publishArtifactOrLog($artifact, $document, $sourceType, $metadata);

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
        bool $forceReembed = false,
        ?array $artifact = null,
    ): KnowledgeDocument {
        // If this is a canonical re-ingest with changed content, previous
        // versions still hold the (project_key, slug) / (project_key, doc_id)
        // unique slots. We must vacate those slots BEFORE the updateOrCreate
        // below, otherwise the insert violates `uq_kb_doc_slug` / `uq_kb_doc_doc_id`.
        if ($canonical !== null) {
            $this->vacateCanonicalIdentifiersOnPreviousVersions($projectKey, $sourcePath, $versionHash);
        }

        $tenantId = app(TenantContext::class)->current();

        // ADR 0030 §3 — the retention contract the row was ingested under.
        // `markdown_only` may drop a shared original only if EVERY row that
        // references it was ingested under a mode that does not require the
        // original; a row without the stamp (pre-v8.36) counts as full_copy.
        $metadata['source_retention'] ??= app(SourceRetentionResolver::class)->mode();

        // v8.36 / ADR 0030 §4 — provenance is written when the version is
        // BORN. A forced re-embed re-runs this on the same row and must not
        // rewrite who created it (a restore's `user:{id}` stays), nor null a
        // stored artifact because the flag is off today: only a freshly
        // staged artifact updates the pointer on an existing row.
        $isNewVersion = $this->findExistingVersion($projectKey, $sourcePath, $versionHash) === null;
        $attributes = array_merge($this->buildDocumentAttributes(
            $title,
            $mimeType,
            $sourceType,
            $metadata,
            $documentHash,
            $canonical,
        ), $isNewVersion
            ? $this->versionProvenanceAttributes($metadata, $documentHash, $artifact)
            : ($artifact === null ? [] : ['markdown_path' => $artifact['final'], 'content_hash' => $documentHash]));
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

        // v8.23 (Ciclo 4, PR5) — on a forced re-embed the document row is the
        // SAME version (unchanged version_hash), so its prior chunks would
        // otherwise linger alongside the freshly re-redacted ones. Drop them
        // first so the re-embed REPLACES the chunk set under the new policy.
        if ($forceReembed) {
            // R30 — knowledge_chunks is tenant-aware; scope explicitly so a
            // document_id collision across tenants (impossible today with
            // auto-increment PKs, but the rule is unconditional) cannot bleed.
            KnowledgeChunk::query()->forTenant($tenantId)->where('knowledge_document_id', $document->id)->delete();
        }

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
            // ADR 0028 phase 1 — who authored this. Asked of the connector
            // that produced the metadata, never inferred from the content.
            // null means no connector declared anything (the CLI walker, the
            // HTTP batch endpoint, a connector predating the capability), and
            // readers resolve that to the trusted default.
            'provenance_tier' => $this->provenanceResolver->forIngestionMetadata($metadata)?->value,
            'status' => 'active',
            'document_hash' => $documentHash,
            // ADR 0030 §4 — `version_actor` / `version_reason` are columns
            // (the audit record of THIS version), never stored metadata: a
            // copy in the bag would ride into the next ingest built from the
            // row's metadata (the admin raw edit) and name the wrong actor.
            'metadata' => array_diff_key($metadata, array_flip(['version_actor', 'version_reason'])),
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
                // v8.36 / ADR 0029 — a machine-read (OCR) document is born in
                // the `auto` tier (ADR 0014) until a person approves it (W3);
                // set here, in the one core both ingest paths share. Text-layer
                // and markdown documents keep the human default.
                'generation_source' => (($metadata['converter']['provenance'] ?? null) === 'ocr')
                    ? GenerationSource::Auto->value
                    : GenerationSource::Human->value,
            ]);
        }

        return array_merge($base, [
            'is_canonical' => true,
            'doc_id' => $canonical->docId,
            'slug' => $canonical->slug,
            'canonical_type' => $canonical->type?->value,
            'canonical_status' => $canonical->status?->value,
            'retrieval_priority' => $canonical->retrievalPriority,
            // v8.11/P3 — honour an explicit `generation_source: auto` frontmatter
            // key so AI-synthesized canonical pages (concept pages) land in the
            // auto tier instead of the human-default. Any other value (or absent)
            // => 'human', identical to the column default, so human-authored
            // canonical docs are unaffected (the frontmatter is the source of
            // truth, preserved across re-ingest).
            'generation_source' => (($canonical->frontmatter['generation_source'] ?? null) === GenerationSource::Auto->value)
                ? GenerationSource::Auto->value
                : GenerationSource::Human->value,
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

    // -----------------------------------------------------------------
    // v8.36 / ADR 0030 — conversion artifacts + version provenance
    // -----------------------------------------------------------------

    /**
     * Write this version's Markdown to a temp file beside its final artifact
     * path — or return null when nothing is to be stored (flag off,
     * `reference_only` retention, dry run).
     *
     * @param  array<string,mixed>  $metadata
     * @return array{disk: string, tmp: string, final: string}|null
     */
    private function stageArtifact(string $projectKey, string $sourcePath, string $versionHash, string $markdown, array $metadata): ?array
    {
        $store = app(ConversionArtifactStore::class);
        if (! $store->enabled() || ! app(SourceRetentionResolver::class)->retainsMarkdown()) {
            return null;
        }
        if (($metadata['dry_run'] ?? false) === true) {
            return null;
        }
        $disk = (string) ($metadata['disk'] ?? config('kb.sources.disk', 'kb'));
        $prefix = array_key_exists('prefix', $metadata)
            ? (string) $metadata['prefix']
            : (string) config('kb.sources.path_prefix', '');
        $final = $store->pathFor(app(TenantContext::class)->current(), $projectKey, $sourcePath, $versionHash, $prefix);

        return ['disk' => $disk, 'tmp' => $store->writeTemp($disk, $final, $markdown), 'final' => $final];
    }

    /**
     * ADR 0030 §3 — the same-hash short-circuit returns the existing version
     * only after its artifact has been verified: present on disk and hashing
     * to `content_hash`. A missing or corrupt artifact is republished from the
     * freshly converted bytes through the same temp-then-publish protocol —
     * no new version, no chunk rewrite, pointer and `content_hash` unchanged
     * (the bytes are the same by construction). A row without a pointer is
     * left to `kb:artifacts-backfill`; a failed repair is logged and the row
     * keeps falling back to reconstruction, exactly as before the re-ingest.
     *
     * @param  array<string,mixed>  $metadata
     */
    private function repairArtifactOfExistingVersion(KnowledgeDocument $existing, string $markdown, array $metadata): void
    {
        $path = $existing->markdown_path;
        if (! is_string($path) || $path === '' || ($metadata['dry_run'] ?? false) === true) {
            return;
        }
        $store = app(ConversionArtifactStore::class);
        if (! $store->enabled()) {
            return;
        }
        $expected = is_string($existing->content_hash) && $existing->content_hash !== '' ? $existing->content_hash : hash('sha256', $markdown);
        if (hash('sha256', $markdown) !== $expected) {
            // Cannot happen for the same version_hash; refuse to "repair" with bytes that are not the recorded ones.
            Log::warning('DocumentIngestor: converted bytes do not match the recorded content_hash; artifact left as is', ['document_id' => $existing->id]);

            return;
        }
        $disk = (string) ($metadata['disk'] ?? config('kb.sources.disk', 'kb'));
        try {
            $current = $store->read($disk, $path);
            if (is_string($current) && hash('sha256', $current) === $expected) {
                return;
            }
            $store->publish($disk, $store->writeTemp($disk, $path, $markdown), $path);
            Log::info('DocumentIngestor: artifact repaired from an identical re-ingest', ['document_id' => $existing->id, 'disk' => $disk, 'markdown_path' => $path, 'was' => $current === null ? 'missing' : 'corrupt']);
        } catch (\Throwable $e) {
            Log::error('DocumentIngestor: artifact repair failed; reads keep falling back to reconstruction until kb:artifacts-backfill repairs it', ['document_id' => $existing->id, 'disk' => $disk, 'markdown_path' => $path, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array{disk: string, tmp: string, final: string}|null  $artifact
     */
    private function discardArtifact(?array $artifact): void
    {
        if ($artifact === null) {
            return;
        }
        app(ConversionArtifactStore::class)->discardTemp($artifact['disk'], $artifact['tmp']);
    }

    /**
     * After commit: publish the temp and, in `markdown_only`, drop the
     * original. The row is already committed and points at the final path,
     * so a publish failure here is a documented degrade (`contentFor()`
     * falls back and says so, `kb:artifacts-backfill` repairs it) — logged,
     * never a reason to skip the canonical indexer dispatch that follows
     * or to fail a job whose retry would be a version-hash no-op (R14).
     *
     * @param  array{disk: string, tmp: string, final: string}|null  $artifact
     * @param  array<string,mixed>  $metadata
     */
    private function publishArtifactOrLog(?array $artifact, KnowledgeDocument $document, string $sourceType, array $metadata): void
    {
        if ($artifact === null) {
            return;
        }
        try {
            $this->publishArtifact($artifact, $document, $sourceType, $metadata);
        } catch (\Throwable $e) {
            $this->discardArtifact($artifact);
            Log::error('DocumentIngestor: artifact publish failed after commit; the row keeps its pointer and reads fall back to reconstruction until kb:artifacts-backfill repairs it', [
                'document_id' => (int) $document->id,
                'disk' => $artifact['disk'],
                'markdown_path' => $artifact['final'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Move the temp into place (R4 — a failed move throws) and, in
     * `markdown_only`, drop the original binary the artifact now stands
     * for. A Markdown source IS its own artifact and is never dropped; and
     * the original is a SHARED storage key (every version of the path, and
     * on a shared disk every tenant with the same key, points at the same
     * bytes), so it is dropped only when every row referencing it — any
     * tenant, trashed rows included — already has an artifact to stand in
     * for it; otherwise it is kept and the reason logged. Rows whose
     * original was dropped are stamped `metadata.source_dropped = true` so
     * the orphan sweeps never read the missing file as an orphan.
     *
     * @param  array{disk: string, tmp: string, final: string}  $artifact
     * @param  array<string,mixed>  $metadata
     */
    private function publishArtifact(array $artifact, KnowledgeDocument $document, string $sourceType, array $metadata): void
    {
        $store = app(ConversionArtifactStore::class);
        $store->publish($artifact['disk'], $artifact['tmp'], $artifact['final']);

        if (! app(SourceRetentionResolver::class)->dropsOriginal() || $sourceType === 'markdown') {
            return;
        }
        $prefix = array_key_exists('prefix', $metadata)
            ? (string) $metadata['prefix']
            : (string) config('kb.sources.path_prefix', '');
        try {
            $sourcePath = KbPath::normalize((string) $document->source_path);
            $original = $prefix === '' ? $sourcePath : KbPath::normalize($prefix.'/'.$sourcePath);
        } catch (\InvalidArgumentException) {
            return;
        }
        $storage = Storage::disk($artifact['disk']);
        if ($original === $artifact['final'] || ! $storage->exists($original)) {
            return;
        }

        // ADR 0030 §3 — the original is dropped only once this row's final
        // move has succeeded (publish() threw otherwise) AND every other
        // referencing row's artifact is PRESENT on disk: a pointer whose file
        // never landed (a publish that failed after commit, repaired later by
        // kb:artifacts-backfill) does not stand in for the original.
        $referencing = $this->rowsReferencingStorageKey($artifact['disk'], $original, $sourcePath);
        $withoutArtifact = $referencing->first(function (KnowledgeDocument $row) use ($store, $artifact, $document): bool {
            $rowMetadata = is_array($row->metadata) ? $row->metadata : [];
            // A row ingested under full_copy (or before the stamp existed)
            // still requires the original: its retention contract wins.
            $rowMode = (string) ($rowMetadata['source_retention'] ?? SourceRetentionResolver::FULL_COPY);
            if ((int) $row->id !== (int) $document->id && $rowMode === SourceRetentionResolver::FULL_COPY) {
                return true;
            }
            if ($rowMode === SourceRetentionResolver::REFERENCE_ONLY) {
                return false; // never needed the local source
            }
            if (! is_string($row->markdown_path) || $row->markdown_path === '') {
                return true;
            }
            $rowDisk = (string) ($rowMetadata['disk'] ?? $artifact['disk']);

            return ! $store->exists($rowDisk, $row->markdown_path);
        });
        if ($withoutArtifact !== null) {
            Log::info('DocumentIngestor: markdown_only retention kept the original — another row referencing the same storage key still requires it (full_copy contract, or no artifact on disk yet)', [
                'document_id' => (int) $document->id,
                'blocking_document_id' => (int) $withoutArtifact->id,
                'disk' => $artifact['disk'],
                'path' => $original,
            ]);

            return;
        }
        if (! $storage->delete($original)) {
            Log::warning('DocumentIngestor: markdown_only retention could not drop the original after the artifact commit', [
                'document_id' => (int) $document->id,
                'disk' => $artifact['disk'],
                'path' => $original,
            ]);

            return;
        }
        foreach ($referencing as $row) {
            $rowMetadata = is_array($row->metadata) ? $row->metadata : [];
            if (($rowMetadata['source_dropped'] ?? false) === true) {
                continue;
            }
            KnowledgeDocument::withoutGlobalScopes()->whereKey($row->id)->update(['metadata' => array_merge($rowMetadata, ['source_dropped' => true])]);
        }
    }

    /**
     * Every row — any tenant, any status, soft-deleted included — whose
     * `(disk, prefix + source_path)` resolves to the given storage key. The
     * same lookup `DocumentDeleter` guards the shared source file with.
     *
     * @return \Illuminate\Support\Collection<int, KnowledgeDocument>
     */
    private function rowsReferencingStorageKey(string $disk, string $fullPath, string $sourcePath): \Illuminate\Support\Collection
    {
        $rows = collect();
        KnowledgeDocument::withoutGlobalScopes()
            ->where('source_path', $sourcePath)
            ->select(['id', 'source_path', 'markdown_path', 'metadata'])
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$rows, $disk, $fullPath): void {
                foreach ($chunk as $row) {
                    $rowMetadata = is_array($row->metadata) ? $row->metadata : [];
                    $rowDisk = (string) ($rowMetadata['disk'] ?? config('kb.sources.disk', 'kb'));
                    $rowPrefix = array_key_exists('prefix', $rowMetadata)
                        ? (string) $rowMetadata['prefix']
                        : (string) config('kb.sources.path_prefix', '');
                    try {
                        $rowFull = $rowPrefix === '' ? KbPath::normalize((string) $row->source_path) : KbPath::normalize($rowPrefix.'/'.$row->source_path);
                    } catch (\InvalidArgumentException) {
                        continue;
                    }
                    if ($rowDisk === $disk && $rowFull === $fullPath) {
                        $rows->push($row);
                    }
                }
            });

        return $rows;
    }

    /**
     * ADR 0030 §4 — `version_actor` comes from the trusted caller (the HTTP
     * controller sets the authenticated principal after stripping the client
     * value, `kb:ocr` sets the re-run actor); the ingestor only defaults it:
     * `system:ocr` for a machine-read document, `system:ingest` otherwise.
     * `version_reason` is free text, bounded to the column.
     *
     * @param  array<string,mixed>  $metadata
     * @param  array{disk: string, tmp: string, final: string}|null  $artifact
     * @return array<string, ?string>
     */
    private function versionProvenanceAttributes(array $metadata, string $documentHash, ?array $artifact): array
    {
        $actor = $metadata['version_actor'] ?? null;
        if (! is_string($actor) || trim($actor) === '') {
            $actor = (($metadata['converter']['provenance'] ?? null) === 'ocr') ? 'system:ocr' : 'system:ingest';
        }
        $reason = $metadata['version_reason'] ?? null;
        $reason = is_string($reason) && trim($reason) !== '' ? mb_substr(trim($reason), 0, 1024) : null;

        return [
            'version_actor' => mb_substr(trim($actor), 0, 191),
            'version_reason' => $reason,
            'content_hash' => $artifact === null ? null : $documentHash,
            'markdown_path' => $artifact['final'] ?? null,
        ];
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

    /**
     * Mirror the source's permission list onto the document, when a connector
     * reported one (ADR 0028 phase 2).
     *
     * The DTO travels in metadata under a reserved key rather than as an
     * argument, because adding even an optional parameter to the ingestion
     * contract is a breaking change for every host that implements it.
     *
     * A corpus whose connector does not read permissions produces no key and
     * reaches none of this, which is what keeps the feature free for the
     * eleven connectors that have nothing to report.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function mirrorSourceAccess(KnowledgeDocument $document, array $metadata): void
    {
        $raw = $metadata[SourceAccess::METADATA_KEY] ?? null;

        if (! is_array($raw)) {
            return;
        }

        $access = SourceAccess::fromArray($raw);

        if ($access === null) {
            return;
        }

        app(SourceAclMirror::class)->syncFor($document, $access);
    }

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
