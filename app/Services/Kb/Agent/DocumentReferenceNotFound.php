<?php

declare(strict_types=1);

namespace App\Services\Kb\Agent;

use RuntimeException;

/** W6 — a `#docid` reference that matches no readable document (callers map it to 404). */
final class DocumentReferenceNotFound extends RuntimeException
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct('No readable document matches this reference.');
    }
}
