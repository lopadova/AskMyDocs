<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiManager;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatLog\ChatLogEntry;
use App\Services\ChatLog\ChatLogManager;
use App\Services\Kb\FewShotService;
use App\Services\Kb\KbSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get(['id', 'role', 'content', 'metadata', 'rating', 'created_at']);

        return response()->json($messages);
    }

    public function store(
        Request $request,
        Conversation $conversation,
        AiManager $ai,
        KbSearchService $search,
        ChatLogManager $chatLog,
        FewShotService $fewShot,
    ): JsonResponse {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:10000'],
        ]);

        $question = $validated['content'];
        $projectKey = $conversation->project_key;
        $userId = $request->user()->id;

        // 1. Save user message
        $conversation->messages()->create([
            'role' => 'user',
            'content' => $question,
        ]);

        $startTime = microtime(true);

        // 2. Load conversation history
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get(['role', 'content'])
            ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        // 3. RAG: search KB for context
        $chunks = $search->search(
            query: $question,
            projectKey: $projectKey,
            limit: config('kb.default_limit', 8),
            minSimilarity: config('kb.default_min_similarity', 0.30),
        );

        // 4. Get few-shot examples from positively-rated past answers
        $fewShotExamples = $fewShot->getExamples($userId, $projectKey);

        // 5. Build system prompt with RAG context + few-shot examples
        $systemPrompt = view('prompts.kb_rag', [
            'chunks' => $chunks,
            'projectKey' => $projectKey,
            'fewShotExamples' => $fewShotExamples,
        ])->render();

        // 6. Send full history to AI provider
        $aiResponse = $ai->chatWithHistory($systemPrompt, $history);

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // 7. Build citations
        $citations = $this->buildCitations($chunks);

        // 8. Save assistant message
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $aiResponse->content,
            'metadata' => [
                'provider' => $aiResponse->provider,
                'model' => $aiResponse->model,
                'prompt_tokens' => $aiResponse->promptTokens,
                'completion_tokens' => $aiResponse->completionTokens,
                'total_tokens' => $aiResponse->totalTokens,
                'chunks_count' => $chunks->count(),
                'latency_ms' => $latencyMs,
                'citations' => $citations,
                'few_shot_count' => count($fewShotExamples),
            ],
        ]);

        $conversation->touch();

        // 9. Log chat interaction (if enabled)
        $chatLog->log(new ChatLogEntry(
            sessionId: $request->header('X-Session-Id', (string) Str::uuid()),
            userId: $userId,
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
            extra: [
                'few_shot_count' => count($fewShotExamples),
                'citations_count' => count($citations),
            ],
        ));

        return response()->json([
            'id' => $assistantMessage->id,
            'role' => 'assistant',
            'content' => $aiResponse->content,
            'metadata' => $assistantMessage->metadata,
            'rating' => null,
            'created_at' => $assistantMessage->created_at,
        ]);
    }

    private function buildCitations(\Illuminate\Support\Collection $chunks): array
    {
        return $chunks
            ->groupBy('document.source_path')
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'document_id' => data_get($first, 'document.id'),
                    'title' => data_get($first, 'document.title', 'Untitled'),
                    'source_path' => data_get($first, 'document.source_path'),
                    'source_type' => data_get($first, 'document.source_type'),
                    'headings' => $group->pluck('heading_path')->filter()->unique()->values()->all(),
                    'chunks_used' => $group->count(),
                ];
            })
            ->values()
            ->all();
    }
}
