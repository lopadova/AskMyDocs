<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

final readonly class DatasetGenerationRequest
{
    /**
     * @param  list<string>  $companies
     * @param  list<string>  $mailboxes
     */
    public function __construct(
        public string $profile,
        public int $seed,
        public string $catalogVersion,
        public string $outputDirectory,
        public array $companies = [],
        public array $mailboxes = [],
        public bool $force = false,
        public bool $check = false,
    ) {}
}
