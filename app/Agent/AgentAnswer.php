<?php

declare(strict_types=1);

namespace App\Agent;

use JsonSerializable;

final readonly class AgentAnswer implements JsonSerializable
{
    /**
     * @param list<array<string,mixed>> $citations
     * @param list<array<string,mixed>> $toolSources
     * @param list<string> $limitations
     * @param array<string,mixed> $grounding
     * @param array<string,mixed>|null $artifact
     */
    public function __construct(
        public string $answer,
        public string $locale,
        public string $completeness,
        public array $citations,
        public array $toolSources,
        public array $limitations = [],
        public ?array $artifact = null,
        public bool $requiresSelection = false,
        public array $grounding = [],
    ) {}

    /** Feedback passed to the next collection attempt; available on old runs as well. */
    public function sessionGap(): array
    {
        $coverage = $this->grounding['coverage_validation'] ?? [];

        return [
            'completeness' => $this->completeness,
            'limitations' => $this->limitations,
            'coverage_sufficient' => $coverage['sufficient'] ?? null,
            ...array_intersect_key($coverage, array_flip(['next_action', 'tasks'])),
        ];
    }

    public function needsSessionSources(): bool
    {
        if ($this->requiresSelection || $this->completeness === 'complete') {
            return false;
        }

        // Runs created before per-task coverage may only have draft limitations.
        $action = $this->grounding['coverage_validation']['next_action'] ?? null;

        return $action === null || in_array($action, ['read', 'search'], true);
    }

    public function hasUsefulInterimAnswer(): bool
    {
        if ($this->completeness !== 'partial' || ($this->grounding['claims'] ?? []) === []) {
            return false;
        }
        $coverage = $this->grounding['coverage_validation'] ?? [];
        if (($coverage['next_action'] ?? null) === 'read') {
            return false;
        }

        return ! isset($coverage['tasks']) || in_array('satisfied', array_column($coverage['tasks'], 'status'), true);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'answer' => $this->answer,
            'locale' => $this->locale,
            'completeness' => $this->completeness,
            'citations' => $this->citations,
            'tool_sources' => $this->toolSources,
            'limitations' => $this->limitations,
            'artifact' => $this->artifact,
            'requires_selection' => $this->requiresSelection,
            'grounding' => $this->grounding,
        ];
    }
}
