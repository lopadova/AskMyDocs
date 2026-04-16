<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiManager;
use App\Services\ChatLog\ChatLogEntry;
use App\Services\ChatLog\ChatLogManager;
use App\Services\Kb\KbSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class KbChatController extends Controller
{
    public function __invoke(
        Request $request,
        AiManager $ai,
        KbSearchService $search,
        ChatLogManager $chatLog,
    ): JsonResponse {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:10000'],
            'project_key' => ['nullable', 'string', 'max:120'],
        ]);

        $question = $validated['question'];
        $projectKey = $validated['project_key'] ?? null;

        $startTime = microtime(true);

        $chunks = $search->search(
            query: $question,
            projectKey: $projectKey,
            limit: config('kb.default_limit', 8),
            minSimilarity: config('kb.default_min_similarity', 0.30),
        );

        $systemPrompt = view('prompts.kb_rag', [
            'chunks' => $chunks,
            'projectKey' => $projectKey,
        ])->render();

        $aiResponse = $ai->chat($systemPrompt, $question);

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Build citations
        $citations = $chunks
            ->groupBy('document.source_path')
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'document_id' => data_get($first, 'document.id'),
                    'title' => data_get($first, 'document.title', 'Untitled'),
                    'source_path' => data_get($first, 'document.source_path'),
                    'headings' => $group->pluck('heading_path')->filter()->unique()->values()->all(),
                    'chunks_used' => $group->count(),
                ];
            })
            ->values()
            ->all();

        $chatLog->log(new ChatLogEntry(
            sessionId: $request->header('X-Session-Id', (string) Str::uuid()),
            userId: $request->user()?->id,
            question: $question,
            answer: $aiResponse->content,
            projectKey: $projectKey,
            aiProvider: $aiResponse->provider,
            aiModel: $aiResponse->model,
            chunksCount: $chunks->count(),
            sources: $chunks->pluck('document.source_path')->filter()->unique()->values()->all(),
            promptTokens: $aiResponse->promptTokens,
            completionTokens: $aiResponse->completionTokens,
            totalTokens: $aiResponse->totalTokens,
            latencyMs: $latencyMs,
            clientIp: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return response()->json([
            'answer' => $aiResponse->content,
            'citations' => $citations,
            'meta' => [
                'provider' => $aiResponse->provider,
                'model' => $aiResponse->model,
                'chunks_used' => $chunks->count(),
                'latency_ms' => $latencyMs,
            ],
        ]);
    }
}
