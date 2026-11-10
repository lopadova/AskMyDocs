<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;

/**
 * Direct, fingerprint-controlled seeding of documents and chunks for tests
 * that exercise READ paths (facets, suggestions, line ranges, path contexts)
 * and must not depend on the ingestion pipeline.
 */
trait SeedsKbDocuments
{
    /** @param array<string,mixed> $attrs */
    protected function seedDoc(string $tenant, string $project, string $path, array $attrs = []): KnowledgeDocument
    {
        return KnowledgeDocument::create(array_merge([
            'tenant_id' => $tenant,
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => pathinfo($path, PATHINFO_FILENAME),
            'source_path' => $path,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'status' => 'active',
            'document_hash' => hash('sha256', $tenant.$project.$path),
            'version_hash' => hash('sha256', $tenant.$project.$path),
            'metadata' => [],
        ], $attrs));
    }

    protected function seedChunk(KnowledgeDocument $doc, int $order, string $text, ?string $heading = null): KnowledgeChunk
    {
        return KnowledgeChunk::create([
            'tenant_id' => $doc->tenant_id,
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => $order,
            'chunk_hash' => hash('sha256', $doc->id.'-'.$order.'-'.$text),
            'heading_path' => $heading ?? '',
            'chunk_text' => $text,
            'metadata' => [],
            'embedding' => [0.1, 0.2, 0.3],
        ]);
    }
}
