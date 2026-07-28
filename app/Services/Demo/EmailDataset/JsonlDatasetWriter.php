<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use JsonException;
use InvalidArgumentException;
use RuntimeException;

final class JsonlDatasetWriter
{
    /** @var array<string, array{handle: resource, touched_at: int}> */
    private array $handles = [];

    /** @var array<string, true> */
    private array $createdPaths = [];

    private int $handleClock = 0;

    /** @var array<string, array{path: string, company: string, mailbox: string, month: string, records: int}> */
    private array $shards = [];

    /** @var array<string, array{path: string, prefix: string, records: int}> */
    private array $fixtureIndexShards = [];

    /** @var array<string, int> */
    private array $companyCounts = [];

    /** @var array<string, int> */
    private array $mailboxCounts = [];

    /** @var array<string, int> */
    private array $monthCounts = [];

    /** @var array<string, int> */
    private array $categoryCounts = [];

    /** @var array<string, array<string, int>> */
    private array $mailboxCategoryCounts = [];

    /** @var array<string, int> */
    private array $truthCounts = [];

    /** @var array<string, int> */
    private array $messageTypeCounts = [];

    /** @var array<string, int> */
    private array $threadLengthCounts = [];

    private int $totalRecords = 0;

    private int $threadMessages = 0;

    /** @var array<string, int> */
    private array $mailboxThreadMessages = [];

    private ?string $activeThreadId = null;

    private int $activeThreadLength = 0;

