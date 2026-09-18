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
                    // "Livello di approfondimento" visibility: how many KB
                    // searches and MCP/API calls this run actually attempted
                    // over its WHOLE lifetime — not just the ones that ended
                    // up cited (tool_sources/citations above are answer-only,
                    // narrower). Surfaced as a chat-message badge.
                    'search_stats' => $this->searchStats($run),
                    'tool_calls' => array_map(static fn (array $source): array => [
                        'id' => (string) ($source['execution_id'] ?? ''),
                        'name' => (string) ($source['tool'] ?? ''),
                        'status' => 'ok',
                    ], $answer->toolSources),
                    'completeness' => $answer->completeness,
                    'limitations' => $answer->limitations,
                    'locale' => $answer->locale,
                    'grounding' => $answer->grounding,
                    'agent_artifact' => $answer->artifact,
                    'requires_selection' => $answer->requiresSelection,
                ],
                'created_at' => now(),
            ],
        );
        $conversation->touch();
    }

    /**
     * How many knowledge-base searches and how many MCP/API tool calls this
     * run actually attempted, over the whole run (every re-plan iteration,
     * not just what the final answer cites). Counts every EXECUTED attempt
     * (completed or failed), never one skipped before it ran (a dependency-
     * resolution failure). AgentLoop's always-on retrieval before the first
     * planning iteration is not an AgentToolExecution row — it is a
     * special-cased first step — so it is the +1 baseline: every run that
     * reaches here (this method only runs after AgentLoop::run() produced
     * an outcome) has always attempted exactly one.
     *
     * @return array{kb_searches: int, tool_calls: int}
     */
    private function searchStats(AgentRun $run): array
    {
        $attempted = $run->toolExecutions()
            ->whereIn('status', ['completed', 'failed'])
            ->get(['tool_kind']);

        return [
            // 'catalog' (list_knowledge_documents) counts as a document
            // search too — it's a lookup ABOUT the KB, same as 'knowledge',
            // just by title instead of by content. Omitting it here would
            // make a catalog-tool call invisible in the badge.
            'kb_searches' => 1 + $attempted->whereIn('tool_kind', ['knowledge', 'catalog'])->count(),
            'tool_calls' => $attempted->whereIn('tool_kind', ['mcp', 'api'])->count(),
        ];
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
                        'grounding' => $answer->grounding,
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
