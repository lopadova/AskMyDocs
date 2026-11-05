<?php

declare(strict_types=1);

namespace App\Services\Chat\Reasoning;

/** A failed branch is data for the coordinator, never an exception erasing its siblings. */
final readonly class ResearchFailure
{
    public function __construct(public string $reason) {}
}
