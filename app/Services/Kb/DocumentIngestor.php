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
use App\Services\Kb\Versioning\ArtifactPublishFailedException;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Services\Kb\Versioning\SourceRetentionResolver;
use App\Support\Kb\ActiveSourceReservation;
use App\Support\Kb\HeldLock;
use App\Support\Kb\LockLostException;
use App\Support\Kb\SourceKeyLock;
use App\Support\Kb\StorageNamespace;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
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

        // ADR 0029 — the same rule as the Flow path (PersistChunksStep): a
        // forced or otherwise FRESH OCR run replaces the identical version
        // it re-produced, so the row points at the run that was billed.
        $replace = $forceReembed
            || \App\Services\Kb\Ocr\OcrService::isForced($combinedMetadata)
            || \App\Services\Kb\Ocr\OcrService::isFreshOcrRun($combinedMetadata);

        return $this->persistFromDrafts(
            projectKey: $projectKey,
            sourcePath: $normalizedSource->sourcePath,
            title: $title,
            mimeType: $normalizedSource->mimeType,
            sourceType: $sourceType,
            markdown: $converted->markdown,
            chunkDrafts: $chunkDrafts,
            metadata: $combinedMetadata,
            forceReembed: $replace,
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
     *
     * Hints are UNTRUSTED (they ride the request / connector metadata), so
     * the surface is closed on three sides: a hint is a namespaced BAG (an
     * array under the connector's key, or `_derived`) — a scalar hint would
     * land on a key a chunker reads as the converter's own (`filename` goes
     * into every chunk's citation) and is dropped; the keys that describe how
     * the text was obtained (OcrService::TRUSTED_ONLY_EXTRACTION_KEYS —
     * `converter_hints.provenance=ocr` would record a plain document as
     * machine-read, `system:ocr` actor and OCR generation tier) are refused
     * even as bags; and the converter's own output wins over every hint, the
     * host-resolved `source_type` over both (ADR 0029 §8).
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
                if (! is_string($key) || $key === '') {
                    continue;
                }
                if (in_array($key, \App\Services\Kb\Ocr\OcrService::TRUSTED_ONLY_EXTRACTION_KEYS, true)) {
                    Log::warning('DocumentIngestor: converter hint dropped — the key describes how the text was obtained and is set by the converter only', ['key' => $key, 'source_path' => $source->sourcePath]);

                    continue;
                }
                if (! is_array($value)) {
                    Log::warning('DocumentIngestor: converter hint dropped — a hint is a namespaced bag, never a scalar the chunkers would read as the converter\'s own', ['key' => $key, 'source_path' => $source->sourcePath]);

                    continue;
                }
                $extra[$key] = $value;
            }
        }

        $derived = $source->metadata['_derived'] ?? null;
        if (is_array($derived) && ! isset($extra['_derived'])) {
            $extra['_derived'] = $derived;
        }

        // The converter's own output wins over every hint (a hint can only
        // ADD to the surface the chunker reads, never rewrite what the
        // converter reported), and the host-resolved `source_type` wins over
        // both: it is what the chunker was resolved on, so the two never
        // disagree — not even on a replay that carries the row's stored
        // converter block.
        return new \App\Services\Kb\Pipeline\ConvertedDocument(
            markdown: $converted->markdown,
            mediaItems: $converted->mediaItems,
            extractionMeta: array_merge($extra, $converted->extractionMeta, ['source_type' => $sourceType]),
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

    /**
     * v8.36 / ADR 0030 §5 — re-derive and re-embed the chunks of an EXISTING
     * version from its stored artifact (the converted Markdown), without
     * running any converter: the row's `mime_type` may be a binary format
     * (a `markdown_only` PDF whose original was dropped), and its converter
     * must never be handed Markdown bytes — that would fail, or start an OCR
     * run over text. The row keeps its identity (`mime_type`, `source_type`,
     * metadata, version hash — the artifact hashes to it by construction);
     * only the chunk set is replaced under the current policy.
     */
    public function reembedFromMarkdown(KnowledgeDocument $document, string $markdown): KnowledgeDocument
    {
        $mimeType = $document->mime_type !== null && $document->mime_type !== '' ? (string) $document->mime_type : 'text/markdown';
        $sourceType = $this->resolveSourceType($mimeType);
        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $sourcePath = KbPath::normalize((string) $document->source_path);

        $converted = new \App\Services\Kb\Pipeline\ConvertedDocument(
            markdown: $markdown,
            mediaItems: [],
            extractionMeta: is_array($metadata['converter'] ?? null) ? $metadata['converter'] : ['converter' => 'artifact'],
            sourceMimeType: $mimeType,
        );
        $source = new SourceDocument(
            sourcePath: $sourcePath,
            mimeType: $mimeType,
            bytes: $markdown,
            externalUrl: is_string($metadata['external_url'] ?? null) ? $metadata['external_url'] : null,
            externalId: is_string($metadata['external_id'] ?? null) ? $metadata['external_id'] : null,
            connectorType: is_string($metadata['connector'] ?? null) ? $metadata['connector'] : 'local',
            metadata: $metadata,
        );
        $converted = $this->projectChunkerHints($converted, $source, $sourceType);
        $chunkDrafts = $this->registry->resolveChunker($sourceType)->chunk($converted);
        // The artifact was produced by this row's converter, so the row's
        // source-type chunker is the right one (a PDF/OCR artifact carries
        // the `## Page N` markers PdfPageChunker slices on). Should that
        // chunker find nothing to slice in a non-empty artifact, fall back to
        // the generic Markdown chunker: with `forceReembed` an empty draft
        // list would REPLACE the live chunks with nothing and silently drop
        // the document from retrieval (R14).
        if ($chunkDrafts === [] && trim($markdown) !== '' && $sourceType !== 'markdown') {
            $chunkDrafts = $this->registry->resolveChunker('markdown')->chunk($converted);
        }

        return $this->persistFromDrafts(
            projectKey: (string) $document->project_key,
            sourcePath: $sourcePath,
            title: (string) $document->title,
            mimeType: $mimeType,
            sourceType: $sourceType,
            markdown: $markdown,
            chunkDrafts: $chunkDrafts,
            metadata: $metadata,
            forceReembed: true,
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
     *
     * `withTrashed()` — a soft-deleted row occupies the SAME
     * `(tenant_id, project_key, source_path, version_hash)` slot the unique
     * index protects (`uq_kb_doc_tenant_version`), and the index does not
     * honour soft-delete any more than it honours the ACL/canonical scopes
     * R10 point 4 already calls out for slug/doc_id. An identical re-ingest
     * after a soft delete would otherwise MISS this row, fall through to
     * `persistDocumentAndChunks()`, and crash `updateOrCreate()` on the
     * unique constraint — a caller simply re-ingesting the same bytes,
     * breaking on a delete it never asked to touch. The two upstream
     * callers ({@see persistDrafts()}, {@see persistFromDrafts()}) restore
     * a trashed match via {@see restoreIfTrashed()} before treating it as
     * the idempotent no-op.
     */
    private function findExistingVersion(string $projectKey, string $sourcePath, string $versionHash): ?KnowledgeDocument
    {
        return KnowledgeDocument::withTrashed()
            ->forTenant(app(TenantContext::class)->current())
            ->where('project_key', $projectKey)
            ->where('source_path', $sourcePath)
            ->where('version_hash', $versionHash)
            ->first();
    }

    /**
     * A `findExistingVersion()` match that is soft-deleted is un-deleted
     * here, at the one point both persistence entry points call before
     * their idempotency short-circuit — never inside
     * `persistDocumentAndChunks()`, which only ever sees a match that
     * already passed through this restore (or none at all).
     *
     * Lighter than `DocumentVersionService::restore()`: that one moves
     * LIVE status between two DIFFERENT rows of a family (a genuinely more
     * elaborate scenario — "make an archived version live again", with
     * canonical-identity juggling and a displaced sibling). This is the
     * SAME row, the SAME hash, coming back from the SAME delete — there is
     * nothing to displace. It mirrors that service's `metadata['restores']`
     * provenance shape so `DocumentVersionService::lastRestoreOf()` reads
     * either kind of restore the same way.
     */
    private function restoreIfTrashed(KnowledgeDocument $existing): void
    {
        if (! $existing->trashed()) {
            return;
        }
        $metadata = is_array($existing->metadata) ? $existing->metadata : [];
        $restores = is_array($metadata['restores'] ?? null) ? $metadata['restores'] : [];
        $restores[] = [
            'actor' => 'system:ingest',
            'at' => now()->toIso8601String(),
            'previous_live_id' => null,
        ];
        $metadata['restores'] = $restores;
        $existing->metadata = $metadata;
        $existing->restore();
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
     * A post-commit artifact publish that fails throws
     * {@see ArtifactPublishFailedException} AFTER the row is committed: the
     * step fails, the job retries, and the retry — an identical re-ingest —
     * repairs the pointer through the same-hash path (ADR 0030 §3).
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
        $existing = $this->findExistingVersion($projectKey, $sourcePath, $versionHash);

        // v8.36 / ADR 0029 — a forced OCR re-run (`metadata.ocr.force`) that
        // produced byte-identical Markdown is still a NEW run: its chunks and
        // its `converter.ocr` block (run key, attempt, confidence) replace the
        // existing version's instead of being dropped by the same-hash guard
        // — otherwise the row would keep pointing at the previous run while
        // the new one was billed and recorded. Same mechanics as forceReembed.
        if (! $replaceExisting && $existing !== null) {
            $this->restoreIfTrashed($existing);
            $existing->update(['indexed_at' => now()]);
            $this->repairArtifactOfExistingVersion($existing, $markdown, $metadata);

            return $existing;
        }

        // ADR 0030 §3 — the retention contract, decided BEFORE anything is
        // staged or dropped (the same rule as persistFromDrafts()).
        $metadata = $this->stampSourceRetention($metadata, $existing);

        // v8.36 — resets the OCR run's isInFlight() freshness clock right
        // next to the write phase that is about to commit a reference to it
        // (OcrService::touchRunBeforeCommit() docblock: the archived-version
        // prune race). No-op for the vast majority of documents with no OCR
        // run in their metadata.
        app(\App\Services\Kb\Ocr\OcrService::class)->touchRunBeforeCommit($metadata, $sourcePath);

        // v8.36 / ADR 0030 §3 — the artifact temp file is written BEFORE the
        // transaction and moved into place only after commit; a failed
        // transaction discards this attempt's temp and nothing else.
        $artifact = $this->stageArtifact($projectKey, $sourcePath, $versionHash, $markdown, $metadata);
        try {
            $document = $this->underSourceKeyLock($sourcePath, $metadata, $this->sourceKeyLockNeeded($sourceType, $metadata), fn (?HeldLock $held = null) => DB::transaction(fn () => $this->commitOnlyIfStillHeld($held, $this->persistDocumentAndChunks(
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
            ))));
        } catch (\Throwable $e) {
            $this->discardArtifact($artifact);
            throw $e;
        }
        $this->publishArtifactOrThrow($artifact, $document);

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
        $existing = $this->findExistingVersion($projectKey, $sourcePath, $versionHash);

        // v8.23 (Ciclo 4, PR5) — a `forceReembed` re-ingest (after a PII-policy
        // change) deliberately SKIPS the version_hash idempotency short-circuit:
        // the raw markdown — and thus the hash — is unchanged, but the chunks
        // must be re-derived + re-embedded under the NEW policy. persistDocument-
        // AndChunks then REPLACES the existing version's chunks rather than
        // accumulating them.
        if (! $forceReembed && $existing !== null) {
            $this->restoreIfTrashed($existing);
            $existing->update(['indexed_at' => now()]);
            $this->repairArtifactOfExistingVersion($existing, $markdown, $metadata);

            return $existing;
        }

        // ADR 0030 §3 — the retention contract this version is persisted
        // under, decided BEFORE anything is staged or dropped: a version
        // being REPLACED keeps the contract it was born under (its own stamp,
        // `full_copy` for a row that predates the stamp — never the configured
        // mode of the day), a new version gets the configured mode; an unknown
        // value is never a permissive one (the untrusted boundaries strip the
        // key anyway).
        $metadata = $this->stampSourceRetention($metadata, $existing);

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

        // v8.36 — resets the OCR run's isInFlight() freshness clock right
        // next to the write phase that is about to commit a reference to it
        // (OcrService::touchRunBeforeCommit() docblock: the archived-version
        // prune race). No-op for the vast majority of documents with no OCR
        // run in their metadata.
        app(\App\Services\Kb\Ocr\OcrService::class)->touchRunBeforeCommit($metadata, $sourcePath);

        // v8.36 / ADR 0030 §3 — temp before the transaction, move after commit,
        // this attempt's temp discarded on failure (one core, both paths).
        $artifact = $this->stageArtifact($projectKey, $sourcePath, $versionHash, $markdown, $metadata);
        try {
            // ADR 0030 §3 — the row commits under the storage key's lock, the
            // one a `markdown_only` drop holds around its scan + delete.
            $document = $this->underSourceKeyLock($sourcePath, $metadata, $this->sourceKeyLockNeeded($sourceType, $metadata), fn (?HeldLock $held = null) => DB::transaction(function () use (
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
                $held,
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

                return $this->commitOnlyIfStillHeld($held, $document);
            }));
        } catch (\Throwable $e) {
            $this->discardArtifact($artifact);
            throw $e;
        }
        // The row is committed: the graph projection is dispatched FIRST, so
        // an artifact publish that fails (and throws, below) never withholds
        // the canonical indexer from a version that exists. A dispatch that
        // throws (a queue connection down, an inline `sync` run failing)
        // still discards this attempt's temp: the row stays, its pointer is
        // the repairable `missing` state, and nothing leased is left behind.
        try {
            $this->dispatchCanonicalIndexerIfCanonical($document);
        } catch (\Throwable $e) {
            $this->discardArtifact($artifact);
            throw $e;
        }
        $this->publishArtifactOrThrow($artifact, $document);

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
        // versions still hold the (tenant_id, project_key, slug) /
        // (tenant_id, project_key, doc_id) unique slots. We must vacate those
        // slots BEFORE the updateOrCreate below, otherwise the insert violates
        // `uq_kb_doc_tenant_slug` / `uq_kb_doc_tenant_doc_id`.
        if ($canonical !== null) {
            $this->vacateCanonicalIdentifiersOnPreviousVersions($projectKey, $sourcePath, $versionHash);
        }

        $tenantId = app(TenantContext::class)->current();

        // ADR 0030 §3 — the retention contract the row was ingested under is
        // stamped by the caller (persistFromDrafts() / persistDrafts()) before
        // anything is staged; `markdown_only` may drop a shared original only
        // if EVERY row that references it was ingested under a mode that does
        // not require the original; a row without the stamp (pre-v8.36)
        // counts as full_copy.

        // v8.36 / ADR 0030 §4 — provenance is written when the version is
        // BORN. A forced re-embed re-runs this on the same row and must not
        // rewrite who created it (a restore's `user:{id}` stays), nor null a
        // stored artifact because the flag is off today: only a freshly
        // staged artifact updates the pointer on an existing row.
        //
        // This is reached with a match that is still soft-deleted when the
        // caller chose `replaceExisting`/`forceReembed` over the idempotent
        // short-circuit (persistDrafts()/persistFromDrafts() only restore on
        // THEIR OWN early-return branch — this call bypasses it). Restored
        // here too, BEFORE `updateOrCreate()` below: that call's lookup is
        // the plain (non-`withTrashed()`) query every other write uses, so a
        // still-trashed row would stay invisible to it and the insert would
        // die on `uq_kb_doc_tenant_version` — the same crash R10 point 4
        // describes for the ACL/canonical scopes, one guard short of here.
        $existingVersion = $this->findExistingVersion($projectKey, $sourcePath, $versionHash);
        $isNewVersion = $existingVersion === null;
        if ($existingVersion !== null) {
            $this->restoreIfTrashed($existingVersion);
        }
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
        //
        // The lookup and the constraint have to agree or the isolation is
        // only advertised: a tenant-scoped lookup under a `project_key`-only
        // unique misses the other tenant's row and then dies on the insert.
        // 2026_10_02_000011 rebuilt the index as
        // `uq_kb_doc_tenant_version (tenant_id, project_key, source_path,
        // version_hash)`, so the four keys below ARE the unique.
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
     * `reference_only` retention, dry run, or a recorded prefix that cannot
     * name an artifact root — a traversal, the reserved `.artifacts`
     * segment). That last case does NOT throw: `rootFor()` would (SEC-PATH-001),
     * which would fail the whole ingest — including a forced re-embed of a
     * version whose bytes are fine — over a namespace problem unrelated to
     * those bytes. Staging nothing is the fail-closed answer for a WRITE
     * instead: a warning is logged, no artifact is staged for this version,
     * an existing pointer on the row is left as it is, and the row still
     * commits with its content re-chunked (see the guard below).
     *
     * @param  array<string,mixed>  $metadata
     * @return array{disk: string, tmp: string, final: string}|null
     */
    private function stageArtifact(string $projectKey, string $sourcePath, string $versionHash, string $markdown, array $metadata): ?array
    {
        $store = app(ConversionArtifactStore::class);
        if (! $store->enabled() || $this->sourceRetentionOf($metadata) === SourceRetentionResolver::REFERENCE_ONLY) {
            return null;
        }
        if (($metadata['dry_run'] ?? false) === true) {
            return null;
        }
        $disk = StorageNamespace::diskOf($metadata);
        $prefix = StorageNamespace::recordedPrefix($metadata);
        if (! StorageNamespace::prefixCanNamePath($prefix)) {
            // A row can carry `prefix: '../outside'`. `rootFor()` refuses it
            // (SEC-PATH-001) by throwing, which would fail the whole ingest —
            // including a forced re-embed of a version whose bytes are fine.
            // Staging nothing is the fail-closed answer for a WRITE: an
            // existing pointer is kept as it is (persistFromDrafts only
            // rewrites it for a freshly staged artifact), so the row keeps
            // the artifact it already has at the path it was really stored
            // under, and the content is still re-chunked.
            Log::warning('DocumentIngestor: the recorded path prefix cannot name a path; no conversion artifact staged for this version.', [
                'project_key' => $projectKey,
                'source_path' => $sourcePath,
                'disk' => $disk,
            ]);

            return null;
        }
        $final = $store->pathFor(app(TenantContext::class)->current(), $projectKey, $sourcePath, $versionHash, $prefix);

        return ['disk' => $disk, 'tmp' => $store->writeTemp($disk, $final, $markdown), 'final' => $final];
    }

    /**
     * ADR 0030 §3 — the same-hash short-circuit returns the existing version
     * only after its artifact has been verified: present on disk and hashing
     * to `content_hash`. A missing or corrupt artifact is republished from the
     * freshly converted bytes through the same temp-then-publish protocol —
     * no new version, no chunk rewrite, pointer and `content_hash` unchanged
     * (the bytes are the same by construction). A row that predates the
     * artifacts (no pointer) gets its artifact published the same way, under
     * ITS retention contract (a `reference_only` row stays without one) —
     * an identical re-ingest with the flag on is the cheapest repair there
     * is, `kb:artifacts-backfill` being the one for rows nobody re-pushes.
     * The disk is the version's RECORDED namespace (the configured one only
     * for a legacy row), never the incoming request's: after `kb.sources.disk`
     * changes the historical artifact still lives where the row says.
     * A failed repair is logged AND thrown ({@see ArtifactPublishFailedException}):
     * the row keeps falling back to reconstruction exactly as before the
     * re-ingest, and the caller — the ingest job, a connector sync — sees
     * the failure and retries instead of reporting a document whose
     * artifact silently never landed (R14).
     *
     * @param  array<string,mixed>  $metadata
     */
    private function repairArtifactOfExistingVersion(KnowledgeDocument $existing, string $markdown, array $metadata): void
    {
        if (($metadata['dry_run'] ?? false) === true) {
            return;
        }
        $store = app(ConversionArtifactStore::class);
        if (! $store->enabled()) {
            return;
        }
        $existingMetadata = is_array($existing->metadata) ? $existing->metadata : [];
        $disk = StorageNamespace::diskOf($existingMetadata);
        $path = $existing->markdown_path;
        if (! is_string($path) || $path === '') {
            // The retention tail runs only once a VERIFIED artifact is there
            // (the publish returned, or a concurrent one had): a failed
            // publish leaves a `missing` pointer, and the gate would only
            // refuse on it.
            if ($this->publishArtifactOfPointerlessVersion($existing, $markdown, $existingMetadata, $disk, $store) && is_string($existing->markdown_path)) {
                $this->finalizeSourceRetentionOrLog($existing, $disk, $existing->markdown_path);
            }

            return;
        }
        // `document_hash`, not `content_hash`, is the authoritative expected
        // hash — the same choice `kb:artifacts-backfill` makes
        // (KbArtifactsBackfillCommand::backfill(), comparing against
        // `$row->document_hash`), and for the same reason: `content_hash` is
        // "an integrity check, not a second identity" (CLAUDE.md §4) and can
        // itself be stale — a legacy pointer that never recorded one, or one
        // left behind by an interrupted repair. Trusting it here as
        // `$expected` would compare the freshly converted markdown against a
        // WRONG value and either refuse a legitimate repair (the warning
        // below, on bytes that are actually correct) or accept a corrupt
        // file as verified. `document_hash` is fixed at the row's own
        // creation and is exactly what `findExistingVersion()` already
        // matched this row on via `version_hash` (`document_hash` equals it
        // by construction).
        $expected = (string) $existing->document_hash;
        if ($expected === '' || hash('sha256', $markdown) !== $expected) {
            // Cannot happen for the same version_hash on a healthy row;
            // refuse to "repair" with bytes that contradict the recorded
            // document_hash rather than silently trust either side (R14).
            Log::warning('DocumentIngestor: converted bytes do not match the recorded document_hash; artifact left as is', ['document_id' => $existing->id]);

            return;
        }
        try {
            $current = $store->read($disk, $path);
            if (is_string($current) && hash('sha256', $current) === $expected) {
                $this->recordContentHashIfDiffers($existing, $expected);
            } else {
                if (! $this->writeAndPublishOrDiscard($disk, $path, $markdown, $existing)) {
                    return; // the row is gone meanwhile: nothing to repair
                }
                $this->recordContentHashIfDiffers($existing, $expected);
                Log::info('DocumentIngestor: artifact repaired from an identical re-ingest', ['document_id' => $existing->id, 'disk' => $disk, 'markdown_path' => $path, 'was' => $current === null ? 'missing' : 'corrupt']);
            }
        } catch (\Throwable $e) {
            Log::error('DocumentIngestor: artifact repair failed; reads keep falling back to reconstruction until the next identical re-ingest or kb:artifacts-backfill repairs it', ['document_id' => $existing->id, 'disk' => $disk, 'markdown_path' => $path, 'error' => $e->getMessage()]);

            throw new ArtifactPublishFailedException((int) $existing->id, $disk, $path, $e->getMessage(), $e);
        }
        // ADR 0030 §3 — the row's retention contract is finalized on every
        // identical re-ingest whose artifact is verified, not only on the
        // fresh ingest: an original re-uploaded after a `markdown_only` drop,
        // or one kept because the key was locked, is dropped now.
        $this->finalizeSourceRetentionOrLog($existing, $disk, $path);
    }

    /**
     * The retention tail — the `markdown_only` drop of the original — is
     * best effort on every path (a fresh ingest, an identical re-ingest, the
     * backfill): the row and its artifact are already correct, and a failure
     * here (a recorded disk since removed from the config, a lock store
     * outage) is logged, never a reason to fail a job whose retry would be a
     * version-hash no-op with nothing left to publish (R14); the next
     * identical ingest or the backfill retries the drop. The artifact
     * PUBLISH is not best effort: see publishArtifactOrThrow().
     *
     * Round-9 Copilot review on PR #479 — this runs inside the SAME
     * in-process ingest as `IngestDocumentJob` / `DispatchIngestFanOutStep::ingestSync()`,
     * which reserve the source for the whole read+convert+commit window
     * (ADR 0030 §3) but sit several calls above this one, past the
     * `Flow::execute()` boundary a live lock object cannot cross as step
     * input. Resolving {@see ActiveSourceReservation} here — the carrier
     * those two callers bind before calling in — threads their reservation
     * into the same assert-before-destroy check `KbArtifactsBackfillCommand`
     * already gets by passing its own explicitly. Absent (no ambient
     * caller, e.g. a direct unit-test call) resolves to null, same as today.
     */
    private function finalizeSourceRetentionOrLog(KnowledgeDocument $existing, string $disk, string $final): void
    {
        try {
            $this->finalizeSourceRetention($existing, $disk, $final, app(ActiveSourceReservation::class)->lock);
        } catch (\Throwable $e) {
            Log::error('DocumentIngestor: markdown_only retention could not be finalized on an identical re-ingest; the next identical ingest or kb:artifacts-backfill retries the drop', [
                'document_id' => (int) $existing->id,
                'disk' => $disk,
                'markdown_path' => $final,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A legacy pointer without `content_hash` (a row backfilled or repaired
     * before the hash was recorded) stays `unverified` on every surface until
     * the hash is persisted: once the stored bytes are known to be THE bytes
     * of this version, record it so the artifact becomes `verified`.
     *
     * The condition is "differs from the version's hash", never "is empty":
     * two rows need the write, not one — the legacy pointer above AND a row
     * whose recorded `content_hash` is present but WRONG, a stale value left
     * by an earlier interrupted repair, which would otherwise report
     * `mismatch` forever while the file on disk is provably correct. Same
     * reasoning, and the same condition, as
     * `KbArtifactsBackfillCommand::backfill()`.
     */
    private function recordContentHashIfDiffers(KnowledgeDocument $existing, string $hash): void
    {
        if ($existing->content_hash === $hash) {
            return;
        }
        if ($existing->updateUnscopedWithinOwnTenant(['content_hash' => $hash]) === 0) {
            Log::warning('DocumentIngestor: content_hash could not be recorded — the row is no longer the one read', ['document_id' => (int) $existing->id]);

            return;
        }
        $existing->content_hash = $hash;
    }

    /**
     * Publish the artifact of a version that never had one (ingested before
     * the flag was on) from an identical re-ingest: pointer first, bytes
     * second — the same order as a fresh ingest, so the orphan sweep never
     * sees a published file no row points at. When the publish fails the
     * pointer is KEPT: it names the version's bytes (`content_hash` is the
     * hash of exactly those), the file behind it is simply not there yet —
     * the `missing` state every reader degrades on and the next identical
     * re-ingest or `kb:artifacts-backfill` repairs. Taking it back would race
     * a concurrent repair of the same version (its publish lands between this
     * attempt's failure and the rollback, and the row would point at nothing
     * while a verified file sits on disk as an orphan); a pointer is never
     * rolled back, only repaired forward. The failure is thrown
     * ({@see ArtifactPublishFailedException}) once the pointer is kept — a
     * concurrent repair that already published these bytes is the one
     * exception: the row is healthy, nothing is thrown.
     *
     * @param  array<string,mixed>  $existingMetadata
     */
    private function publishArtifactOfPointerlessVersion(KnowledgeDocument $existing, string $markdown, array $existingMetadata, string $disk, ConversionArtifactStore $store): bool
    {
        if ($this->sourceRetentionOf($existingMetadata) === SourceRetentionResolver::REFERENCE_ONLY) {
            return false;
        }
        $hash = hash('sha256', $markdown);
        if ($hash !== (string) $existing->document_hash) {
            Log::warning('DocumentIngestor: converted bytes do not match the recorded document_hash; no artifact published', ['document_id' => $existing->id]);

            return false;
        }
        $prefix = StorageNamespace::recordedPrefix($existingMetadata);
        $final = null;
        try {
            // The row is the authority on its namespace, not the ambient
            // context: the pointer update and the publish both check
            // `$existing->tenant_id`, so the path must be composed from the
            // same tenant or a context that moved between the lookup and
            // this repair would point the row at another tenant's
            // content-addressed path (R30).
            $final = $store->pathFor((string) $existing->tenant_id, (string) $existing->project_key, KbPath::normalize((string) $existing->source_path), (string) $existing->version_hash, $prefix);
            if ($existing->updateUnscopedWithinOwnTenant(['markdown_path' => $final, 'content_hash' => $hash]) === 0) {
                // No row took the pointer (the row is no longer in the table):
                // bytes published now would be an orphan nobody points at (R4).
                Log::warning('DocumentIngestor: no artifact published — the pointerless row is no longer the one read', ['document_id' => (int) $existing->id]);

                return false;
            }
            if (! $this->writeAndPublishOrDiscard($disk, $final, $markdown, $existing)) {
                return false; // the row is gone meanwhile: nothing to stand in for
            }
            $existing->markdown_path = $final;
            $existing->content_hash = $hash;
            Log::info('DocumentIngestor: artifact published for a version that predated the artifacts, from an identical re-ingest', ['document_id' => $existing->id, 'disk' => $disk, 'markdown_path' => $final]);

            return true;
        } catch (\Throwable $e) {
            // The pointer stays (see the docblock): the row is in the
            // repairable `missing` state, never rolled back under a
            // concurrent repair's feet. Read-only after the fact, a verified
            // file at the path means a concurrent repair already published
            // these very bytes: the row is healthy and this is not an error.
            if ($final !== null) {
                $existing->markdown_path = $final;
                $existing->content_hash = $hash;
            }
            if ($final !== null && $store->verifies($disk, $final, $hash)) {
                Log::warning('DocumentIngestor: this artifact publish failed but a concurrent repair had already published these bytes; pointer kept, artifact verified', ['document_id' => $existing->id, 'disk' => $disk, 'markdown_path' => $final, 'error' => $e->getMessage()]);

                return true;
            }
            Log::error('DocumentIngestor: artifact publish failed for a version that predated the artifacts; the pointer is kept (state: missing) and the next identical re-ingest or kb:artifacts-backfill repairs it', ['document_id' => $existing->id, 'disk' => $disk, 'markdown_path' => $final, 'error' => $e->getMessage()]);

            throw new ArtifactPublishFailedException((int) $existing->id, $disk, (string) $final, $e->getMessage(), $e);
        }
    }

    /**
     * The retention contract a persisted row lives under: its own stamp when
     * it carries a valid one, `full_copy` otherwise — a row without the stamp
     * predates v8.36 and, once persisted, counts as `full_copy` wherever a
     * drop is decided (publishArtifactOrThrow(), the backfill, the pointerless
     * repair). Never the configured mode of the day.
     *
     * @param  array<string,mixed>  $metadata
     */
    private function sourceRetentionOf(array $metadata): string
    {
        $mode = $metadata['source_retention'] ?? null;
        if (is_string($mode) && in_array($mode, SourceRetentionResolver::MODES, true)) {
            return $mode;
        }

        return SourceRetentionResolver::FULL_COPY;
    }

    /**
     * Stamp the contract a version is persisted under. A version being
     * REPLACED (a forced re-embed, a fresh OCR run of the same bytes) keeps
     * the one it was born under — its own stamp, `full_copy` for a row that
     * predates the stamp — so a replay after `KB_SOURCE_RETENTION` moved to
     * `markdown_only` never drops an original the row was ingested to keep.
     * A NEW version gets the configured mode — unless the artifacts flag is
     * off, in which case the mode is the inert foundation it was (nothing is
     * stored, nothing is dropped or withheld, R43) and the row is stamped
     * `full_copy`: what actually happened, so a later backfill with the flag
     * on does not read `intentionally_missing` into a row that never lived
     * under `reference_only`. An unknown value is never a permissive one.
     *
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function stampSourceRetention(array $metadata, ?KnowledgeDocument $existing): array
    {
        if ($existing !== null) {
            $metadata['source_retention'] = $this->sourceRetentionOf(is_array($existing->metadata) ? $existing->metadata : []);

            return $metadata;
        }
        if (! app(ConversionArtifactStore::class)->enabled()) {
            $metadata['source_retention'] = SourceRetentionResolver::FULL_COPY;

            return $metadata;
        }
        $mode = $metadata['source_retention'] ?? null;
        $metadata['source_retention'] = is_string($mode) && in_array($mode, SourceRetentionResolver::MODES, true)
            ? $mode
            : app(SourceRetentionResolver::class)->mode();

        return $metadata;
    }

    /**
     * ADR 0030 §3/§8 — move a committed row's temp into place under the
     * artifact PATH's lock, the lock every delete and sweep of the path
     * holds around its reference re-check (`DocumentDeleter::removeArtifactIfUnreferenced()`):
     * a hard delete or prune can commit its row removal between this row's
     * commit and its publish, and a stale sweep could otherwise remove a
     * recreated version's artifact under it. Under the lock the row is
     * re-checked to exist, to belong to the tenant the caller names, and to
     * still point at `$final` — a row deleted, repointed, or of another
     * tenant has nothing to stand in for, so its bytes are never published
     * as an orphan: the temp is discarded and false is returned (each cause
     * logged for what it is). A publish that fails discards the temp (and
     * its lease) and rethrows: nothing is left for the age sweep.
     *
     * @param  string  $tenantId  the row's OWN tenant (R30 — never the ambient context); empty is a caller bug
     * @return bool true when the artifact is in place; false when the row is gone, belongs to another tenant, or no longer points at the path
     *
     * @throws \LogicException on an empty `$tenantId` (a caller bug, loud — never a silent no-op)
     * @throws \Throwable the publish failure, after the discard
     */
    public function publishArtifactForRow(string $disk, string $tmp, string $final, int $documentId, string $tenantId): bool
    {
        if ($tenantId === '') {
            app(ConversionArtifactStore::class)->discardTemp($disk, $tmp);
            throw new \LogicException('DocumentIngestor::publishArtifactForRow() needs the row\'s own tenant id (an unhydrated tenant_id, an empty --tenant): refusing rather than publishing nothing silently.');
        }
        $store = app(ConversionArtifactStore::class);
        try {
            return (bool) $store->underPathLock($disk, $final, function (HeldLock $held) use ($store, $disk, $tmp, $final, $documentId, $tenantId): bool {
                // The row must exist, be the caller's tenant's (R30 — the
                // caller names the row's tenant explicitly, as
                // KnowledgeDocument::updateUnscopedWithinOwnTenant() does,
                // never the ambient context) and still point at the path;
                // each miss is logged for what it is (R14), and the temp is
                // discarded: nothing to stand in for.
                // Scoped to the caller's tenant IN SQL (R30), never read
                // cross-tenant and judged afterwards: a row of another tenant
                // is simply not this caller's row, and reads as absent.
                $row = KnowledgeDocument::withoutGlobalScopes()
                    ->whereKey($documentId)
                    ->where('tenant_id', $tenantId)
                    ->first(['id', 'tenant_id', 'markdown_path']);
                $refusal = match (true) {
                    $row === null => 'no row of this tenant carries that id (deleted meanwhile, or another tenant\'s)',
                    $row->markdown_path !== $final => 'the row no longer points at the path (repointed meanwhile)',
                    default => null,
                };
                if ($refusal !== null) {
                    Log::warning("DocumentIngestor: artifact not published — {$refusal}; temp discarded", ['document_id' => $documentId, 'tenant_id' => $tenantId, 'disk' => $disk, 'markdown_path' => $final]);
                    $store->discardTemp($disk, $tmp);

                    return false;
                }
                // The lock's TTL may have lapsed during the re-check: a lost
                // lock is a refusal (thrown → the temp is discarded below), never
                // a move under another holder's publish or removal.
                // Asserted HERE and again inside the publish: the store's
                // own early returns (identical bytes already published) come
                // before its late assertion, and a `true` from those under a
                // lapsed lock would license the `markdown_only` drop that
                // follows. The late one covers the probes in between.
                $held->assertHeld('artifact publish');
                $store->publish($disk, $tmp, $final, $held);

                return true;
            });
        } catch (\Throwable $e) {
            $store->discardTemp($disk, $tmp);
            throw $e;
        }
    }

    /**
     * Write the temp and publish it for the row (see publishArtifactForRow()).
     *
     * @return bool true when the artifact is in place; false when the row no longer points at the path
     *
     * @throws \Throwable the publish failure, after the discard
     */
    private function writeAndPublishOrDiscard(string $disk, string $final, string $markdown, KnowledgeDocument $row): bool
    {
        $tmp = app(ConversionArtifactStore::class)->writeTemp($disk, $final, $markdown);

        return $this->publishArtifactForRow($disk, $tmp, $final, (int) $row->id, (string) $row->tenant_id);
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
     * After commit: move the temp into place and, in `markdown_only`, drop
     * the original. The row is already committed and points at the final
     * path, so a publish that fails leaves the version in the documented
     * `missing` state (`contentFor()` falls back and says so, the pointer is
     * never rolled back) — and it PROPAGATES as
     * {@see ArtifactPublishFailedException}: the ingest job retries, and the
     * retry is an identical re-ingest that repairs the pointer through the
     * same-hash path (`repairArtifactOfExistingVersion()`), so a disk that
     * refused a write for a moment never leaves a silently degraded version
     * behind; `kb:artifacts-backfill` covers the rows nobody re-ingests. A
     * caller whose retry would NOT take that path — `forceReembed` /
     * `replaceExisting` replace the chunk set again instead — catches the
     * exception and logs it (ReembedDocumentJob): the row is correct, only
     * its artifact is missing. The retention tail that follows a successful
     * publish stays best effort (finalizeSourceRetentionOrLog()).
     *
     * @param  array{disk: string, tmp: string, final: string}|null  $artifact
     *
     * @throws ArtifactPublishFailedException
     */
    private function publishArtifactOrThrow(?array $artifact, KnowledgeDocument $document): void
    {
        if ($artifact === null) {
            $this->stampSourceTakenIfPending($document, false);

            return;
        }
        try {
            if (! $this->publishArtifactForRow($artifact['disk'], $artifact['tmp'], $artifact['final'], (int) $document->id, (string) $document->tenant_id)) {
                $this->stampSourceTakenIfPending($document, false);

                return; // the row is gone, repointed, or another tenant's: nothing to stand in for, nothing published
            }
            $this->stampSourceTakenIfPending($document, true);
        } catch (\Throwable $e) {
            $this->stampSourceTakenIfPending($document, false);
            // publishArtifactForRow() owns the temp: it discarded it before rethrowing.
            Log::error('DocumentIngestor: artifact publish failed after commit; the row keeps its pointer (state: missing) and the retry — an identical re-ingest — or kb:artifacts-backfill repairs it', [
                'document_id' => (int) $document->id,
                'disk' => $artifact['disk'],
                'markdown_path' => $artifact['final'],
                'error' => $e->getMessage(),
            ]);

            throw new ArtifactPublishFailedException((int) $document->id, $artifact['disk'], $artifact['final'], $e->getMessage(), $e);
        }
        $this->finalizeSourceRetentionOrLog($document, $artifact['disk'], $artifact['final']);
    }

    /**
     * ADR 0030 §3 — apply the row's retention contract to its original once
     * the row's artifact `$final` is on `$disk`: in `markdown_only` (the
     * ROW's persisted stamp, never the configured mode of the day — a
     * `full_copy` version re-embedded after `KB_SOURCE_RETENTION` moved keeps
     * its original) the original binary is dropped under the storage key's
     * lock when every other referencing row already has a verified artifact
     * to stand in for it, and the rows are stamped `source_dropped`. Three
     * callers, one gate: the fresh ingest, the identical re-ingest whose
     * artifact was just verified, repaired or published for the first time,
     * `kb:artifacts-backfill`. A Markdown source IS its own artifact and is
     * never dropped; the original is a SHARED storage key (every version of
     * the path, and on a shared disk every tenant with the same key, points
     * at the same bytes), so it is dropped only when every row referencing
     * it — any tenant, trashed rows included — already has a verified
     * artifact to stand in for it; otherwise it is kept and the reason
     * logged. Rows whose original was dropped are stamped
     * `metadata.source_dropped = true` so the orphan sweeps never read the
     * missing file as an orphan.
     *
     * @param  $sourceReservationHeld  v8.36 / PR #479 Copilot review — some
     *     callers (`kb:artifacts-backfill`) already hold a `SourceInFlight`
     *     reservation over `$original` for the whole call, the same
     *     acquire-and-hold pattern `DocumentDeleter` uses. That reservation's
     *     lease is fixed and this method's own scan (`firstRowBlockingDrop()`,
     *     streamed over every referencing row) can outlive it, exactly like
     *     the storage-key lock below can — asserted at the same point, right
     *     before the delete, so a lapsed reservation refuses the drop instead
     *     of letting a fresh ingest that has since reserved and started
     *     reading the same original find it removed out from under it. `null`
     *     (the fresh-ingest / identical-re-ingest callers, which do not have
     *     a reservation object to hand down this deep) leaves the storage-key
     *     lock as the only guard, unchanged from before this parameter.
     * @return bool true when THIS call dropped the original
     */
    public function finalizeSourceRetention(KnowledgeDocument $document, string $disk, string $final, ?HeldLock $sourceReservationHeld = null): bool
    {
        $metadata = is_array($document->metadata) ? $document->metadata : [];
        if ($this->sourceRetentionOf($metadata) !== SourceRetentionResolver::MARKDOWN_ONLY || (string) $document->source_type === 'markdown') {
            return false;
        }
        $store = app(ConversionArtifactStore::class);
        $artifact = ['disk' => $disk, 'final' => $final];
        $prefix = StorageNamespace::recordedPrefix($metadata);
        try {
            $sourcePath = KbPath::normalize((string) $document->source_path);
            $original = $prefix === '' ? $sourcePath : KbPath::normalize($prefix.'/'.$sourcePath);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $storage = Storage::disk($artifact['disk']);
        if ($original === $artifact['final'] || ! $storage->exists($original)) {
            return false;
        }

        // ADR 0030 §3 — the reference scan and the delete run under the
        // storage key's lock, the same lock the persist paths hold around
        // their row commit (underSourceKeyLock()): a concurrent ingest of the
        // same `(disk, path)` cannot commit a `full_copy` row between the scan
        // and the delete. A lock that cannot be taken keeps the original —
        // the conservative direction — and says so.
        if (! ConversionArtifactStore::cacheStoreCanLock()) {
            // No serialization at all on this store: the drop keeps the
            // original (the conservative direction) rather than deleting a
            // shared file believing it is serialized.
            Log::warning('DocumentIngestor: markdown_only retention kept the original — the cache store cannot exclude concurrent holders, so the storage key lock is unavailable', [
                'document_id' => (int) $document->id,
                'disk' => $artifact['disk'],
                'path' => $original,
            ]);

            return false;
        }
        $lock = $this->sourceKeyLock($artifact['disk'], $original);
        try {
            $lock->block(SourceKeyLock::waitSeconds());
        } catch (LockTimeoutException) {
            Log::info('DocumentIngestor: markdown_only retention kept the original — the storage key is locked by a concurrent writer; the next identical ingest retries the drop', [
                'document_id' => (int) $document->id,
                'disk' => $artifact['disk'],
                'path' => $original,
            ]);

            return false;
        }
        try {
            return $this->dropOriginalUnderLock($storage, $store, $artifact, $document, $original, $sourcePath, new HeldLock($lock, 'storage key'), $sourceReservationHeld);
        } catch (LockLostException $e) {
            // The scan outlived the TTL of one of the two locks guarding this
            // section — the storage key, or the caller's SourceInFlight
            // reservation when it passed one: the delete is refused (the
            // conservative direction — the original stays, the next identical
            // ingest retries the drop), never run past a lapsed lock.
            // $e->getMessage() names which one lapsed.
            Log::warning('DocumentIngestor: markdown_only retention kept the original — a lock guarding the section lapsed during the reference scan; the next identical ingest retries the drop', [
                'document_id' => (int) $document->id,
                'disk' => $artifact['disk'],
                'path' => $original,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            HeldLock::releaseQuietly($lock);
        }
    }

    /**
     * @param  array{disk: string, final: string}  $artifact
     * @return bool true when the original was dropped
     */
    private function dropOriginalUnderLock(Filesystem $storage, ConversionArtifactStore $store, array $artifact, KnowledgeDocument $document, string $original, string $sourcePath, HeldLock $held, ?HeldLock $sourceReservationHeld = null): bool
    {
        // The original is dropped only once this row's final move has
        // succeeded (publish() threw otherwise) AND every other referencing
        // row's artifact is PRESENT on disk and VERIFIED (its bytes hash to
        // the row's `content_hash`, `document_hash` for a legacy pointer): a
        // pointer whose file never landed, or a corrupt sibling, does not
        // stand in for the original — the last valid representation is never
        // deleted. The scan streams (R3): the first blocking row ends it.
        $blocking = $this->firstRowBlockingDrop($store, $artifact['disk'], $original, $sourcePath, (int) $document->id);
        if ($blocking !== null) {
            Log::info('DocumentIngestor: markdown_only retention kept the original — another row referencing the same storage key still requires it (full_copy contract, or no verified artifact on disk)', [
                'document_id' => (int) $document->id,
                'blocking_document_id' => $blocking,
                'disk' => $artifact['disk'],
                'path' => $original,
            ]);

            return false;
        }
        // The scan may have outlived the TTL of either lock guarding this
        // section: the storage key, and — when the caller passed one — the
        // SourceInFlight reservation it holds over the same original. Both
        // are asserted right here, immediately before the irreversible step,
        // so either lapsing refuses the delete (LockLostException) instead of
        // one holder's expired lease letting a fresh ingest that has since
        // reserved and started reading find its source removed from under it.
        $held->assertHeld('markdown_only drop of the original');
        $sourceReservationHeld?->assertHeld('markdown_only drop of the original');
        if (! $storage->delete($original)) {
            Log::warning('DocumentIngestor: markdown_only retention could not drop the original after the artifact commit', [
                'document_id' => (int) $document->id,
                'disk' => $artifact['disk'],
                'path' => $original,
            ]);

            return false;
        }
        // Bounded pass (R3): every referencing row is stamped chunk by chunk,
        // never materialised as a whole.
        // `includeAmbiguous: false` — only rows whose recorded namespace really
        // names this key are stamped; an ambiguous one is left alone rather
        // than told its original was dropped (see the scan's docblock).
        $this->eachRowReferencingStorageKey($artifact['disk'], $original, $sourcePath, function (KnowledgeDocument $row): bool {
            $rowMetadata = is_array($row->metadata) ? $row->metadata : [];
            if (($rowMetadata['source_dropped'] ?? false) !== true && $row->updateUnscopedWithinOwnTenant(['metadata' => array_merge($rowMetadata, ['source_dropped' => true])]) === 0) {
                Log::warning('DocumentIngestor: source_dropped could not be stamped — the row is no longer the one read; the orphan sweeps fail closed on a missing file', ['document_id' => (int) $row->id]);
            }

            return true;
        }, includeAmbiguous: false);

        return true;
    }

    /**
     * The id of the first row referencing the storage key that still requires
     * the original, or null when none does.
     */
    private function firstRowBlockingDrop(ConversionArtifactStore $store, string $disk, string $fullPath, string $sourcePath, int $currentId): ?int
    {
        $blocking = null;
        $this->eachRowReferencingStorageKey($disk, $fullPath, $sourcePath, function (KnowledgeDocument $row) use ($store, $disk, $currentId, &$blocking): bool {
            $rowMetadata = is_array($row->metadata) ? $row->metadata : [];
            // A row ingested under full_copy (or before the stamp existed, or
            // with a stamp that is not a known mode) still requires the
            // original: its retention contract wins, and an invalid value is
            // the conservative `full_copy` — never a mode that permits the drop.
            $rowMode = $this->sourceRetentionOf($rowMetadata);
            if ((int) $row->id !== $currentId && $rowMode === SourceRetentionResolver::FULL_COPY) {
                $blocking = (int) $row->id;

                return false;
            }
            if ((int) $row->id !== $currentId && $rowMode === SourceRetentionResolver::REFERENCE_ONLY) {
                // A `reference_only` sibling stored NOTHING that could stand
                // in for the original (no artifact by design): the shared
                // original is the only thing a re-embed or a `kb:ocr` re-run
                // of that version can read, so it blocks the drop — the most
                // conservative arm (ADR 0030 §3).
                $blocking = (int) $row->id;

                return false;
            }
            if (! is_string($row->markdown_path) || $row->markdown_path === '') {
                $blocking = (int) $row->id;

                return false;
            }
            $rowDisk = StorageNamespace::recordedDisk($rowMetadata) ?? $disk;
            $expected = is_string($row->content_hash) && $row->content_hash !== '' ? $row->content_hash : (string) $row->document_hash;
            if (! $store->verifies($rowDisk, $row->markdown_path, $expected)) {
                $blocking = (int) $row->id;

                return false;
            }

            return true;
        });

        return $blocking;
    }

    /**
     * Stream every row — any tenant, any status, soft-deleted included — whose
     * `(disk, prefix + source_path)` resolves to the given storage key, in
     * id order and in bounded chunks (R3); the callback returns false to stop.
     * The same lookup `DocumentDeleter` guards the shared source file with.
     *
     * `$includeAmbiguous` decides what a row whose namespace CANNOT be resolved
     * (no usable recorded disk, or a prefix that will not normalize) means to
     * the caller, because the fail-closed direction is not the same for both:
     *  - the scan that decides whether a drop may happen passes `true` — an
     *    ambiguous row may own this object, so it blocks and the bytes stay;
     *  - the pass that STAMPS `source_dropped` on the rows whose original was
     *    dropped passes `false` — stamping a row whose namespace does not name
     *    this key records a claim nothing supports, and the orphan sweeps and
     *    the backfill both read that flag as fact.
     *
     * @param  callable(KnowledgeDocument): bool  $each
     */
    private function eachRowReferencingStorageKey(string $disk, string $fullPath, string $sourcePath, callable $each, bool $includeAmbiguous = true): void
    {
        KnowledgeDocument::withoutGlobalScopes()
            ->where('source_path', $sourcePath)
            // `tenant_id` rides along: the stamp write is bound to each row's
            // OWN tenant (updateUnscopedWithinOwnTenant, R30).
            ->select(['id', 'tenant_id', 'source_path', 'markdown_path', 'content_hash', 'document_hash', 'metadata'])
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($disk, $fullPath, $each, $includeAmbiguous): bool {
                foreach ($chunk as $row) {
                    $rowMetadata = is_array($row->metadata) ? $row->metadata : [];
                    // A row that never recorded a usable disk (pre-v8.36, or a
                    // null/empty/malformed value — StorageNamespace) is an
                    // AMBIGUOUS reference: it may live on any disk its logical
                    // path matches, so it counts on this one too — the same
                    // fail-closed rule as DocumentDeleter::documentReferencesStorageKey().
                    // Only a row whose RECORDED namespace resolves elsewhere is
                    // skipped.
                    $rowDisk = StorageNamespace::recordedDisk($rowMetadata);
                    if ($rowDisk === null) {
                        if ($includeAmbiguous && ! $each($row)) {
                            return false;
                        }

                        continue;
                    }
                    $rowPrefix = StorageNamespace::recordedPrefix($rowMetadata);
                    try {
                        $rowFull = $rowPrefix === '' ? KbPath::normalize((string) $row->source_path) : KbPath::normalize($rowPrefix.'/'.$row->source_path);
                    } catch (\InvalidArgumentException) {
                        // A recorded namespace that cannot be resolved is not
                        // proof the row lives elsewhere — it is the SAME
                        // ambiguity as a missing disk, so it is handed to the
                        // caller under the same rule (`$includeAmbiguous`):
                        // it blocks a drop, and it is never stamped.
                        if ($includeAmbiguous && ! $each($row)) {
                            return false;
                        }

                        continue;
                    }
                    if ($rowDisk !== $disk || $rowFull !== $fullPath) {
                        continue;
                    }
                    if (! $each($row)) {
                        return false;
                    }
                }

                return true;
            });
    }

    /**
     * The lock every writer of a storage key shares with the `markdown_only`
     * drop and with the orphan-file sweep's deletion of a source
     * ({@see SourceKeyLock}): held around a row commit (persist paths) and
     * around the reference scan + delete (drop, sweep), so none can slip
     * between another's two halves. Needs an atomic lock store (Redis in
     * production): on a per-host store the halves of different pods are not
     * serialized.
     */
    private function sourceKeyLock(string $disk, string $fullPath): \Illuminate\Contracts\Cache\Lock
    {
        return SourceKeyLock::make($disk, $fullPath);
    }

    /**
     * Whether a row commit must be serialized with the `markdown_only` drop of
     * its storage key: every commit of a NON-Markdown source while the flag is
     * on — whatever this row's own contract. A `reference_only` version stores
     * no artifact but still requires the shared original, so it must not
     * commit past a concurrent drop's reference scan (it would then require a
     * file already gone); a dry run commits nothing; a Markdown source is its
     * own artifact and is never dropped; with the flag off no drop is
     * possible at all (R43: nothing waits on the lock store).
     *
     * @param  array<string,mixed>  $metadata
     */
    private function sourceKeyLockNeeded(string $sourceType, array $metadata): bool
    {
        // `dry_run` never reaches a persist today (stripped by
        // OcrService::stripTrustedOnlyKeys() at the HTTP boundary and by
        // stripRunControlKeys() in PersistChunksStep): the arm mirrors
        // stageArtifact() so a dry run, which commits nothing, waits on nothing.
        if ($sourceType === 'markdown' || ($metadata['dry_run'] ?? false) === true) {
            return false;
        }

        return app(ConversionArtifactStore::class)->enabled();
    }

    /**
     * Run `$commit` under the storage key's lock (see sourceKeyLock()) — but
     * ONLY when a `markdown_only` drop of the same key is possible at all
     * (`$needed`, i.e. {@see sourceKeyLockNeeded()}: a non-Markdown source
     * while the artifacts flag is on): with the flag off, in a dry run, or for
     * a Markdown source (never dropped) there is nothing to serialize against
     * and an ingest never waits on, nor depends on, a lock store (R43).
     * `reference_only` DOES lock: it stores no artifact of its own, so it
     * depends on the shared original and must not commit past a concurrent
     * drop's reference scan — removing that lock would reintroduce the race
     * (`test_a_reference_only_ingest_commits_under_the_storage_key_lock_while_the_flag_is_on`). A
     * lock that cannot be taken in time fails the persist loudly — a queued
     * ingest retries, a caller sees the error — never a silent commit past
     * a drop in progress.
     *
     * @template T
     *
     * @param  array<string,mixed>  $metadata
     * @param  callable(?HeldLock): T  $commit  receives the held lock (null when none was needed) to assert before it commits
     * @return T
     */
    private function underSourceKeyLock(string $sourcePath, array $metadata, bool $needed, callable $commit): mixed
    {
        if (! $needed) {
            return $commit();
        }
        $disk = StorageNamespace::diskOf($metadata);
        $prefix = StorageNamespace::recordedPrefix($metadata);
        try {
            $fullPath = $prefix === '' ? KbPath::normalize($sourcePath) : KbPath::normalize($prefix.'/'.$sourcePath);
        } catch (\InvalidArgumentException) {
            return $commit();
        }
        if (! ConversionArtifactStore::cacheStoreCanLock()) {
            // The same posture as the artifact path lock: a store that cannot
            // lock — or one that grants every lock without excluding anyone —
            // gives no serialization at all, and a commit that assumed one
            // would be worse than a refused ingest (SEC-FAILCLOSED-001).
            throw new \RuntimeException('DocumentIngestor: the cache store cannot hold locks, so the storage key lock is unavailable; the ingest is refused (configure a lock-capable cache store — Redis in production).');
        }
        $lock = $this->sourceKeyLock($disk, $fullPath);
        $lock->block(SourceKeyLock::waitSeconds());
        $held = new HeldLock($lock, 'storage key');
        // Presence BEFORE the commit: only a source that WAS there and is
        // gone afterwards is a holder having taken it. An ingest whose bytes
        // never touched this disk (a benchmark corpus, an API batch) must not
        // be stamped `source_dropped` for a file that never existed.
        $wasPresent = $this->originalPresentQuietly($disk, $fullPath);
        $this->sourceTakenDuringCommit = null; // never carry a previous call's verdict into this one
        try {
            // The commit receives its lock: it asserts, inside the
            // transaction and before it commits, that the TTL has not lapsed
            // (commitOnlyIfStillHeld()) — a lost lock rolls the row back.
            $result = $commit($held);
            // …and once more AFTER the transaction returns, because Laravel
            // issues the COMMIT itself when the closure returns: the row
            // becomes visible to a concurrent drop or sweep only then. A TTL
            // that lapsed across that last stretch means the key could have
            // been taken while this row was still invisible, so the state is
            // reconciled and reported rather than assumed (see the method).
            $this->reconcileIfKeyLockLapsedAcrossCommit($held, $disk, $fullPath, $result, $wasPresent);

            return $result;
        } finally {
            HeldLock::releaseQuietly($lock);
        }
    }

    /**
     * The one window the storage key's lock cannot close by itself: the
     * in-transaction assertion happens before the closure returns, and
     * Laravel issues the COMMIT after it, so between the two the row is
     * still invisible while the lock may already have lapsed. A concurrent
     * `markdown_only` drop or orphan sweep could then take the key, not see
     * this row, and delete the shared original.
     *
     * Closing it properly needs the row's visibility and the key to be
     * serialized by the SAME authority — a database-level key lock taken
     * inside the transaction — which is recorded as a follow-up (the cache
     * lock cannot make an uncommitted row visible). Until then the outcome
     * is not assumed: when the lock lapsed across the commit, the original
     * is probed and, if it is gone, the row is stamped `source_dropped` so
     * the sweeps and the Time Machine read a consistent state — the
     * artifact published right after this call is what stands in for it —
     * and the loss is reported at `error` when nothing does (R14: never a
     * row that silently references a file no longer there).
     */
    private function reconcileIfKeyLockLapsedAcrossCommit(HeldLock $held, string $disk, string $fullPath, mixed $result, ?bool $wasPresent): void
    {
        // The row is COMMITTED by the time this runs: reconciliation is
        // best-effort diagnosis and must never turn a decision already taken
        // into a failure — a cache blip on the ownership read, a database
        // that refuses the stamp, a broken log channel (the same principle as
        // HeldLock::releaseQuietly()).
        try {
            $this->reconcileKeyLockLapse($held, $disk, $fullPath, $result, $wasPresent);
        } catch (\Throwable $e) {
            Log::warning('DocumentIngestor: the post-commit key-lock reconciliation could not complete; the row is committed and its artifact publish follows', ['disk' => $disk, 'path' => $fullPath, 'exception' => $e::class, 'error' => $e->getMessage()]);
        }
    }

    /** @see reconcileIfKeyLockLapsedAcrossCommit() — the body it guards. */
    private function reconcileKeyLockLapse(HeldLock $held, string $disk, string $fullPath, mixed $result, ?bool $wasPresent): void
    {
        try {
            $held->assertHeld('the commit that just landed');

            return;
        } catch (LockLostException $e) {
            $lapse = $e->getMessage();
        }
        $present = $this->originalPresentQuietly($disk, $fullPath);
        if ($present === null) {
            Log::warning('DocumentIngestor: the storage key lock lapsed across the commit and the original could not be probed', ['disk' => $disk, 'path' => $fullPath, 'error' => $lapse]);

            return;
        }
        if ($wasPresent !== true || $present) {
            // Either the original is still there, or it was never on this
            // disk to begin with: nothing was taken, and the lapse is a TTL
            // to raise, not an incident.
            Log::warning('DocumentIngestor: the storage key lock lapsed across the commit; no original was taken — raise kb.conversion_artifacts.source_lock_seconds above the longest ingest transaction', ['disk' => $disk, 'path' => $fullPath, 'original_before' => $wasPresent, 'original_after' => $present, 'error' => $lapse]);

            return;
        }
        $document = $result instanceof KnowledgeDocument ? $result : null;
        $artifact = $document !== null ? (string) $document->markdown_path : '';
        if ($document === null || $artifact === '') {
            Log::error('DocumentIngestor: the original is gone and no artifact stands in for it — the storage key lock lapsed across the commit while a concurrent drop or sweep held the key; re-ingest the source', ['disk' => $disk, 'path' => $fullPath, 'document_id' => $document?->id, 'error' => $lapse]);

            return;
        }
        // Detected here — under the lock, the only place ownership can still
        // be read — but NOT stamped here: `source_dropped` says "an artifact
        // stands in for the original", and the artifact is only moved into
        // place after this call. A stamp written now would survive a publish
        // that then fails, leaving a row with no source, no artifact and the
        // very flag that silences the orphan sweep. The verdict is therefore
        // handed to the publish step (stampSourceTakenIfPending()).
        $this->sourceTakenDuringCommit = ['document_id' => (int) $document->id, 'disk' => $disk, 'path' => $fullPath, 'markdown_path' => $artifact, 'error' => $lapse];
        Log::error('DocumentIngestor: the original was dropped by a concurrent holder while this row committed; its artifact must stand in for the source', ['disk' => $disk, 'path' => $fullPath, 'document_id' => (int) $document->id, 'markdown_path' => $artifact, 'error' => $lapse]);
    }

    /**
     * The verdict of the last post-commit reconciliation, consumed by the
     * publish step: the row's original was taken by a concurrent holder while
     * it committed, so it must be stamped `source_dropped` — but only once
     * its artifact is really on the disk to stand in for it.
     *
     * @var array{document_id: int, disk: string, path: string, markdown_path: string, error: string}|null
     */
    private ?array $sourceTakenDuringCommit = null;

    /**
     * Stamp the row whose original a concurrent holder took while it
     * committed, now that its artifact is (or is not) in place. `$published`
     * false means nothing stands in for the source: that is reported, never
     * stamped — the orphan sweep must keep seeing the row.
     */
    private function stampSourceTakenIfPending(KnowledgeDocument $document, bool $published): void
    {
        $verdict = $this->sourceTakenDuringCommit;
        $this->sourceTakenDuringCommit = null;
        if ($verdict === null || $verdict['document_id'] !== (int) $document->id) {
            return;
        }
        if (! $published) {
            Log::error('DocumentIngestor: the original was taken while this row committed and its artifact could NOT be published — the row is left unstamped so the sweeps still see it; re-ingest the source', $verdict);

            return;
        }
        try {
            $metadata = is_array($document->metadata) ? $document->metadata : [];
            if (($metadata['source_dropped'] ?? false) === true) {
                return;
            }
            if ($document->updateUnscopedWithinOwnTenant(['metadata' => array_merge($metadata, ['source_dropped' => true])]) === 0) {
                // The row is no longer the one read — the very holder this
                // exists for may have repointed or removed it: say so instead
                // of claiming a stamp that did not land (R4).
                Log::error('DocumentIngestor: source_dropped could NOT be stamped after the publish — the row is no longer the one read', $verdict);

                return;
            }
            $document->metadata = array_merge($metadata, ['source_dropped' => true]);
        } catch (\Throwable $e) {
            // Best-effort, like the reconciliation it completes: the row and
            // its artifact are both committed by now.
            Log::warning('DocumentIngestor: source_dropped could not be stamped after the publish', $verdict + ['exception' => $e::class, 'error' => $e->getMessage()]);
        }
    }

    /** Whether the original is on the disk right now; null when the disk could not say. */
    private function originalPresentQuietly(string $disk, string $fullPath): ?bool
    {
        try {
            return Storage::disk($disk)->exists($fullPath);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The last statement of a row commit that runs under the storage key's
     * lock: the lock has a TTL and no renewal, so before the transaction
     * commits the row the holder asserts the lock is still its own — a
     * commit past a lapsed lock could land between a concurrent
     * `markdown_only` drop's scan and its delete. A lost lock throws
     * (LockLostException), the transaction rolls back, the job retries.
     * A commit that needed no lock (`$held` null) passes through.
     */
    private function commitOnlyIfStillHeld(?HeldLock $held, KnowledgeDocument $document): KnowledgeDocument
    {
        $held?->assertHeld('row commit');

        return $document;
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
        $tenantId = app(TenantContext::class)->current();
        // R21 — Lock the WHOLE family first, whatever each row's status, and
        // only then archive. The bare UPDATE below is not enough on its own
        // under MVCC: PostgreSQL READ COMMITTED evaluates its WHERE at scan
        // time and, when a row it matched was locked by another transaction,
        // re-checks only THAT row after the lock clears. A row that did NOT
        // match at scan time is never revisited — so an archived version that
        // a concurrent Time Machine restore activates in the window between
        // this scan and this commit is missed, and the family ends with two
        // active rows.
        //
        // The locking read has no status predicate, so it takes the restore's
        // target too. Whichever transaction reaches the family second blocks
        // on the other's rows and re-reads them afterwards: restore-first
        // means this UPDATE sees the newly active row and archives it;
        // ingest-first means the restore's own post-update sweep (the
        // `$concurrentlyActive` pass in DocumentVersionService) sees this row
        // and archives it. Either order leaves exactly one active version.
        //
        // R30/R31 — scope by tenant_id so a re-ingest under tenant A never
        // archives the same `(project_key, source_path)` row owned by
        // tenant B. project_key + source_path are NOT globally unique.
        // R3 — ids only, and the family is capped by kb:prune-archived-versions.
        KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->where('source_path', $sourcePath)
            ->where('id', '!=', $currentDocumentId)
            ->lockForUpdate()
            ->pluck('id');

        KnowledgeDocument::query()
            ->forTenant($tenantId)
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
