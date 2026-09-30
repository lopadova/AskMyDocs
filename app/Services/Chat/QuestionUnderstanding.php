<?php

declare(strict_types=1);

namespace App\Services\Chat;

final readonly class QuestionUnderstanding
{
    /** @param list<string> $kbQueries @param list<array{text:string,type:string}> $mentions */
    public function __construct(
        public ?string $language,
        public string $intent,
        public array $kbQueries,
        public array $mentions,
        public bool $referencesPreviousTurn,
        public bool $available = true,
        public array $focus = [],
        public array $subquestions = [],
        public array $resolvedReferences = [],
        public string $transition = 'new',
        public bool $needsClarification = false,
        public string $clarification = '',
        public ?string $failureReason = null,
        public ?QuestionAction $action = null,
    ) {}

    /** @return list<string> */
    public function mentionTexts(): array
    {
        return array_values(array_unique(array_column($this->mentions, 'text')));
    }

    public function asksForProvenance(): bool
    {
        // Do not shortcut a mixed request containing ordinary research tasks.
        return $this->available && ! $this->needsClarification && $this->referencesPreviousTurn
            && $this->effectiveAction() === QuestionAction::Provenance && count($this->subquestions) === 1
            && ($this->focus['aspect'] ?? '') === 'source_provenance'
            && ($this->subquestions[0]['aspect'] ?? '') === 'source_provenance';
    }

    public function targetIdentifiers(): array
    {
        return array_values(array_unique(array_merge($this->focus['identifiers'] ?? [], $this->resolvedReferences,
            ...array_map(fn ($sub) => $sub['identifiers'] ?? [], $this->subquestions))));
    }

    public function asksToReadSource(): bool
    {
        return $this->available && ! $this->needsClarification
            && $this->effectiveAction() === QuestionAction::ReadSource
            && count($this->subquestions) <= 1;
    }

    public function effectiveAction(): QuestionAction
    {
        if (! $this->available) {
            return QuestionAction::Research;
        }
        if ($this->needsClarification) {
            return QuestionAction::Clarify;
        }
        // Compatibility for persisted turns predating action. Only migrate typed
        // transitions; never infer a read/confirmation from historical prose.
        return $this->action ?? match ($this->transition) {
            'provenance' => QuestionAction::Provenance,
            'recap' => QuestionAction::Recap,
            default => QuestionAction::Research,
        };
    }

    public function toArray(): array
    {
        return ['language' => $this->language, 'intent' => $this->intent, 'action' => $this->effectiveAction()->value, 'kb_queries' => $this->kbQueries,
            'mentions' => $this->mentions, 'references_previous_turn' => $this->referencesPreviousTurn,
            'available' => $this->available, 'focus' => $this->focus, 'subquestions' => $this->subquestions,
            'resolved_references' => $this->resolvedReferences, 'transition' => $this->transition,
            'needs_clarification' => $this->needsClarification, 'clarification' => $this->clarification,
            'failure_reason' => $this->failureReason];
    }

    /** Each branch owns an objective and search seeds, never the combined parent intent. */
    public function forSubquestion(int $index): self
    {
        $sub = $this->subquestions[$index];
        $focus = array_intersect_key($sub, array_flip(['topic', 'identifiers', 'aspect', 'fields']));
        $question = $sub['question'] ?? trim(implode(' ', [$sub['topic'], implode(' ', $sub['identifiers']), $sub['aspect']]));
        $queries = $sub['kb_queries'] ?? [$question];
        return new self($this->language, $question, $queries,
            array_values(array_filter($this->mentions, fn ($mention) => in_array(is_array($mention) ? $mention['text'] : $mention, $sub['identifiers'], true))),
            $this->referencesPreviousTurn, $this->available, $focus, [$sub],
            array_values(array_intersect($this->resolvedReferences, $sub['identifiers'])), $this->transition,
            $this->needsClarification, $this->clarification, $this->failureReason, $this->effectiveAction());
    }

    public static function fromArray(array $data): self
    {
        return new self($data['language'] ?? null, $data['intent'] ?? '', $data['kb_queries'] ?? [],
            $data['mentions'] ?? [], $data['references_previous_turn'] ?? false, $data['available'] ?? false,
            $data['focus'] ?? [], $data['subquestions'] ?? [], $data['resolved_references'] ?? [],
            $data['transition'] ?? 'new', $data['needs_clarification'] ?? false, $data['clarification'] ?? '', $data['failure_reason'] ?? null,
            isset($data['action']) ? (is_string($data['action']) ? QuestionAction::tryFrom($data['action']) ?? QuestionAction::Research : QuestionAction::Research) : null);
    }
}
