<?php

declare(strict_types=1);

namespace App\Services\Kb\Agent;

use RuntimeException;

/** W6 — a `#docid` prefix shared by several documents (callers map it to 409 with the candidates). */
final class AmbiguousDocumentReference extends RuntimeException
{
    /** @param  list<array<string,mixed>>  $candidates */
    public function __construct(public readonly string $reference, public readonly array $candidates)
    {
        parent::__construct('The reference matches more than one document; use a longer prefix.');
    }
}
