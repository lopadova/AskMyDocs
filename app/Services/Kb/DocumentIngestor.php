<?php

namespace App\Services\Kb;

use App\Jobs\CanonicalIndexerJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Canonical\CanonicalParsedDocument;
use App\Services\Kb\Canonical\CanonicalParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Document ingestion pipeline.
 *
 * Chunks source documents, generates embeddings (with caching), stores
 * everything in PostgreSQL with pgvector, and (new since Phase 2 canonical
 * compilation) populates canonical metadata when the markdown carries
 * valid YAML frontmatter. Canonical-indexed documents also dispatch
 * {@see CanonicalIndexerJob} after commit to build the kb_nodes / kb_edges
 * graph projection.
 *
 * Graceful degradation (R4): markdown with malformed frontmatter is
 * ingested as a regular non-canonical document, logged as a warning.
 * Ingestion never fails on a frontmatter issue.
 */
class DocumentIngestor
{
    protected CanonicalParser $canonicalParser;

    public function __construct(
        protected MarkdownChunker $markdownChunker,
        protected EmbeddingCacheService $embeddingCache,
        ?CanonicalParser $canonicalParser = null,
    ) {
        // CanonicalParser is stateless and has no deps of its own, so we
        // can default-instantiate it when callers don't wire it explicitly
        // (e.g. legacy unit tests built before Phase 2).
        $this->canonicalParser = $canonicalParser ?? new CanonicalParser();
    }

    public function ingestMarkdown(
        string $projectKey,
        string $sourcePath,
        string $title,
        string $markdown,
        array $metadata = [],
    ): KnowledgeDocument {
        $documentHash = hash('sha256', $markdown);
        $versionHash = $documentHash;

        $existing = $this->findExistingVersion($projectKey, $sourcePath, $versionHash);
        if ($existing !== null) {
            $existing->update(['indexed_at' => now()]);
            return $existing;
        }

        $canonical = $this->tryParseCanonical($projectKey, $sourcePath, $markdown);
        $chunks = $this->markdownChunker->chunk($sourcePath, $markdown);
        $embeddingResponse = $this->embeddingCache->generate($chunks->pluck('text')->all());

        $document = DB::transaction(fn () => $this->persistDocumentAndChunks(
            $projectKey,
            $sourcePath,
            $title,
            $metadata,
            $documentHash,
            $versionHash,
            $chunks,
            $embeddingResponse,
            $canonical,
        ));

        $this->dispatchCanonicalIndexerIfCanonical($document);

        return $document;
    }

    // -----------------------------------------------------------------
    // lookup
    // -----------------------------------------------------------------

    private function findExistingVersion(string $projectKey, string $sourcePath, string $versionHash): ?KnowledgeDocument
    {
        return KnowledgeDocument::where('project_key', $projectKey)
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
    // persistence (wrapped in transaction by the caller)
    // -----------------------------------------------------------------

    private function persistDocumentAndChunks(
        string $projectKey,
        string $sourcePath,
        string $title,
        array $metadata,
        string $documentHash,
        string $versionHash,
        $chunks,
        $embeddingResponse,
        ?CanonicalParsedDocument $canonical,
    ): KnowledgeDocument {
        $attributes = $this->buildDocumentAttributes($title, $metadata, $documentHash, $canonical);
        $document = KnowledgeDocument::updateOrCreate(
            [
                'project_key' => $projectKey,
                'source_path' => $sourcePath,
                'version_hash' => $versionHash,
            ],
            $attributes,
        );

        $this->archivePreviousVersions($projectKey, $sourcePath, $document->id);
        $this->persistChunks($document, $projectKey, $chunks, $embeddingResponse);

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDocumentAttributes(
        string $title,
        array $metadata,
        string $documentHash,
        ?CanonicalParsedDocument $canonical,
    ): array {
        $base = [
            'source_type' => 'markdown',
            'title' => $canonical !== null ? ($this->firstLine($canonical->body) ?? $title) : $title,
            'mime_type' => 'text/markdown',
            'language' => $metadata['language'] ?? 'it',
            'access_scope' => $metadata['access_scope'] ?? 'internal',
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
            'title' => $title,   // caller-supplied title always wins; parse() summary is separate
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

    private function firstLine(string $text): ?string
    {
        $line = strtok($text, "\n");
        if ($line === false) {
            return null;
        }
        $trimmed = trim(ltrim($line, '# '));
        return $trimmed === '' ? null : $trimmed;
    }

    private function archivePreviousVersions(string $projectKey, string $sourcePath, int $currentDocumentId): void
    {
        KnowledgeDocument::query()
            ->where('project_key', $projectKey)
            ->where('source_path', $sourcePath)
            ->where('id', '!=', $currentDocumentId)
            ->where('status', '!=', 'archived')
            ->update(['status' => 'archived']);
    }

    private function persistChunks(
        KnowledgeDocument $document,
        string $projectKey,
        $chunks,
        $embeddingResponse,
    ): void {
        foreach ($chunks as $index => $chunk) {
            KnowledgeChunk::updateOrCreate(
                [
                    'knowledge_document_id' => $document->id,
                    'chunk_hash' => hash('sha256', $chunk['text']),
                ],
                [
                    'project_key' => $projectKey,
                    'chunk_order' => $index,
                    'heading_path' => $chunk['heading_path'],
                    'chunk_text' => $chunk['text'],
                    'metadata' => $chunk['metadata'],
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
        CanonicalIndexerJob::dispatch($document->id);
    }
}
