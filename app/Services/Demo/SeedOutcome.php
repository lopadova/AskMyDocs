<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * Esito del seeding di una singola casella di test, identificata da `mailboxKey`
 * (ogni azienda ne ha 2: `<project>-1` / `<project>-2`).
 */
final readonly class SeedOutcome
{
    public function __construct(
        public string $mailboxKey,
        public string $projectKey,
        public string $companyName,
        public string $email,
        public int $appended,
        public int $purged,
        public bool $dryRun,
        public int $alreadyPresent = 0,
        public int $expected = 0,
        public int $resumed = 0,
        public ?string $datasetVersion = null,
    ) {}
}
