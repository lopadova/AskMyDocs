<?php

declare(strict_types=1);

namespace App\Services\Kb\Investigation;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;

/**
 * Turns a vector candidate into bounded, readable source evidence.
 *
 * Short documents (including ordinary IMAP messages) are read in full. For a
 * long source the reader opens only the matching chunk and neighbouring chunks;
 * this prevents a distant manual chapter from leaking into a grounded answer.
 */
class KbSourceReader
{
    public function __construct(private readonly TenantContext $tenant)
    {
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>|null
     */
    public function readCandidate(array $candidate): ?array
    {
        $documentId = (int) data_get($candidate, 'document.id', 0);
        $chunkId = (int) data_get($candidate, 'chunk_id', 0);
        if ($documentId < 1 || $chunkId < 1) {
            return null;
        }

        // The document model keeps the normal ACL global scope. We never read
        // adjacent chunks merely because a caller guessed a document id.
        $document = KnowledgeDocument::query()
            ->forTenant($this->tenant->current())
            ->whereKey($documentId)
            ->first();
        if ($document === null) {
            return null;
        }

        $anchor = KnowledgeChunk::query()
            ->forTenant($this->tenant->current())
            ->where('knowledge_document_id', $document->id)
            ->whereKey($chunkId)
            ->first(['id', 'chunk_order']);
        if ($anchor === null) {
            return null;
        }

        $chunks = KnowledgeChunk::query()
            ->forTenant($this->tenant->current())
            ->where('knowledge_document_id', $document->id)
            ->orderBy('chunk_order')
            ->get(['id', 'chunk_order', 'chunk_text']);
        $totalChars = $chunks->sum(fn (KnowledgeChunk $chunk): int => mb_strlen((string) $chunk->chunk_text));
        $fullLimit = (int) config('kb.investigation.full_source_max_chars', 18000);
        $isShort = $totalChars <= $fullLimit;

        if ($isShort) {
            $excerpt = $chunks->pluck('chunk_text')->implode("\n\n");
            $usedChunks = $chunks;
        } else {
            $radius = max(0, (int) config('kb.investigation.long_source_neighbor_radius', 1));
            $usedChunks = $chunks
                ->filter(fn (KnowledgeChunk $chunk): bool => $chunk->chunk_order >= $anchor->chunk_order - $radius
                    && $chunk->chunk_order <= $anchor->chunk_order + $radius)
                ->values();
            $excerpt = $usedChunks->pluck('chunk_text')->implode("\n\n");
            $excerpt = mb_substr($excerpt, 0, (int) config('kb.investigation.long_source_max_chars', 9000));
        }

        $excerpt = trim($excerpt);
        if ($excerpt === '') {
            return null;
        }

        return [
            'document_id' => (int) $document->id,
            'title' => (string) $document->title,
            'source_path' => (string) $document->source_path,
            'source_type' => (string) $document->source_type,
            'excerpt' => $excerpt,
            'complete_source' => $isShort,
            'chunk_ids' => $usedChunks->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            'candidate' => $candidate,
        ];
    }
}
