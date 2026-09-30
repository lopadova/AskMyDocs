<?php

declare(strict_types=1);

namespace App\Decisions;

use JsonSerializable;

final readonly class DecisionResult implements JsonSerializable
{
    /** @param array<string,array<string,mixed>> $answers @param array<string,mixed> $usage */
    public function __construct(
        public string $id,
        public string $model,
        public array $answers,
        public array $usage,
        public float $latencyMs,
    ) {}

    /** A confident no is false; an inconclusive answer is never silently false. */
    public function toBool(string $question, float $threshold): bool
    {
        if ($threshold <= 0.5 || $threshold > 1.0) {
            throw new DecisionException('Decision threshold must be greater than 0.5 and at most 1.');
        }
        $answer = $this->answers[$question] ?? null;
        if (! is_array($answer) || ($answer['type'] ?? null) !== 'noul') {
            throw new DecisionException("Question [{$question}] is not a yes/no decision.");
        }
        $probability = $answer['probability_true'];
        if ($probability >= $threshold) {
            return true;
        }
        if ($probability <= 1 - $threshold) {
            return false;
        }

        throw new DecisionException("Question [{$question}] is inconclusive.");
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'model' => $this->model, 'answers' => $this->answers,
            'usage' => $this->usage, 'latency_ms' => $this->latencyMs];
    }
}
