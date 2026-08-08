<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Evidence\AgentEvidenceEnvelope;

final readonly class AgentLoopOutcome
{
    /** @param list<array<string,mixed>> $completedActions */
    public function __construct(
        public string $decision,
        public AgentEvidenceEnvelope $evidence,
        public array $completedActions,
        public ?string $stopReason = null,
    ) {}

    public function awaitingConfirmation(): bool
    {
        return $this->decision === 'awaiting_confirmation';
    }
}
