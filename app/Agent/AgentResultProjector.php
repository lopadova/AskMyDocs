<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;

/** Materializes terminal AgentRun results onto their channel's durable history. */
final class AgentResultProjector
{
    public function project(AgentRun $run, AgentAnswer $answer): void
    {
        if ($run->channel !== 'chat') {
            return;
        }
        if ($run->conversation_id === null) {
            return;
        }

        $conversation = Conversation::query()
            ->forTenant($run->tenant_id)
            ->whereKey($run->conversation_id)
            ->where('user_id', $run->user_id)
            ->first();
        if (! $conversation instanceof Conversation) {
            throw new \DomainException('agent_conversation_scope_mismatch');
        }

        Message::query()->firstOrCreate(
            ['agent_run_id' => $run->id],
            [
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $answer->answer,
                'confidence' => null,
                'refusal_reason' => $answer->completeness === 'insufficient' ? 'insufficient_data' : null,
                'metadata' => [
                    'agent_run_id' => $run->run_id,
                    'provider' => 'agent',
                    'model' => 'planner+synthesizer',
                    'citations' => $answer->citations,
                    'tool_sources' => $answer->toolSources,
                    'tool_calls_count' => count($answer->toolSources),
                    'tool_calls' => array_map(static fn (array $source): array => [
                        'id' => (string) ($source['execution_id'] ?? ''),
                        'name' => (string) ($source['tool'] ?? ''),
                        'status' => 'ok',
                    ], $answer->toolSources),
                    'completeness' => $answer->completeness,
                    'limitations' => $answer->limitations,
                    'locale' => $answer->locale,
                ],
                'created_at' => now(),
            ],
        );
        $conversation->touch();
    }
}
