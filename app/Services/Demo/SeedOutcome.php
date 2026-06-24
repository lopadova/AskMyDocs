<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * Esito del seeding di una singola casella (una per azienda).
 */
final readonly class SeedOutcome
{
    public function __construct(
        public string $projectKey,
        public string $companyName,
        public string $email,
        public int $appended,
        public int $purged,
        public bool $dryRun,
    ) {}
}
