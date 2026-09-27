<?php

declare(strict_types=1);

namespace App\Services\Kb\Investigation;

/** A validated, retrieval-safe interpretation of a user request. */
final readonly class KbInvestigationIntent
{
    /**
     * @param list<string> $entities
     * @param list<string> $constraints
     * @param list<string> $requiredFacts
     * @param list<string> $ambiguities
     * @param list<string> $queries
     */
    public function __construct(
        public string $objective,
        public array $entities,
        public array $constraints,
        public array $requiredFacts,
        public array $ambiguities,
        public array $queries,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'objective' => $this->objective,
            'entities' => $this->entities,
            'constraints' => $this->constraints,
            'required_facts' => $this->requiredFacts,
            'ambiguities' => $this->ambiguities,
            'queries' => $this->queries,
        ];
    }
}
