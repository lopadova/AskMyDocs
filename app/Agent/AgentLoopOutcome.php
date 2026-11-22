<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Evidence\AgentEvidenceEnvelope;
use App\Services\Chat\QuestionAction;
use App\Services\Chat\QuestionUnderstanding;

final readonly class AgentLoopOutcome
{
    /** @param list<array<string,mixed>> $completedActions */
    public function __construct(
        public string $decision,
        public AgentEvidenceEnvelope $evidence,
        public array $completedActions,
        public ?string $stopReason = null,
    ) {}

    public static function assessSession(AgentEvidenceEnvelope $evidence, ?QuestionUnderstanding $understanding, array $completed = []): self
    {
        if ($evidence->hasEvidence() && $understanding?->effectiveAction() !== QuestionAction::Recheck
            && ! $understanding?->requiresCurrentObservation()) {
            return new self('answer', $evidence, $completed, 'context_answer_attempt');
        }

        return new self('request_research', $evidence, $completed);
    }

    public function awaitingConfirmation(): bool
    {
        return $this->decision === 'awaiting_confirmation';
    }

    public function requiresInteraction(): bool
    {
        return $this->awaitingConfirmation() || in_array($this->decision, [
            'awaiting_mcp_confirmation',
            'awaiting_mcp_input',
            'waiting_mcp_task',
            'waiting_api_tool',
        ], true);
    }
}
