<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiManager;
use App\Http\Requests\Api\KbChatRequest;
use App\Services\ChatLog\ChatLogEntry;
use App\Services\ChatLog\ChatLogManager;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class KbChatController extends Controller
{
    public function __invoke(
        KbChatRequest $request,
        AiManager $ai,
        KbSearchService $search,
        ChatLogManager $chatLog,
    ): JsonResponse {
        $question = (string) $request->input('question');
        // Effective single-project key for the legacy meta payload + the
        // chat log row. When the new `filters.project_keys` payload is
        // used, the FIRST element is treated as the canonical tenant for
        // observability (chat-log row carries one project_key column);
        // the FULL list is still applied via the RetrievalFilters DTO.
        $projectKey = $request->effectiveProjectKey();
        $filters = $request->toFilters();

        $startTime = microtime(true);

        $result = $search->searchWithContext(
            query: $question,
            projectKey: $projectKey,
            limit: config('kb.default_limit', 8),
            minSimilarity: config('kb.default_min_similarity', 0.30),
            filters: $filters,
        );

        $systemPrompt = view('prompts.kb_rag', [
            'chunks' => $result->primary,
            'expanded' => $result->expanded,
            'rejected' => $result->rejected,
            'projectKey' => $projectKey,
        ])->render();

        $aiResponse = $ai->chat($systemPrompt, $question);

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        $citations = $this->buildCitations($result);
        $sources = $this->collectSources($result);

        $chatLog->log(new ChatLogEntry(
            sessionId: $request->header('X-Session-Id', (string) Str::uuid()),
            userId: $request->user()?->id,
            question: $question,
            answer: $aiResponse->content,
            projectKey: $projectKey,
            aiProvider: $aiResponse->provider,
            aiModel: $aiResponse->model,
            chunksCount: $result->totalChunks(),
            sources: $sources,
            promptTokens: $aiResponse->promptTokens,
            completionTokens: $aiResponse->completionTokens,
            totalTokens: $aiResponse->totalTokens,
            latencyMs: $latencyMs,
            clientIp: $request->ip(),
            userAgent: $request->userAgent(),
            extra: [
                'primary_count' => $result->primary->count(),
                'expanded_count' => $result->expanded->count(),
                'rejected_count' => $result->rejected->count(),
            ],
        ));

        return response()->json([
            'answer' => $aiResponse->content,
            'citations' => $citations,
            'meta' => [
                'provider' => $aiResponse->provider,
                'model' => $aiResponse->model,
                'chunks_used' => $result->totalChunks(),
                'primary_count' => $result->primary->count(),
                'expanded_count' => $result->expanded->count(),
                'rejected_count' => $result->rejected->count(),
                'latency_ms' => $latencyMs,
                // T2.2 — surface the user-selected filter count so the
                // FE composer can render "5 filters selected" without
                // an extra round-trip. Mirrors the meta key set by
                // KbSearchService::searchWithContext (T2.1).
                'filters_selected' => $result->meta['filters_selected'] ?? 0,
            ],
        ]);
    }

    /**
     * Build citations grouped by source document with an `origin` marker so
     * the UI can label primary / related / rejected differently.
     *
     * @return array<int, array{document_id: ?int, title: string, source_path: ?string, headings: list<string>, chunks_used: int, origin: string}>
     */
    private function buildCitations(SearchResult $result): array
    {
        $citations = [];
        $this->appendCitationsFor($result->primary, 'primary', $citations);
        $this->appendCitationsFor($result->expanded, 'related', $citations);
        $this->appendCitationsFor($result->rejected, 'rejected', $citations);
        return array_values($citations);
    }

    /**
     * @param  Collection<int, array>  $chunks
     * @param  array<string, array>  $citations
     */
    private function appendCitationsFor(Collection $chunks, string $origin, array &$citations): void
    {
        foreach ($chunks->groupBy('document.source_path') as $sourcePath => $group) {
            $key = $origin . ':' . $sourcePath;
            if (isset($citations[$key])) {
                continue;
            }
            $first = $group->first();
            $citations[$key] = [
                'document_id' => data_get($first, 'document.id'),
                'title' => data_get($first, 'document.title', 'Untitled'),
                'source_path' => data_get($first, 'document.source_path'),
                'headings' => $group->pluck('heading_path')->filter()->unique()->values()->all(),
                'chunks_used' => $group->count(),
                'origin' => $origin,
            ];
        }
    }

    /**
     * @return list<string>
     */
    private function collectSources(SearchResult $result): array
    {
        $all = $result->primary->concat($result->expanded)->concat($result->rejected);
        return $all->pluck('document.source_path')->filter()->unique()->values()->all();
    }
}
