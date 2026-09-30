<?php

declare(strict_types=1);

namespace App\Agent;

use App\Contracts\AgentRunHandler;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Creates the canonical user message and its durable AgentRun exactly once. */
final readonly class AgentChatTurnStarter
{
    public function __construct(
        private AgentRunDispatcher $runs,
        private AgentRunHandler $handler,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>|null  $messageMetadata
     */
    public function start(
        AgentExecutionContext $context,
        Conversation $conversation,
        User $user,
        string $displayContent,
        array $input,
        ?array $messageMetadata = null,
        bool $inline = false,
    ): AgentChatTurn {
        if (trim((string) ($input['question'] ?? '')) === '') {
            throw new \InvalidArgumentException('A chat turn requires a question.');
        }

        /** @var AgentChatTurn $turn */
        $turn = DB::transaction(function () use (
            $context,
            $conversation,
            $user,
            $displayContent,
            $input,
            $messageMetadata,
        ): AgentChatTurn {
            // Serialize creation, not the long research itself. Voice and text
            // share this fence; internal research branches are not new turns.
            Conversation::query()->forTenant($context->tenantId)->whereKey($conversation->id)
                ->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $busy = AgentRun::query()->forTenant($context->tenantId)
                ->where('conversation_id', $conversation->id)
                ->whereNull('input_json->research_parent_id')
                ->whereNotIn('status', [AgentRun::STATUS_COMPLETED, AgentRun::STATUS_PARTIAL, AgentRun::STATUS_FAILED, AgentRun::STATUS_CANCELLED])
                ->exists();
            if ($busy) {
                throw new AgentConversationBusy($context->locale);
            }
            $message = $conversation->messages()->create([
                'role' => 'user',
                'content' => $displayContent,
                'metadata' => $messageMetadata,
            ]);
            $run = $this->runs->create(
                $context,
                [...$input, 'user_message_id' => $message->id],
                ['user_id' => $user->id, 'conversation_id' => $conversation->id],
            );
            $message->forceFill(['metadata' => array_merge(
                is_array($message->metadata) ? $message->metadata : [],
                ['agent_run_id' => $run->run_id],
            )])->save();

            return new AgentChatTurn($message, $run);
        }, 3);

        if ($inline) {
            $this->handler->handle($turn->run);
        } else {
            $this->runs->enqueue($turn->run);
        }

        return new AgentChatTurn($turn->message->refresh(), $turn->run->refresh());
    }
}
