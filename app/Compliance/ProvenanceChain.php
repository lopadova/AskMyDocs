<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Models\ChatLog;
use App\Models\ChatLogProvenance;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;

/**
 * v6.0/W7 — Cross-system provenance chain helper.
 *
 * Stitches an eval-harness trace (or a ChatLog row) back through the
 * retrieval chunks → source documents → frontmatter metadata so an
 * auditor can answer "what data ultimately grounded this answer?". The
 * chain survives hard deletes of knowledge_chunks because
 * chat_log_provenance denormalizes source_path + uses ON DELETE
 * CASCADE only on chat_logs / messages.
 *
 * Two entry points:
 *   - `trace(array $input)` — legacy v6/W6 signature, returns the
 *     pre-computed shape verbatim (used by unit tests + callers that
 *     already have the lineage at hand).
 *   - `forChatLog(int $chatLogId)` — production wiring. Reads
 *     chat_log_provenance rows + joins knowledge_chunks +
 *     knowledge_documents (withTrashed for forensic survival).
 */
final class ProvenanceChain
{
    /**
     * @param  array{
     *   eval_trace_id?:string|int|null,
     *   retrieval?:array,
     *   chunk?:array,
     *   document?:array,
     *   frontmatter_author?:string|null
     * }  $input
     */
    public function trace(array $input): array
    {
        return [
            'eval_trace_id' => $input['eval_trace_id'] ?? null,
            'retrieval' => $input['retrieval'] ?? [],
            'chunk' => $input['chunk'] ?? [],
            'document' => $input['document'] ?? [],
            'frontmatter_author' => $input['frontmatter_author'] ?? null,
        ];
    }

    /**
     * Build the full provenance chain for one ChatLog row.
     *
     * @return array{
     *   chat_log_id:int,
     *   tenant_id:?string,
     *   spans:list<array{
     *     answer_token_start:int,
     *     answer_token_end:int,
     *     contribution_score:float,
     *     source_path:string,
     *     chunk:array<string, mixed>,
     *     document:array<string, mixed>,
     *     frontmatter_author:?string
     *   }>
     * }
     */
    public function forChatLog(int $chatLogId): array
    {
        // R30 — every read here is scoped to the active tenant so a guessed
        // chat-log / chunk / document id from another tenant can't bleed into
        // the provenance chain (forensic/compliance integrity).
        $tenantId = app(TenantContext::class)->current();

        $log = ChatLog::query()->forTenant($tenantId)->find($chatLogId);

        $spans = ChatLogProvenance::query()
            ->where('chat_log_id', $chatLogId)
            ->orderBy('answer_token_start')
            ->get();

        $chunkIds = $spans->pluck('knowledge_chunk_id')->filter()->unique()->all();
        $chunks = collect();
        $documents = collect();
        if ($chunkIds !== []) {
            $chunks = KnowledgeChunk::query()->forTenant($tenantId)->whereIn('id', $chunkIds)->get()->keyBy('id');
            $documentIds = $chunks->pluck('knowledge_document_id')->filter()->unique()->all();
            if ($documentIds !== []) {
                $documents = KnowledgeDocument::query()
                    ->forTenant($tenantId)
                    ->withTrashed()
                    ->whereIn('id', $documentIds)
                    ->get()
                    ->keyBy('id');
            }
        }

        $spansOut = [];
        foreach ($spans as $span) {
            $chunk = $chunks->get($span->knowledge_chunk_id);
            $document = $chunk ? $documents->get($chunk->knowledge_document_id) : null;
            $frontmatter = is_array($document?->frontmatter_json ?? null) ? $document->frontmatter_json : [];
            $spansOut[] = [
                'answer_token_start' => (int) $span->answer_token_start,
                'answer_token_end' => (int) $span->answer_token_end,
                'contribution_score' => (float) $span->contribution_score,
                'source_path' => (string) $span->source_path,
                'chunk' => $chunk
                    ? [
                        'id' => $chunk->id,
                        'chunk_order' => $chunk->chunk_order ?? null,
                        'heading_path' => $chunk->heading_path ?? null,
                        'preview' => mb_substr((string) ($chunk->chunk_text ?? ''), 0, 240),
                    ]
                    : ['missing' => true],
                'document' => $document
                    ? [
                        'id' => $document->id,
                        'title' => $document->title,
                        'project_key' => $document->project_key,
                        'doc_id' => $document->doc_id ?? null,
                        'slug' => $document->slug ?? null,
                        'canonical_status' => $document->canonical_status ?? null,
                        'soft_deleted' => $document->deleted_at !== null,
                    ]
                    : ['missing' => true],
                'frontmatter_author' => $frontmatter['author'] ?? null,
            ];
        }

        return [
            'chat_log_id' => $chatLogId,
            'tenant_id' => $tenantId,
            'spans' => $spansOut,
        ];
    }
}
