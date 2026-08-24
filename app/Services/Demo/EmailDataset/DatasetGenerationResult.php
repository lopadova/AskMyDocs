<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

final readonly class DatasetGenerationResult
{
    public function __construct(
        public string $datasetVersion,
        public string $directory,
        public int $totalRecords,
        public int $shardCount,
        public string $aggregateChecksum,
        public bool $checkOnly = false,
    ) {}
}
