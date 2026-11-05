<?php

declare(strict_types=1);

namespace App\Mcp\Audit;

use App\Models\User;
use Padosoft\AskMyDocsConnectorMcp\Events\McpToolInvocationFinished;

final readonly class McpConnectorInvocationAuditListener
{
    public function __construct(private McpConnectorAuditRecorder $audit) {}

    public function handle(McpToolInvocationFinished $event): void
    {
        if (! $event->actor instanceof User) {
            return;
        }
        $outcome = $event->outcome;
        $status = $event->exception !== null
            ? 'error'
            : match ($outcome?->status) {
                'completed', 'task_accepted' => 'ok',
                'input_required' => 'input_required',
                'declined' => 'denied',
                default => 'error',
            };

        $this->audit->record(
            $event->actor,
            [
                'name' => (string) $event->tool->local_name,
                'provenance' => $event->provenance,
            ],
            $event->arguments,
            ['conversation_id' => $event->conversationId],
            $status,
            $outcome?->toArray(),
            $event->latencyMs,
            $event->exception,
        );
    }
}
