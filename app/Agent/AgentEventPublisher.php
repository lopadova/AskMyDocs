<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\AgentRunEvent;
use Illuminate\Support\Facades\DB;

final readonly class AgentEventPublisher
{
    public function __construct(private AgentMessageCatalog $messages) {}

    /**
     * @param array<string,scalar|null> $messageParameters
     * @param array<string,mixed> $data
     */
    public function publish(
        AgentRun $run,
        string $type,
        ?string $messageKey = null,
        array $messageParameters = [],
        array $data = [],
        ?AgentProgress $progress = null,
        bool $canCancel = true,
    ): AgentRunEvent {
        $this->assertEventName($type);

        return DB::transaction(function () use (
            $run, $type, $messageKey, $messageParameters, $data, $progress, $canCancel,
        ): AgentRunEvent {
            /** @var AgentRun $locked */
            $locked = AgentRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            $sequence = $locked->last_sequence + 1;
            $copy = $messageKey === null
                ? ['locale' => $locked->locale, 'message_key' => null, 'message_params' => [], 'message' => null]
                : $this->messages->format($locked->locale, $messageKey, $messageParameters);

            $event = $locked->events()->create([
                'sequence' => $sequence,
                'type' => $type,
                'phase' => strtok($type, '.') ?: $type,
                'locale' => $locked->locale,
                'message_key' => $copy['message_key'],
                'message_params' => $copy['message_params'],
                'message' => $copy['message'],
                'payload_json' => [
                    'progress' => $progress?->jsonSerialize(),
                    'can_cancel' => $canCancel,
                    'data' => $data,
                ],
            ]);

            $locked->forceFill(['last_sequence' => $sequence])->save();
            $run->setAttribute('last_sequence', $sequence);

            return $event;
        }, 3);
    }

    /** @return array<string,mixed> */
    public function serialize(AgentRunEvent $event): array
    {
        $payload = is_array($event->payload_json) ? $event->payload_json : [];

        return [
            'run_id' => $event->run->run_id,
            'sequence' => $event->sequence,
            'type' => $event->type,
            'phase' => $event->phase,
            'locale' => $event->locale,
            'message_key' => $event->message_key,
            'message_params' => $event->message_params ?? [],
            'message' => $event->message,
            'progress' => $payload['progress'] ?? null,
            'can_cancel' => (bool) ($payload['can_cancel'] ?? false),
            'data' => is_array($payload['data'] ?? null) ? $payload['data'] : [],
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    private function assertEventName(string $type): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $type) !== 1) {
            throw new \InvalidArgumentException("Invalid agent event type [{$type}].");
        }
    }
}
