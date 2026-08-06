<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * Explicit input contract for delivering one generated dataset.
 */
final readonly class EmailDatasetSeedRequest
{
    /**
     * @param  list<string>  $mailboxKeys
     */
    public function __construct(
        public array $mailboxKeys,
        public string $datasetDirectory,
        public bool $dryRun = false,
        public bool $resume = false,
        public bool $purgeDataset = false,
        public bool $purgeAllSeeded = false,
        public bool $purgeOnly = false,
        public int $checkpointEvery = 100,
    ) {}
}