    public function __construct(
        private readonly string $directory,
        private readonly EmailRecordValidator $validator,
        private readonly int $maxOpenHandles = 64,
    ) {
        if ($maxOpenHandles < 1) {
            throw new InvalidArgumentException('maxOpenHandles must be at least 1.');
        }

        $this->makeDirectory($directory);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function write(array $record): void
    {
        $this->validator->validate($record);

        $month = substr((string) $record['sent_at'], 0, 7);
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new RuntimeException('Cannot derive shard month from email sent_at.');
        }

        $relativePath = $record['company_key'].'/'.$record['mailbox_key'].'/'.$month.'.jsonl';
        $handle = $this->handleFor($relativePath);
        try {
            $line = json_encode(
                $record,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode generated email record.', 0, $exception);
        }

        $this->writeAll($handle, $line, $relativePath);
        $this->writeFixtureIndexEntry($record);

        if (! isset($this->shards[$relativePath])) {
            $this->shards[$relativePath] = [
                'path' => $relativePath,
                'company' => (string) $record['company_key'],
                'mailbox' => (string) $record['mailbox_key'],
                'month' => $month,
                'records' => 0,
            ];
        }
        $this->shards[$relativePath]['records']++;

        $this->increment($this->companyCounts, (string) $record['company_key']);
        $this->increment($this->mailboxCounts, (string) $record['mailbox_key']);
        $this->increment($this->monthCounts, $month);
        $this->increment($this->categoryCounts, (string) $record['scenario_type']);
        $mailboxKey = (string) $record['mailbox_key'];
        $scenarioKey = (string) $record['scenario_type'];
        $this->mailboxCategoryCounts[$mailboxKey] ??= [];
        $this->increment($this->mailboxCategoryCounts[$mailboxKey], $scenarioKey);
        $this->increment($this->truthCounts, (string) $record['truth_state']);
        $this->increment($this->messageTypeCounts, (string) $record['message_type']);
        $this->totalRecords++;

        $threadId = $record['thread_id'] ?? null;
        if (is_string($threadId)) {
            $this->threadMessages++;
            $this->increment($this->mailboxThreadMessages, $mailboxKey);
            if ($threadId === $this->activeThreadId) {
                $this->activeThreadLength++;
            } else {
                $this->finalizeActiveThread();
                $this->activeThreadId = $threadId;
                $this->activeThreadLength = 1;
            }
        } else {
            $this->finalizeActiveThread();
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function finish(array $metadata): array
    {
        $this->finalizeActiveThread();
        $this->closeHandles();

        ksort($this->shards, SORT_STRING);
        $manifestShards = [];
        $aggregateParts = [];
        foreach ($this->shards as $shard) {
            $path = $this->directory.'/'.$shard['path'];
            $checksum = hash_file('sha256', $path);
            if ($checksum === false) {
                throw new RuntimeException("Unable to checksum generated shard {$path}.");
            }

            $manifestShards[] = $shard + ['sha256' => $checksum];
            $aggregateParts[] = $shard['path'].' '.$checksum;
        }

        $aggregateChecksum = hash('sha256', implode("\n", $aggregateParts)."\n");
        ksort($this->fixtureIndexShards, SORT_STRING);
        $fixtureIndexShards = [];
        $fixtureIndexAggregateParts = [];
        $fixtureIndexRecords = 0;
        foreach ($this->fixtureIndexShards as $shard) {
            $path = $this->directory.'/'.$shard['path'];
            $checksum = hash_file('sha256', $path);
            if ($checksum === false) {
                throw new RuntimeException("Unable to checksum fixture metadata index shard {$path}.");
            }

            $fixtureIndexShards[] = $shard + ['sha256' => $checksum];
            $fixtureIndexAggregateParts[] = $shard['path'].' '.$checksum;
            $fixtureIndexRecords += $shard['records'];
        }
        if ($fixtureIndexRecords !== $this->totalRecords) {
            throw new RuntimeException(
                "Fixture metadata index contains {$fixtureIndexRecords} records; dataset contains {$this->totalRecords}.",
            );
        }
        $fixtureIndexAggregateChecksum = hash(
            'sha256',
            implode("\n", $fixtureIndexAggregateParts)."\n",
        );

        $manifest = [
            'schema_version' => '2.0',
            'dataset_version' => (string) $metadata['dataset_version'],
            'profile' => (string) $metadata['profile'],
            'seed' => (int) $metadata['seed'],
            'catalog_version' => (string) $metadata['catalog_version'],
            'generator_revision' => (string) $metadata['generator_revision'],
            'snapshot_fingerprint' => (string) $metadata['snapshot_fingerprint'],
            'selection' => [
                'companies' => array_values($metadata['companies']),
                'mailboxes' => array_values($metadata['mailboxes']),
            ],
            'timeline' => [
                'start' => (string) $metadata['timeline_start'],
                'end' => (string) $metadata['timeline_end'],
            ],
            'total_records' => $this->totalRecords,
            'total_shards' => count($manifestShards),
            'aggregate_checksum' => $aggregateChecksum,
            'contains_real_pii' => false,
            'generated_fixture' => true,
            'indexes' => [
                'fixtures' => [
                    'algorithm' => 'fixture-id-prefix',
                    'prefix_length' => FixtureMetadataIndex::PREFIX_LENGTH,
                    'total_records' => $fixtureIndexRecords,
                    'total_shards' => count($fixtureIndexShards),
                    'aggregate_checksum' => $fixtureIndexAggregateChecksum,
                    'shards' => $fixtureIndexShards,
                ],
            ],
            'statistics' => [
                'records_by_company' => $this->sorted($this->companyCounts),
                'records_by_mailbox' => $this->sorted($this->mailboxCounts),
                'records_by_month' => $this->sorted($this->monthCounts),
                'records_by_category' => $this->sorted($this->categoryCounts),
                'records_by_mailbox_category' => $this->sortRecursively($this->mailboxCategoryCounts),
                'records_by_truth_state' => $this->sorted($this->truthCounts),
                'records_by_message_type' => $this->sorted($this->messageTypeCounts),
                'thread_messages' => $this->threadMessages,
                'thread_ratio' => $this->totalRecords === 0
                    ? 0.0
                    : round($this->threadMessages / $this->totalRecords, 6),
                'thread_messages_by_mailbox' => $this->sorted($this->mailboxThreadMessages),
                'thread_length_distribution' => $this->sorted($this->threadLengthCounts),
            ],
            'gold_sources' => $metadata['gold_sources'],
            'validators' => [
                'record-v2-contract',
                'message-id-identity',
                'thread-reference-chain',
                'unique-generated-content',
                'reserved-email-domains',
                'canary-company-ownership',
                'exact-profile-counts',
                'shard-sha256',
                'fixture-metadata-index',
            ],
            'compatibility' => [
                'format' => 'jsonl',
                'reader' => 'App\\Services\\Demo\\EmailDataset\\EmailDatasetReader',
                'record_contract' => 'email-v2',
                'fixture_index_contract' => 'fixture-metadata-v2-content-committed',
            ],
            'shards' => $manifestShards,
        ];

        $manifest = $this->sortRecursively($manifest);
        try {
            $json = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode email dataset manifest.', 0, $exception);
        }

        $manifestPath = $this->directory.'/manifest.json';
        $handle = fopen($manifestPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Unable to create dataset manifest {$manifestPath}.");
        }

        try {
            $this->writeAll($handle, $json, 'manifest.json');
        } finally {
            if (! fclose($handle)) {
                throw new RuntimeException("Unable to close dataset manifest {$manifestPath}.");
            }
        }

        return $manifest;
    }

    public function abort(): void
    {
        $this->closeHandles('aborted dataset shards');
    }

    /**
     * @return resource
     */
    private function handleFor(string $relativePath)
    {
        if (isset($this->handles[$relativePath])) {
            $this->handles[$relativePath]['touched_at'] = ++$this->handleClock;

            return $this->handles[$relativePath]['handle'];
        }

        $path = $this->directory.'/'.$relativePath;
        $this->makeDirectory(dirname($path));
        if (count($this->handles) >= $this->maxOpenHandles) {
            $this->evictLeastRecentlyUsedHandle();
        }

        $mode = isset($this->createdPaths[$relativePath]) ? 'ab' : 'xb';
        $handle = fopen($path, $mode);
        if ($handle === false) {
            throw new RuntimeException("Unable to open generated shard {$path} in {$mode} mode.");
        }

        $this->createdPaths[$relativePath] = true;
        $this->handles[$relativePath] = [
            'handle' => $handle,
            'touched_at' => ++$this->handleClock,
        ];

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function writeAll($handle, string $contents, string $label): void
    {
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException("Unable to write generated dataset file {$label}.");
            }
            $offset += $written;
        }
    }

    private function closeHandles(string $label = 'generated shards'): void
    {
        $failures = [];
        foreach (array_keys($this->handles) as $path) {
            $failures = [
                ...$failures,
                ...$this->flushAndClose($path),
            ];
        }

        if ($failures !== []) {
            throw new RuntimeException(
                "Unable to close {$label}: ".implode('; ', $failures),
            );
        }
    }

    private function evictLeastRecentlyUsedHandle(): void
    {
        $path = null;
        $oldest = PHP_INT_MAX;
        foreach ($this->handles as $candidate => $entry) {
            if ($entry['touched_at'] < $oldest) {
                $path = $candidate;
                $oldest = $entry['touched_at'];
            }
        }

        if ($path === null) {
            throw new RuntimeException('Unable to select a generated dataset handle for eviction.');
        }

        $failures = $this->flushAndClose($path);
        if ($failures !== []) {
            throw new RuntimeException(
                'Unable to evict generated shard: '.implode('; ', $failures),
            );
        }
    }

    /**
     * The handle is always removed from the live pool. Both operations are
     * attempted so one failed flush cannot leak every later descriptor.
     *
     * @return list<string>
     */
    private function flushAndClose(string $path): array
    {
        $entry = $this->handles[$path] ?? null;
        if ($entry === null) {
            return [];
        }

        unset($this->handles[$path]);
        $failures = [];
        if (! fflush($entry['handle'])) {
            $failures[] = "flush failed for {$path}";
        }
        if (! fclose($entry['handle'])) {
            $failures[] = "close failed for {$path}";
        }

        return $failures;
    }

    private function finalizeActiveThread(): void
    {
        if ($this->activeThreadId === null) {
            return;
        }

        $this->increment($this->threadLengthCounts, (string) $this->activeThreadLength);
        $this->activeThreadId = null;
        $this->activeThreadLength = 0;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function writeFixtureIndexEntry(array $record): void
    {
        $entry = FixtureMetadataIndex::entryFromRecord($record);
        $prefix = FixtureMetadataIndex::prefix((string) $entry['fixture_id']);
        $relativePath = "indexes/fixtures/{$prefix}.jsonl";

        try {
            $line = json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode fixture metadata index entry.', 0, $exception);
        }

        $this->writeAll($this->handleFor($relativePath), $line, $relativePath);
        if (! isset($this->fixtureIndexShards[$relativePath])) {
            $this->fixtureIndexShards[$relativePath] = [
                'path' => $relativePath,
                'prefix' => $prefix,
                'records' => 0,
            ];
        }
        $this->fixtureIndexShards[$relativePath]['records']++;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function increment(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    private function makeDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create dataset directory {$path}.");
        }
    }

    /**
     * @param  array<string, int>  $values
     * @return array<string, int>
     */
    private function sorted(array $values): array
    {
        ksort($values, SORT_STRING);

        return $values;
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }
}
