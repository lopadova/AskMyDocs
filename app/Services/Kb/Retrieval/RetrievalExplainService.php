<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\Agent\DocumentLineReader;
use App\Support\TenantContext;
use App\Services\Kb\Investigation\KbInvestigationService;
use App\Services\Kb\KbSearchService;
use Illuminate\Support\Facades\Auth;

/**
 * v8.41/W1 (ADR 0035 §D1) — the ONE core behind the three `explain` surfaces
 * (R44): `kb:search --explain`, `GET /api/admin/kb/retrieval/explain`, and the
 * `KbRetrievalExplainTool` MCP tool. Each surface only adapts input → this
 * service → output; the tenant is whatever {@see \App\Support\TenantContext}
 * holds (set by the middleware / MCP resolver / `--tenant`).
 *
 * Runs the SAME `searchWithContext()` path the chat uses, so the trace
 * describes the real ranking, and returns numbers + short machine tokens only
 * — a 160-char chunk preview for orientation, never the full chunk text or
 * any provider payload (SEC-LLM-001 gate 8).
 */
final class RetrievalExplainService
{
    private const PREVIEW_CHARS = 160;

    public function __construct(private readonly KbSearchService $search) {}

    /** Whether the HTTP + MCP surfaces are enabled (R43). The CLI ignores it. */
    public function enabled(): bool
    {
        return (bool) config('kb.retrieval_explain.enabled', false);
    }

    public function maxLimit(): int
    {
        return max(1, (int) config('kb.retrieval_explain.max_limit', 20));
    }

    /**
     * @return array{
     *   query: string,
     *   project_key: string|null,
     *   limit: int,
     *   results: list<array<string,mixed>>,
     *   meta: array<string,mixed>
     * }
     */
    public function explain(
        string $query,
        ?string $projectKey = null,
        int $limit = 8,
        ?RetrievalFilters $filters = null,
        ?string $intent = null,
        bool $investigation = false,
        ?float $minScore = null,
        bool $lineNumbers = false,
    ): array {
        $limit = max(1, min($limit, $this->maxLimit()));
        $effective = ($filters ?? RetrievalFilters::forLegacyProject($projectKey))->withExplain()->withIntent($intent);

        // The chat runs the recursive investigation (KbInvestigationService)
        // when KB_RECURSIVE_RETRIEVAL_ENABLED is on: explaining the DIRECT
        // retrieval alone would describe a path the user's answer no longer
        // takes. `investigation: true` traces the real path — the model-written
        // round queries, each round's outcome and the sources finally selected.
        $investigationTrace = null;
        if ($investigation && (bool) config('kb.investigation.enabled', true)) {
            $actor = Auth::user();
            $outcome = app(KbInvestigationService::class)->investigate(
                $query,
                $projectKey,
                $effective,
                actor: $actor instanceof User ? $actor : null,
            );
            $result = $outcome->search;
            $investigationTrace = $outcome->trace();
        } else {
            $result = $this->search->searchWithContext(
                query: $query,
                projectKey: $projectKey,
                limit: $limit,
                minSimilarity: (float) config('kb.default_min_similarity', 0.30),
                filters: $effective,
            );
            if ($investigation) {
                $investigationTrace = ['status' => 'disabled', 'flag' => 'KB_RECURSIVE_RETRIEVAL_ENABLED'];
            }
        }

        return [
            'query' => $query,
            'project_key' => $projectKey,
            'limit' => $limit,
            'results' => ResultScoreFloor::apply($result->primary->take($limit)->values(), $minScore)
                ->map(fn (array $chunk): array => $this->shape($chunk, $lineNumbers))
                ->values()
                ->all(),
            'meta' => [
                'primary_count' => $result->primary->count(),
                'fusion_method' => $result->meta['search_strategy']['fusion_method'] ?? null,
                'hybrid_search' => (bool) config('kb.hybrid_search.enabled', false),
                'fts_syntax' => (string) config('kb.hybrid_search.query_syntax', 'plain'),
                'retrieval_ms' => $result->meta['retrieval_ms'] ?? null,
                // v8.43/W3 + v8.44/W4 — what the optional stages did (null = stage off).
                'cross_encoder' => $result->meta['cross_encoder'] ?? null,
                'query_expansion' => $result->meta['query_expansion'] ?? null,
                // Present only when the caller asked to trace the investigation path (R27 additive).
                'investigation' => $investigationTrace,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $chunk
     * @return array<string,mixed>
     */
    private function shape(array $chunk, bool $lineNumbers = false): array
    {
        $text = (string) ($chunk['chunk_text'] ?? '');

        return $this->withLines([
            'chunk_id' => $chunk['chunk_id'] ?? null,
            'document' => [
                'id' => data_get($chunk, 'document.id'),
                'ref' => data_get($chunk, 'document.ref'),
                'title' => data_get($chunk, 'document.title'),
                'source_path' => data_get($chunk, 'document.source_path'),
                'canonical_type' => data_get($chunk, 'document.canonical_type'),
                'canonical_status' => data_get($chunk, 'document.canonical_status'),
            ],
            'heading_path' => $chunk['heading_path'] ?? null,
            'preview' => mb_substr($text, 0, self::PREVIEW_CHARS),
            'vector_score' => round((float) ($chunk['vector_score'] ?? 0.0), 6),
            'rerank_score' => isset($chunk['rerank_score']) ? round((float) $chunk['rerank_score'], 6) : null,
            'explain' => $chunk['explain'] ?? null,
        ], $chunk, $lineNumbers);
    }

    /**
     * W6 (qmd parity — `--line-numbers`) — where the chunk sits in the document
     * view that `kb:show --from` / `KbReadDocumentTool from_line` read, so a
     * result can be opened at the exact lines. Additive key, only on request.
     *
     * @param  array<string,mixed>  $shaped
     * @param  array<string,mixed>  $chunk
     * @return array<string,mixed>
     */
    private function withLines(array $shaped, array $chunk, bool $lineNumbers): array
    {
        if (! $lineNumbers) {
            return $shaped;
        }
        $documentId = data_get($chunk, 'document.id');
        $document = is_numeric($documentId)
            ? KnowledgeDocument::query()->forTenant(app(TenantContext::class)->current())->find((int) $documentId)
            : null;
        $shaped['lines'] = $document === null || ! is_numeric($chunk['chunk_id'] ?? null)
            ? null
            : app(DocumentLineReader::class)->chunkLines($document, (int) $chunk['chunk_id']);

        return $shaped;
    }
}
