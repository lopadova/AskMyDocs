<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Validation\ValidationException;

/** Durable run discovery and monotonically increasing read receipts. */
final class ConversationActivityService
{
    /** @return list<array<string, mixed>> */
    public function list(int $userId, string $tenantId): array
    {
        return Conversation::query()->forTenant($tenantId)->where('user_id', $userId)
            ->with([
                'latestAgentRun:id,conversation_id,run_id,channel,status,locale,budget_json,input_json',
                'latestAssistantMessage' => fn ($query) => $query->select('messages.id', 'messages.conversation_id', 'messages.created_at')
                    ->addSelect(['run_completed_at' => AgentRun::query()->forTenant($tenantId)->select('completed_at')
                        ->whereColumn('agent_runs.id', 'messages.agent_run_id')])
                    ->withCasts(['run_completed_at' => 'datetime']),
                'pendingSessionRuns' => fn ($query) => $query->forTenant($tenantId)->where('user_id', $userId)
                    ->select('id', 'conversation_id', 'run_id', 'status', 'locale', 'budget_json'),
            ])
            ->get(['id', 'last_read_message_id'])
            ->map(function (Conversation $conversation): array {
                $reply = $conversation->latestAssistantMessage;
                $pending = $conversation->pendingSessionRuns;
                $run = $pending->first() ?? $conversation->latestAgentRun;

                return [
                    'conversation_id' => $conversation->id,
                    'last_read_message_id' => (int) $conversation->last_read_message_id,
                    'latest_reply' => $reply === null ? null : [
                        'message_id' => $reply->id,
                        'created_at' => ($reply->run_completed_at ?? $reply->created_at)?->toIso8601String(),
                    ],
                    // No prompts, tool payloads or transcripts in the sidebar snapshot.
                    'run' => $run === null ? null : $this->reference($run, $pending->isNotEmpty() || $run->usesConcurrentSession()),
                    ...$pending->isEmpty() ? [] : ['runs' => $pending->map(fn (AgentRun $item) => $this->reference($item, true))->all()],
                ];
            })->all();
    }

    private function reference(AgentRun $run, bool $concurrent): array
    {
        return [
            'run_id' => $run->run_id,
            'status' => $run->status,
            'locale' => $run->locale,
            'events_url' => '/agent-runs/'.$run->run_id.'/events',
            'cancel_url' => '/agent-runs/'.$run->run_id.'/cancel',
            'continue_url' => '/agent-runs/'.$run->run_id.'/continue',
            ...$concurrent ? ['concurrent_session' => true] : [],
            'confirmation' => $run->status !== AgentRun::STATUS_AWAITING_CONFIRMATION ? null : [
                'physicalExtension' => max(1, (int) data_get($run->budget_json, 'requested_extension.physical', 25)),
                'logicalExtension' => max(0, (int) data_get($run->budget_json, 'requested_extension.logical', 0)),
            ],
        ];
    }

    public function markRead(Conversation $conversation, int $messageId): int
    {
        // A receipt names the actual displayed answer. A delayed request must
        // never acknowledge another reply that arrived after the user left.
        $exists = Message::query()->forTenant($conversation->tenant_id)
            ->where('conversation_id', $conversation->id)->whereKey($messageId)
            ->where('role', 'assistant')->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['message_id' => 'The reply does not belong to this conversation.']);
        }

        Conversation::query()->forTenant($conversation->tenant_id)->whereKey($conversation->id)
            ->where('user_id', $conversation->user_id)->where('last_read_message_id', '<', $messageId)
            ->toBase()->update(['last_read_message_id' => $messageId]);

        return (int) $conversation->fresh()->last_read_message_id;
    }
}
