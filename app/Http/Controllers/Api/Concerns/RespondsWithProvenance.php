<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Ai\StreamChunk;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\AnswerProvenance;
use App\Services\Chat\Reasoning\ConversationReasoning;
use App\Services\ChatLog\ChatLogEntry;
use App\Services\ChatLog\ChatLogManager;
use App\Services\Kb\Retrieval\RetrievalFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Same deterministic answer and metadata for ordinary and SSE conversation chat. */
trait RespondsWithProvenance
{
    private function provenanceResponse(Request $request, Conversation $conversation, Message $turn, ?RetrievalFilters $filters,
        ?string $language, ChatLogManager $log, float $startTime, bool $stream = false): mixed
    {
        $answer = app(AnswerProvenance::class)->explain($conversation, $turn, $request->user(),
            $language ?? $request->user()->locale ?? 'en', $filters);
        $metadata = ['provider' => 'server', 'model' => 'answer-provenance', 'locale' => $answer->locale,
            'citations' => $answer->citations, 'tool_sources' => $answer->toolSources,
            'grounding' => $answer->grounding, 'completeness' => $answer->completeness, 'limitations' => $answer->limitations,
            'tool_calls' => [], 'tool_calls_count' => 0, 'search_stats' => ['kb_searches' => 0, 'tool_calls' => 0],
            'latency_ms' => (int) ((microtime(true) - $startTime) * 1000), 'confidence' => null, 'refusal_reason' => null];
        $metadata += ['cost' => null, 'cost_currency' => null, 'streamed' => $stream];
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => $answer->answer,
            'metadata' => $metadata, 'confidence' => null, 'refusal_reason' => null]);
        // An attribution is not another verified business fact or a fresh source observation.
        app(ConversationReasoning::class)->finish($conversation, $turn, $message, []);
        $conversation->touch();
        $log->log(new ChatLogEntry(sessionId: $request->header('X-Session-Id', (string) Str::uuid()), userId: $request->user()->id,
            question: $turn->content, answer: $answer->answer, projectKey: $conversation->project_key,
            aiProvider: 'server', aiModel: 'answer-provenance', chunksCount: 0,
            sources: array_map(fn ($citation) => '#doc-'.$citation['document_id'], $answer->citations), promptTokens: null, completionTokens: null, totalTokens: null,
            latencyMs: $metadata['latency_ms'], clientIp: $request->ip(), userAgent: $request->userAgent(), extra: ['grounding' => $answer->grounding]));
        if (! $stream) {
            return response()->json(['id' => $message->id, 'role' => 'assistant', 'content' => $message->content,
                'metadata' => $message->metadata, 'rating' => null, 'confidence' => null, 'refusal_reason' => null, 'created_at' => $message->created_at]);
        }
        // Saved before emission: disconnects cannot leave an unpersisted attribution.
        return $this->streamingResponse($request, function () use ($message, $answer): void {
            $this->emit(StreamChunk::start());
            foreach ($answer->citations as $citation) {
                $this->emit(StreamChunk::sourceUrl('doc-'.$citation['document_id'], '#doc-'.$citation['document_id'], $citation['title'],
                    ['origin' => 'primary', 'source_type' => $citation['source_type'], 'headings' => [], 'chunks_used' => 0]));
            }
            $id = 'provenance-'.$message->id;
            $this->emit(StreamChunk::textStart($id));
            $this->emit(StreamChunk::textDelta($id, $message->content));
            $this->emit(StreamChunk::textEnd($id));
            $this->emit(StreamChunk::finish());
        });
    }
}
