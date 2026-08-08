<?php

declare(strict_types=1);

namespace App\Agent\Budget;

final readonly class AgentBudgetDecision
{
    private function __construct(
        public string $outcome,
        public ?string $reason = null,
        public bool $autoExtended = false,
    ) {}

    public static function allow(bool $autoExtended = false): self
    {
        return new self('allow', null, $autoExtended);
    }

    public static function confirmation(string $reason): self
    {
        return new self('confirmation', $reason);
    }

    public static function stop(string $reason): self
    {
        return new self('stop', $reason);
    }

    public function allowed(): bool { return $this->outcome === 'allow'; }
    public function requiresConfirmation(): bool { return $this->outcome === 'confirmation'; }
}
