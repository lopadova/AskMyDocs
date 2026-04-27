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

        // 3b. T3.3 — deterministic refusal short-circuit. Mirrors the
        // KbChatController behaviour for the conversation flow: if the
        // retrieved chunks don't pass the similarity floor, save a
        // refusal assistant message and return WITHOUT calling the LLM.
        $refusalThreshold = (float) config('kb.refusal.min_chunk_similarity', 0.45);
        $refusalMinChunks = (int) config('kb.refusal.min_chunks_required', 1);
        $grounded = $chunks->filter(
            fn ($c) => (float) ($c->vector_score ?? 0) >= $refusalThreshold
        );

        if ($grounded->count() < $refusalMinChunks) {
            return $this->refusalResponse(
                request: $request,
                chatLog: $chatLog,
                conversation: $conversation,
                question: $question,
                projectKey: $projectKey,
                userId: $userId,
                startTime: $startTime,
                reason: 'no_relevant_context',
            );
        }

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

    /**
     * T3.3 — refusal payload for the conversation flow.
     *
     * Persists an assistant message with role='assistant', the i18n
     * "no grounded answer" body, and metadata.refusal_reason +
     * metadata.confidence so the FE can render it as a RefusalNotice
     * (T3.7, deferred). Also writes the chat_log row so analytics see
     * the refusal turn. The DB-level columns `messages.refusal_reason`
     * + `messages.confidence` (T3.1) get populated alongside the
     * metadata blob — keeps SQL aggregation cheap without scanning JSON.
     */
    private function refusalResponse(
        Request $request,
        ChatLogManager $chatLog,
        Conversation $conversation,
        string $question,
        ?string $projectKey,
        int $userId,
        float $startTime,
        string $reason,
    ): JsonResponse {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
        $answer = (string) __('kb.no_grounded_answer');

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $answer,
            'confidence' => 0,
            'refusal_reason' => $reason,
            'metadata' => [
                'provider' => 'none',
                'model' => 'none',
                'chunks_count' => 0,
                'latency_ms' => $latencyMs,
                'citations' => [],
                'refusal_reason' => $reason,
                'confidence' => 0,
            ],
        ]);

        $conversation->touch();

        $chatLog->log(new ChatLogEntry(
            sessionId: $request->header('X-Session-Id', (string) Str::uuid()),
            userId: $userId,
            question: $question,
            answer: $answer,
            projectKey: $projectKey,
            aiProvider: 'none',
            aiModel: 'none',
            chunksCount: 0,
            sources: [],
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0,
            latencyMs: $latencyMs,
            clientIp: $request->ip(),
            userAgent: $request->userAgent(),
            extra: [
                'refusal_reason' => $reason,
                'confidence' => 0,
            ],
        ));

        return response()->json([
            'id' => $assistantMessage->id,
            'role' => 'assistant',
            'content' => $answer,
            'metadata' => $assistantMessage->metadata,
            'rating' => null,
            'confidence' => 0,
            'refusal_reason' => $reason,
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
