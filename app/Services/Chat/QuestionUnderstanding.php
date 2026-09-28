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
    ) {}

    /** @return list<string> */
    public function mentionTexts(): array
    {
        return array_values(array_unique(array_column($this->mentions, 'text')));
    }
}
