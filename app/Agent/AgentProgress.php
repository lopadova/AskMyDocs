<?php

declare(strict_types=1);

namespace App\Agent;

use JsonSerializable;

final readonly class AgentProgress implements JsonSerializable
{
    public function __construct(
        public int $logicalCompleted = 0,
        public int $logicalMinimum = 0,
        public int $logicalLikely = 0,
        public int $logicalMaximum = 0,
        public int $physicalCompleted = 0,
        public int $physicalMinimum = 0,
        public int $physicalLikely = 0,
        public int $physicalMaximum = 0,
        public ?int $etaMs = null,
    ) {
        foreach ($this->values() as $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException('Agent progress values cannot be negative.');
            }
        }
        if ($logicalMinimum > $logicalLikely || $logicalLikely > $logicalMaximum
            || $physicalMinimum > $physicalLikely || $physicalLikely > $physicalMaximum) {
            throw new \InvalidArgumentException('Agent progress estimates must satisfy minimum <= likely <= maximum.');
        }
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'logical' => [
                'completed' => $this->logicalCompleted,
                'estimated' => [
                    'min' => $this->logicalMinimum,
                    'likely' => $this->logicalLikely,
                    'max' => $this->logicalMaximum,
                ],
            ],
            'physical' => [
                'completed' => $this->physicalCompleted,
                'estimated' => [
                    'min' => $this->physicalMinimum,
                    'likely' => $this->physicalLikely,
                    'max' => $this->physicalMaximum,
                ],
            ],
            'eta_ms' => $this->etaMs,
        ];
    }

    /** @return list<int> */
    private function values(): array
    {
        return [
            $this->logicalCompleted, $this->logicalMinimum, $this->logicalLikely, $this->logicalMaximum,
            $this->physicalCompleted, $this->physicalMinimum, $this->physicalLikely, $this->physicalMaximum,
            $this->etaMs ?? 0,
        ];
    }
}
