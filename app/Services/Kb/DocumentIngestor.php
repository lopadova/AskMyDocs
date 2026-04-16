<?php

namespace App\Services\Kb;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\DB;

/**
 * Document ingestion pipeline.
 *
 * Chunks source documents, generates embeddings (with caching),
 * and stores everything in PostgreSQL with pgvector.
 *
 * TODO:
 * - parser PDF / DOCX
 * - remove / archive chunk obsoleti
 * - queue batch
 */
class DocumentIngestor
{
    public function __construct(
        protected MarkdownChunker $markdownChunker,
        protected EmbeddingCacheService $embeddingCache,
    ) {}

    public function ingestMarkdown(
        string $projectKey,
        string $sourcePath,
        string $title,
        string $markdown,
        array $metadata = [],
    ): KnowledgeDocument {
        $documentHash = hash('sha256', $markdown);
        $versionHash = $documentHash;

        // Skip if document content hasn't changed
        $existing = KnowledgeDocument::where('project_key', $projectKey)
            ->where('source_path', $sourcePath)
            ->where('version_hash', $versionHash)
            ->first();

        if ($existing) {
            $existing->update(['indexed_at' => now()]);

            return $existing;
        }

        $chunks = $this->markdownChunker->chunk($sourcePath, $markdown);

        // Generate embeddings with cache (only new texts call the API)
        $embeddingResponse = $this->embeddingCache->generate(
            $chunks->pluck('text')->all()
        );

        return DB::transaction(function () use (
            $projectKey,
            $sourcePath,
            $title,
            $metadata,
            $documentHash,
            $versionHash,
            $chunks,
            $embeddingResponse,
        ) {
            $document = KnowledgeDocument::updateOrCreate(
                [
                    'project_key' => $projectKey,
                    'source_path' => $sourcePath,
                    'version_hash' => $versionHash,
                ],
                [
                    'source_type' => 'markdown',
                    'title' => $title,
                    'mime_type' => 'text/markdown',
                    'language' => $metadata['language'] ?? 'it',
                    'access_scope' => $metadata['access_scope'] ?? 'internal',
                    'status' => 'active',
                    'document_hash' => $documentHash,
                    'metadata' => $metadata,
                    'indexed_at' => now(),
                ]
            );

            KnowledgeDocument::query()
                ->where('project_key', $projectKey)
                ->where('source_path', $sourcePath)
                ->where('id', '!=', $document->id)
                ->where('status', '!=', 'archived')
                ->update(['status' => 'archived']);

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

            return $document;
        });
    }
}
