<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class EmailDatasetGenerator
{
    public function __construct(
        private CatalogLoader $catalogs,
        private ExactAllocator $allocator,
        private GoldCategoryClassifier $goldClassifier,
        private EmailRecordValidator $recordValidator,
        private DatasetPublisher $publisher,
        private EmailDatasetQualityValidator $qualityValidator,
        private ?Closure $snapshotFingerprintResolver = null,
    ) {}

    public function generate(DatasetGenerationRequest $request): DatasetGenerationResult
    {
        if ($request->seed < 0) {
            throw new InvalidArgumentException('Dataset seed must be a non-negative integer.');
        }

        $discoveredInputs = $this->loadGenerationInputs($request);
        $companies = $discoveredInputs['companies'];
        $mailboxes = $discoveredInputs['mailboxes'];
        $snapshotFingerprint = $this->snapshotFingerprint(
            $discoveredInputs['profile']->key,
            $request->catalogVersion,
            $companies,
            $mailboxes,
        );

        // The discovery reads above determine the selected keys only. Reload
        // every value-bearing input after the fingerprint is committed, then
        // generate exclusively from these copies. This closes the A→B window
        // where old in-memory templates could otherwise be published under a
        // fingerprint calculated from newer files.
        $generationInputs = $this->loadGenerationInputs($request);
        if ($generationInputs['profile']->key !== $discoveredInputs['profile']->key
            || $generationInputs['companies'] !== $companies
            || $generationInputs['mailboxes'] !== $mailboxes) {
            throw new RuntimeException(
                'Email dataset input selection changed while its snapshot was being captured.',
            );
        }
        $profile = $generationInputs['profile'];
        $loadedCompanies = $generationInputs['loaded_companies'];

        $datasetVersion = DatasetVersion::make(
            profile: $profile->key,
            seed: $request->seed,
            catalogVersion: $request->catalogVersion,
            companies: $companies,
            mailboxes: $mailboxes,
            subset: $request->companies !== [] || $request->mailboxes !== [],
            snapshotFingerprint: $snapshotFingerprint,
        );
        $destination = $this->publisher->destination($request->outputDirectory, $datasetVersion);
        $this->publisher->assertCanGenerate($destination, $request->force, $request->check);
        $temporary = $this->publisher->createTemporaryDirectory($request->outputDirectory, $datasetVersion);
        $writer = new JsonlDatasetWriter($temporary, $this->recordValidator);
        $random = new DeterministicRandom($request->seed);
        $factory = new EmailRecordFactory($random);
        $goldSources = [];
        $expectedRecords = 0;

        try {
            foreach ($loadedCompanies as $companyKey => $company) {
                foreach ($company['mailboxes'] as $mailboxKey => $mailbox) {
                    if (! in_array($mailboxKey, $mailboxes, true)) {
                        continue;
                    }

                    $gold = $profile->includeGold ? $this->catalogs->loadGold($mailboxKey) : [];
                    $goldCount = count($gold);
                    $target = $profile->targetForMailbox($goldCount);
                    $expectedRecords += $target;
                    $categoryTargets = $this->allocator->allocate(
                        $target,
                        $mailbox['category_matrix_large'],
                    );
                    $remaining = $categoryTargets;

                    $goldPath = $this->catalogs->goldPath($mailboxKey);
                    $goldChecksum = hash_file('sha256', $goldPath);
                    if ($goldChecksum === false) {
                        throw new RuntimeException("Unable to checksum gold fixture {$goldPath}.");
                    }
                    $goldSources[$mailboxKey] = [
                        'path' => 'database/seeders/emails/'.$mailboxKey.'.json',
                        'records' => $goldCount,
                        'sha256' => $goldChecksum,
                    ];

                    foreach ($gold as $goldIndex => $raw) {
                        if (! is_array($raw)) {
                            throw new RuntimeException("Gold fixture {$mailboxKey} contains a non-object record.");
                        }

                        $scenarioKey = $this->goldClassifier->classify(
                            $raw,
                            $company['scenarios'],
                            $remaining,
                        );
                        $remaining[$scenarioKey]--;
                        $writer->write($factory->fromGold(
                            raw: $raw,
                            company: $company,
                            mailboxKey: $mailboxKey,
                            scenarioKey: $scenarioKey,
                            datasetVersion: $datasetVersion,
                            index: $goldIndex,
                        ));
                    }

                    $generatedTotal = array_sum($remaining);
                    $targetThreadMessages = (int) round($target * $profile->threadRatio);
                    if ($targetThreadMessages > $generatedTotal) {
                        throw new RuntimeException(
                            "Profile {$profile->key} cannot reach its thread ratio in {$mailboxKey}: "
                            ."{$targetThreadMessages} thread records but only {$generatedTotal} generated slots."
                        );
                    }

                    $threadAllocation = $generatedTotal === 0
                        ? array_fill_keys(array_keys($remaining), 0)
                        : $this->allocator->allocate($targetThreadMessages, $remaining);
                    $threadAllocation = $this->removeSingletonThreadAllocations($threadAllocation, $remaining);

                    $sequence = $goldCount;
                    foreach ($remaining as $scenarioKey => $generatedForCategory) {
                        $threadMessages = $threadAllocation[$scenarioKey] ?? 0;
                        $threadSizes = $this->partitionThreadSizes(
                            $threadMessages,
                            $random,
                            "{$companyKey}|{$mailboxKey}|{$scenarioKey}",
                        );
                        $threadIndex = 0;

                        foreach ($threadSizes as $threadSize) {
                            $threadCoordinate = "thread-{$threadIndex}";
                            $threadId = substr(hash('sha256', implode('|', [
                                $request->catalogVersion,
                                (string) $request->seed,
                                $companyKey,
                                $mailboxKey,
                                $scenarioKey,
                                $threadCoordinate,
                            ])), 0, 32);
                            $sentAt = $factory->dateFor(
                                $profile,
                                "{$companyKey}|{$mailboxKey}|{$scenarioKey}|{$threadCoordinate}",
                            );
                            $references = [];
                            $parent = null;

                            for ($position = 0; $position < $threadSize; $position++) {
                                if ($position > 0) {
                                    $hours = $random->integer(
                                        "{$companyKey}|{$mailboxKey}|{$scenarioKey}|{$threadCoordinate}|reply-{$position}",
                                        1,
                                        36,
                                    );
                                    $sentAt = $sentAt->modify("+{$hours} hours");
                                }

                                $record = $factory->synthetic(
                                    company: $company,
                                    profile: $profile,
                                    mailboxKey: $mailboxKey,
                                    scenarioKey: $scenarioKey,
                                    datasetVersion: $datasetVersion,
                                    catalogVersion: $request->catalogVersion,
                                    seed: $request->seed,
                                    coordinate: "{$threadCoordinate}-message-{$position}",
                                    conversationScope: $threadCoordinate,
                                    messageType: 'thread',
                                    threadId: $threadId,
                                    inReplyTo: $parent,
                                    references: $references,
                                    sequence: ++$sequence,
                                    sentAt: $sentAt,
                                    threadPosition: $position,
                                    threadSize: $threadSize,
                                );
                                $writer->write($record);
                                $parent = (string) $record['message_id'];
                                $references[] = $parent;
                            }
                            $threadIndex++;
                        }

                        $standaloneCount = $generatedForCategory - $threadMessages;
                        for ($standalone = 0; $standalone < $standaloneCount; $standalone++) {
                            $messageType = $standalone % 7 < 5 ? 'transactional' : 'report';
                            $writer->write($factory->synthetic(
                                company: $company,
                                profile: $profile,
                                mailboxKey: $mailboxKey,
                                scenarioKey: $scenarioKey,
                                datasetVersion: $datasetVersion,
                                catalogVersion: $request->catalogVersion,
                                seed: $request->seed,
                                coordinate: "standalone-{$standalone}",
                                conversationScope: null,
                                messageType: $messageType,
                                threadId: null,
                                inReplyTo: null,
                                references: [],
                                sequence: ++$sequence,
                            ));
                        }
                    }

                    if ($sequence !== $target) {
                        throw new RuntimeException(
                            "Mailbox {$mailboxKey} generated {$sequence} records instead of {$target}."
                        );
                    }
                }
            }

            $manifest = $writer->finish([
                'dataset_version' => $datasetVersion,
                'profile' => $profile->key,
                'seed' => $request->seed,
                'catalog_version' => $request->catalogVersion,
                'generator_revision' => DatasetVersion::GENERATOR_REVISION,
                'snapshot_fingerprint' => $snapshotFingerprint,
                'companies' => $companies,
                'mailboxes' => $mailboxes,
                'timeline_start' => $profile->timelineStart->format('Y-m-d'),
                'timeline_end' => $profile->timelineEnd->format('Y-m-d'),
                'gold_sources' => $goldSources,
            ]);

            if ((int) $manifest['total_records'] !== $expectedRecords) {
                throw new RuntimeException(
                    "Dataset generated {$manifest['total_records']} records instead of {$expectedRecords}."
                );
            }

            if ($profile->key === 'large') {
                $ratio = (float) $manifest['statistics']['thread_ratio'];
                if ($ratio < 0.63 || $ratio > 0.67) {
                    throw new RuntimeException("Large profile thread ratio {$ratio} is outside the 63-67% gate.");
                }
            }

            $this->qualityValidator->validate($temporary);
            $finalSnapshotFingerprint = $this->snapshotFingerprint(
                $profile->key,
                $request->catalogVersion,
                $companies,
                $mailboxes,
            );
            if (! hash_equals($snapshotFingerprint, $finalSnapshotFingerprint)) {
                throw new RuntimeException(
                    'Email dataset inputs changed during generation; '
                    .'the temporary artifact was discarded.',
                );
            }

            if ($request->check) {
                $this->publisher->assertIdenticalAndDiscard($temporary, $destination);
            } else {
                $this->publisher->publish($temporary, $destination, $request->force);
            }

            return new DatasetGenerationResult(
                datasetVersion: $datasetVersion,
                directory: $destination,
                totalRecords: (int) $manifest['total_records'],
                shardCount: (int) $manifest['total_shards'],
                aggregateChecksum: (string) $manifest['aggregate_checksum'],
                checkOnly: $request->check,
            );
        } catch (Throwable $exception) {
            try {
                $writer->abort();
            } finally {
                $this->publisher->discard($temporary);
            }

            throw $exception;
        }
    }

    /**
     * @return array{
     *     profile: DatasetProfile,
     *     companies: list<string>,
     *     mailboxes: list<string>,
     *     loaded_companies: array<string, array<string, mixed>>
     * }
     */
    private function loadGenerationInputs(
        DatasetGenerationRequest $request,
    ): array {
        $profile = $this->catalogs->loadProfile($request->profile);
        $index = $this->catalogs->loadIndex($request->catalogVersion);
        $companies = $this->selectCompanies($index['companies'], $request->companies);
        $loadedCompanies = [];
        $allSelectedMailboxes = [];

        foreach ($companies as $companyKey) {
            $company = $this->catalogs->loadCompany(
                $request->catalogVersion,
                $companyKey,
            );
            $loadedCompanies[$companyKey] = $company;
            array_push(
                $allSelectedMailboxes,
                ...array_keys($company['mailboxes']),
            );
        }
        sort($allSelectedMailboxes, SORT_STRING);
        $mailboxes = $this->selectMailboxes(
            $allSelectedMailboxes,
            $request->mailboxes,
        );
        $companies = array_values(array_filter(
            $companies,
            static fn (string $companyKey): bool => array_intersect(
                array_keys($loadedCompanies[$companyKey]['mailboxes']),
                $mailboxes,
            ) !== [],
        ));
        $loadedCompanies = array_intersect_key(
            $loadedCompanies,
            array_flip($companies),
        );

        return [
            'profile' => $profile,
            'companies' => $companies,
            'mailboxes' => $mailboxes,
            'loaded_companies' => $loadedCompanies,
        ];
    }

    /**
     * @param  list<string>  $companies
     * @param  list<string>  $mailboxes
     */
    private function snapshotFingerprint(
        string $profile,
        string $catalogVersion,
        array $companies,
        array $mailboxes,
    ): string {
        $fingerprint = $this->snapshotFingerprintResolver === null
            ? $this->catalogs->snapshotFingerprint(
                $profile,
                $catalogVersion,
                $companies,
                $mailboxes,
            )
            : ($this->snapshotFingerprintResolver)(
                $profile,
                $catalogVersion,
                $companies,
                $mailboxes,
            );

        if (! is_string($fingerprint)
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new RuntimeException(
                'Dataset snapshot fingerprint resolver returned an invalid SHA-256 hash.',
            );
        }

        return $fingerprint;
    }

    /**
     * @param  list<string>  $available
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function selectCompanies(array $available, array $requested): array
    {
        return $this->select('company', $available, $requested);
    }

    /**
     * @param  list<string>  $available
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function selectMailboxes(array $available, array $requested): array
    {
        return $this->select('mailbox', $available, $requested);
    }

    /**
     * @param  list<string>  $available
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function select(string $label, array $available, array $requested): array
    {
        $selected = $requested === [] ? $available : array_values(array_unique(array_map('strval', $requested)));
        sort($selected, SORT_STRING);

        $unknown = array_values(array_diff($selected, $available));
        if ($unknown !== []) {
            throw new InvalidArgumentException("Unknown email dataset {$label}: ".implode(', ', $unknown));
        }

        if ($selected === []) {
            throw new InvalidArgumentException("At least one email dataset {$label} must be selected.");
        }

        return $selected;
    }

    /**
     * @param  array<string, int>  $allocation
     * @param  array<string, int>  $capacity
     * @return array<string, int>
     */
    private function removeSingletonThreadAllocations(array $allocation, array $capacity): array
    {
        $deficit = 0;
        foreach ($allocation as $scenarioKey => $count) {
            if ($count === 1) {
                $allocation[$scenarioKey] = 0;
                $deficit++;
            }
        }

        while ($deficit > 0) {
            $candidates = array_keys(array_filter(
                $allocation,
                static fn (int $count, string $scenarioKey): bool => $count >= 2 && $count < $capacity[$scenarioKey],
                ARRAY_FILTER_USE_BOTH,
            ));
            if ($candidates === []) {
                throw new RuntimeException('Unable to eliminate singleton thread allocation without changing totals.');
            }

            usort($candidates, static function (string $left, string $right) use ($allocation, $capacity): int {
                $leftRoom = $capacity[$left] - $allocation[$left];
                $rightRoom = $capacity[$right] - $allocation[$right];
                $byRoom = $rightRoom <=> $leftRoom;

                return $byRoom !== 0 ? $byRoom : strcmp($left, $right);
            });
            $allocation[$candidates[0]]++;
            $deficit--;
        }

        ksort($allocation, SORT_STRING);

        return $allocation;
    }

    /**
     * @return list<int>
     */
    private function partitionThreadSizes(
        int $messages,
        DeterministicRandom $random,
        string $scope,
    ): array {
        if ($messages === 0) {
            return [];
        }
        if ($messages < 2) {
            throw new RuntimeException('A real thread must contain at least two messages.');
        }

        $remaining = $messages;
        $sizes = [];
        $index = 0;
        while ($remaining > 0) {
            $candidates = array_values(array_filter(
                [2, 2, 3, 3, 4, 5, 8],
                static fn (int $size): bool => $size <= $remaining
                    && ($remaining - $size === 0 || $remaining - $size >= 2),
            ));
            if ($candidates === []) {
                throw new RuntimeException("Unable to partition {$messages} messages into valid threads.");
            }

            $size = (int) $random->pick($scope."|thread-size-{$index}", $candidates);
            $sizes[] = $size;
            $remaining -= $size;
            $index++;
        }

        return $sizes;
    }
}
