<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use App\Services\Widget\WidgetPiiMasker;
use Illuminate\Support\Facades\DB;

/** Materializes terminal AgentRun results onto their channel's durable history. */
final class AgentResultProjector
{
    public function __construct(private readonly WidgetPiiMasker $masker) {}

    public function project(AgentRun $run, AgentAnswer $answer): void
    {
        if ($run->channel === 'widget') {
            $this->projectWidget($run, $answer);

            return;
        }
        if ($run->channel !== 'chat' || $run->conversation_id === null) {
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

    private function projectWidget(AgentRun $run, AgentAnswer $answer): void
    {
        if ($run->widget_session_id === null) {
            return;
        }

        DB::transaction(function () use ($run, $answer): void {
            $session = WidgetSession::query()
                ->forTenant($run->tenant_id)
                ->whereKey($run->widget_session_id)
                ->where('project_key', $run->project_key)
                ->when(
                    $run->widget_identity_id === null,
                    fn ($query) => $query->whereNull('widget_identity_id'),
                    fn ($query) => $query->where('widget_identity_id', $run->widget_identity_id),
                )
                ->lockForUpdate()
                ->first();
            if (! $session instanceof WidgetSession) {
                throw new \DomainException('agent_widget_session_scope_mismatch');
            }

            WidgetSessionStep::query()->firstOrCreate(
                ['agent_run_id' => $run->id],
                [
                    'tenant_id' => $run->tenant_id,
                    'widget_session_id' => $session->id,
                    'step_index' => (int) ($session->steps()->max('step_index') ?? -1) + 1,
                    'kind' => WidgetSessionStep::KIND_BOT_MESSAGE,
                    'args_json' => $this->masker->maskArray([
                        'content' => $answer->answer,
                        'citations' => $answer->citations,
                        'tool_sources' => $answer->toolSources,
                        'completeness' => $answer->completeness,
                        'limitations' => $answer->limitations,
                        'locale' => $answer->locale,
                    ]) ?? [],
                ],
            );
            $session->forceFill([
                'status' => WidgetSession::STATUS_ACTIVE,
                'blocked_reason' => null,
            ])->save();
        }, 3);
    }
}
