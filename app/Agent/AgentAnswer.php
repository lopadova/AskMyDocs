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
     */
    public function __construct(
        public string $answer,
        public string $locale,
        public string $completeness,
        public array $citations,
        public array $toolSources,
        public array $limitations = [],
    ) {}

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
        ];
    }
}
