<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion;

/**
 * v8.44/W4 (ADR 0035 §D5) — a validated typed expansion of one query.
 *
 *   lex   keyword variants    → routed to the full-text branch only
 *   vec   paraphrases         → routed to the vector branch only
 *   hyde  one hypothetical answer passage → vector branch only
 *
 * Immutable and CLOSED: the only way to obtain one is
 * {@see QueryExpansionParser::parse()}, which rejects (rather than repairs)
 * anything that does not fit.
 */
final readonly class QueryExpansion
{
    /**
     * @param  list<string>  $lex
     * @param  list<string>  $vec
     */
    public function __construct(
        public array $lex = [],
        public array $vec = [],
        public ?string $hyde = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lex === [] && $this->vec === [] && $this->hyde === null;
    }

    /** @return list<string> vec + hyde, the texts that must be embedded */
    public function embeddable(): array
    {
        return array_values(array_filter([...$this->vec, $this->hyde], static fn (?string $t): bool => $t !== null && $t !== ''));
    }

    /** @return array{lex: list<string>, vec: list<string>, hyde: string|null} */
    public function toArray(): array
    {
        return ['lex' => $this->lex, 'vec' => $this->vec, 'hyde' => $this->hyde];
    }
}
