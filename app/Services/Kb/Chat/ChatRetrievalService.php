<?php

declare(strict_types=1);

namespace App\Services\Kb\Chat;

use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Kb\Retrieval\RetrievalGrounding;
use App\Services\Kb\Retrieval\SearchResult;

/**
 * The single retrieval-quality path shared by every chat surface
 * (KbChatController, MessageController, MessageStreamController).
 *
 * Before v8.1, `/api/kb/chat` ran `searchWithContext()` (primary + graph-
 * expanded + rejected-approach context) while the conversation + stream
 * endpoints ran the bare `search()` (primary only). The same question
 * therefore produced different grounding context AND different citations
 * depending on which channel the client used. This service collapses the
 * three onto ONE retrieval call, ONE grounding gate, ONE citation builder,
 * and ONE prompt-context shape — the retrieval analogue of the codebase's
 * "two ingestion entrypoints, one execution path" invariant.
 */
final class ChatRetrievalService
{
    public function __construct(private readonly KbSearchService $search)
    {
    }

    /**
     * Run the full context-aware retrieval (primary + expanded + rejected)
     * with the standard chat limits, so every channel over-retrieves and
     * reranks identically.
     */
    public function retrieve(string $query, ?string $projectKey, ?RetrievalFilters $filters = null): SearchResult
    {
        return $this->search->searchWithContext(
            query: $query,
            projectKey: $projectKey,
            limit: (int) config('kb.default_limit', 8),
            minSimilarity: (float) config('kb.default_min_similarity', 0.30),
            filters: $filters,
        );
    }

    /**
     * Deterministic refusal decision on the reranked primary set. Delegates
     * to {@see RetrievalGrounding} so the rerank_score-OR-vector gate and
     * the shape-agnostic reads stay in one place.
     */
    public function shouldRefuse(SearchResult $result): bool
    {
        return RetrievalGrounding::shouldRefuse($result->primary);
    }

    /**
     * The variables the `prompts.kb_rag` blade needs to render its typed
     * blocks (⚠ REJECTED APPROACHES / 📎 RELATED CONTEXT / ## Context).
     * Callers merge `projectKey` + `fewShotExamples` themselves.
     *
     * @return array{chunks: \Illuminate\Support\Collection, expanded: \Illuminate\Support\Collection, rejected: \Illuminate\Support\Collection}
     */
    public function promptContext(SearchResult $result): array
    {
        return [
            'chunks' => $result->primary,
            'expanded' => $result->expanded,
            'rejected' => $result->rejected,
        ];
    }

    /**
     * Citations grouped per source document with an `origin` marker
     * (primary | related | rejected) so the UI can label them distinctly.
     * Shape-agnostic reads via data_get (chunks are arrays in production).
     *
     * @return list<array{document_id: ?int, title: string, source_path: ?string, headings: list<string>, chunks_used: int, origin: string}>
     */
    public function buildCitations(SearchResult $result): array
    {
        $citations = [];
        $this->appendCitations($result->primary, 'primary', $citations);
        $this->appendCitations($result->expanded, 'related', $citations);
        $this->appendCitations($result->rejected, 'rejected', $citations);

        return array_values($citations);
    }

    /**
     * Distinct source paths across all three buckets (chat-log `sources`).
     *
     * @return list<string>
     */
    public function collectSources(SearchResult $result): array
    {
        return $result->primary
            ->concat($result->expanded)
            ->concat($result->rejected)
            ->pluck('document.source_path')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Per-chunk evidence for a citation (v8.1 P1). `evidence_hash` prefers
     * the persisted `chunk_hash` (SHA-256 set at ingest) and falls back to
     * hashing the chunk text, so it's stable across the request even when
     * the chunk array didn't carry the column.
     *
     * @return array{chunk_id: mixed, heading: ?string, score: float, snippet: string, evidence_hash: ?string}
     */
    private static function chunkEvidence(mixed $chunk): array
    {
        $text = (string) data_get($chunk, 'chunk_text', '');
        $hash = data_get($chunk, 'chunk_hash')
            ?? data_get($chunk, 'metadata.chunk_hash')
            ?? ($text !== '' ? hash('sha256', $text) : null);

        return [
            'chunk_id' => data_get($chunk, 'chunk_id'),
            'heading' => data_get($chunk, 'heading_path'),
            // Final ranking score; vector_score only as a fallback for
            // candidates that never went through the reranker.
            'score' => (float) (data_get($chunk, 'rerank_score') ?? data_get($chunk, 'vector_score') ?? 0.0),
            'snippet' => \Illuminate\Support\Str::limit($text, 240),
            'evidence_hash' => $hash,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $chunks
     * @param  array<string, array<string, mixed>>  $citations
     */
    private function appendCitations(\Illuminate\Support\Collection $chunks, string $origin, array &$citations): void
    {
        // Group by document id (stable, never null for a persisted doc),
        // falling back to source_path only when the id is absent — the old
        // source_path-only grouping collided when two docs shared a null /
        // duplicate path. R27: keys unchanged, just a sturdier group key.
        foreach ($chunks->groupBy(fn ($c) => data_get($c, 'document.id') ?? 'path:' . data_get($c, 'document.source_path')) as $groupKey => $group) {
            $key = $origin . ':' . $groupKey;
            if (isset($citations[$key])) {
                continue;
            }
            $first = $group->first();
            $citations[$key] = [
                'document_id' => data_get($first, 'document.id'),
                'title' => data_get($first, 'document.title', 'Untitled'),
                'source_path' => data_get($first, 'document.source_path'),
                // R27 — `source_type` was present on the conversation
                // channel's citations but not /api/kb/chat's; expose it on
                // the unified shape so no channel loses a key it had.
                'source_type' => data_get($first, 'document.source_type'),
                'headings' => $group->pluck('heading_path')->filter()->unique()->values()->all(),
                'chunks_used' => $group->count(),
                'origin' => $origin,
                // v8.1 P1 — evidence-grade per-chunk provenance (R27
                // additive). Lets an auditor answer "why was this cited?":
                // which chunk, its heading, its final rank score, a text
                // snippet, and a content fingerprint that survives re-
                // ingestion so a citation can be traced to exact bytes.
                'chunks' => $group->map(fn ($c) => self::chunkEvidence($c))->values()->all(),
            ];
        }
    }
}
