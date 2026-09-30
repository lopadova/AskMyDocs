<?php

declare(strict_types=1);

namespace App\Realtime;

use AgentsFullDuplex\RealtimeAgent\Contracts\ToolHandlerContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use App\Agent\AgentChatTurnStarter;
use App\Agent\AgentConversationBusy;
use App\Agent\AgentExecutionContextFactory;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ChatInputRedactor;

final readonly class HandleRealtimeChatTurn implements ToolHandlerContract
{
    public function __construct(
        private RealtimeSessionAccess $access,
        private AgentExecutionContextFactory $contexts,
        private AgentChatTurnStarter $turns,
        private ChatInputRedactor $redactor,
    ) {}

    /** @return array<string,mixed> */
    public function handle(AgentSession $session, ToolCall $call): array
    {
        $user = $session->owner;
        if (! $user instanceof User) {
            throw new \DomainException('realtime_owner_invalid');
        }

        $link = $this->access->linkFor($user, $session);
        if ($link === null || $link->conversation_id === null) {
            throw new \DomainException('realtime_session_scope_mismatch');
        }

        $question = $this->redactor->redact(trim((string) ($call->arguments['question'] ?? '')));
        if ($question === '' || mb_strlen($question) > 10_000) {
            throw new \InvalidArgumentException('realtime_question_invalid');
        }

        $conversation = Conversation::query()
            ->forTenant($link->tenant_id)
            ->whereKey($link->conversation_id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        $context = $this->contexts->forUser($user, $conversation->project_key);
        $input = [
            'question' => $question,
            'filters' => is_array($link->filters) ? $link->filters : [],
            'realtime_session_id' => $session->id,
            'realtime_call_id' => $call->id,
        ];
        if (is_array($link->live_sources)) {
            $input['live_sources'] = $link->live_sources;
        }

        try {
            $turn = $this->turns->start(
                $context,
                $conversation,
                $user,
                $question,
                $input,
                [
                    'realtime_agent_session_id' => $session->id,
                    'interaction_mode' => 'voice',
                ],
                inline: true,
            );
        } catch (AgentConversationBusy $exception) {
            // No user message/run is created, and the first request keeps running.
            return $exception->voiceResponse();
        }
        $run = $turn->run->refresh();
        $payload = [
            'run' => app(RealtimeChatRunPresenter::class)->present($run),
        ];

        if (in_array($run->status, [AgentRun::STATUS_COMPLETED, AgentRun::STATUS_PARTIAL], true)) {
            $payload['response'] = data_get($run->result_json, 'response', []);
        } elseif (in_array($run->status, [
            AgentRun::STATUS_AWAITING_CONFIRMATION,
            AgentRun::STATUS_AWAITING_MCP_CONFIRMATION,
            AgentRun::STATUS_AWAITING_MCP_INPUT,
            AgentRun::STATUS_WAITING_MCP_TASK,
        ], true)) {
            $payload['handoff'] = [
                'required' => true,
                'status' => $run->status,
                'interaction' => data_get($run->result_json, 'pending_mcp_interaction'),
            ];
        } else {
            $payload['error'] = [
                'code' => $run->error_code ?? 'agent_run_failed',
                'message' => 'The AskMyDocs pipeline could not complete this turn.',
            ];
        }

        return $payload;
    }
}
