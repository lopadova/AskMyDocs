<?php

declare(strict_types=1);

namespace App\Decisions;

final class Decisions
{
    /** @var array<string,mixed> */
    private array $state = [];

    /** @var array<string,array<string,mixed>> */
    private array $questions = [];

    private function __construct(private readonly DecisionModel $model) {}

    public static function using(?string $model = null): self
    {
        $name = $model ?? (string) config('decisions.default', 'jev');
        $class = config("decisions.models.{$name}.driver");
        if (! is_string($class) || ! is_subclass_of($class, DecisionModel::class)) {
            throw new DecisionException("Unknown decision model [{$name}].");
        }

        return new self(app($class));
    }

    /** @param array<string,mixed> $state */
    public function withState(array $state): self
    {
        $copy = clone $this;
        $copy->state = $state;

        return $copy;
    }

    /** @param array{true:string,false:string} $criteria */
    public function yesNo(string $key, string $instructions, array $criteria): self
    {
        $this->assertQuestion($key, $instructions);
        if (trim($criteria['true'] ?? '') === '' || trim($criteria['false'] ?? '') === '') {
            throw new DecisionException('Yes/no decisions need true and false criteria.');
        }
        $copy = clone $this;
        $copy->questions[$key] = ['type' => 'noul', 'instructions' => $instructions, 'criteria' => $criteria];

        return $copy;
    }

    /** @param array<string,string> $criteria */
    public function choice(string $key, string $instructions, array $criteria): self
    {
        $this->assertQuestion($key, $instructions);
        if (count($criteria) < 2 || count($criteria) > 20 || array_filter($criteria, fn ($v, $k) =>
            ! is_string($k) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $k) !== 1
            || ! is_string($v) || trim($v) === '' || strlen($v) > 1000, ARRAY_FILTER_USE_BOTH) !== []) {
            throw new DecisionException('Choice needs 2–20 named, non-empty criteria.');
        }
        $copy = clone $this;
        $copy->questions[$key] = ['type' => 'choice', 'instructions' => $instructions, 'criteria' => $criteria];

        return $copy;
    }

    /** @param list<string> $criteria */
    public function score(string $key, string $instructions, array $criteria): self
    {
        $this->assertQuestion($key, $instructions);
        if (! array_is_list($criteria) || count($criteria) < 2 || count($criteria) > 20
            || array_filter($criteria, fn ($v) => ! is_string($v) || trim($v) === '') !== []) {
            throw new DecisionException('Score needs 2–20 ordered, non-empty criteria.');
        }
        $copy = clone $this;
        $copy->questions[$key] = ['type' => 'score', 'instructions' => $instructions, 'criteria' => $criteria];

        return $copy;
    }

    public function relevance(string $key = 'relevant'): self
    {
        return $this->yesNo($key, 'Does the candidate directly address the user question?', [
            'true' => 'The candidate concerns the same entity and requested topic.',
            'false' => 'The candidate concerns a different entity or topic.',
        ]);
    }

    public function sufficiency(string $key = 'sufficient'): self
    {
        return $this->yesNo($key, 'Do the supplied facts support an answer without guessing?', [
            'true' => 'The requested facts are explicitly present in the supplied evidence.',
            'false' => 'The requested facts are absent, incomplete, or contradictory.',
        ]);
    }

    public function conflicts(string $key = 'conflicts'): self
    {
        return $this->yesNo($key, 'Do the supplied sources make incompatible factual claims?', [
            'true' => 'Sources disagree on a fact needed for the answer.',
            'false' => 'No relevant factual disagreement is present.',
        ]);
    }

    public function decide(): DecisionResult
    {
        if ($this->questions === [] || $this->state === []) {
            throw new DecisionException('Decision needs state and at least one question.');
        }
        if (count($this->questions) > 10) {
            throw new DecisionException('At most ten questions are allowed per decision.');
        }
        $state = DecisionState::fromArray($this->state);
        if ($state->exceedsLimit()) {
            throw new DecisionException('Decision state exceeds the configured limit.');
        }
        if (strlen(json_encode($this->questions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 12000) {
            throw new DecisionException('Decision questions exceed the request limit.');
        }

        return $this->model->decide($state->data, $this->questions);
    }

    private function assertQuestion(string $key, string $instructions): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1 || trim($instructions) === '' || strlen($instructions) > 1000) {
            throw new DecisionException('Invalid decision question key or instructions.');
        }
        if (isset($this->questions[$key])) {
            throw new DecisionException("Duplicate decision question [{$key}].");
        }
    }
}
