<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use Generator;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Read-only streaming boundary for generated datasets.
 *
 * Checksums are verified for the complete dataset before the first record is
 * yielded, so a consumer never starts an IMAP delivery from a corrupt corpus.
 */
final readonly class EmailDatasetReader
{
    public function __construct(
        private EmailRecordValidator $recordValidator,
        private ?string $currentGeneratorRevision = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function manifest(string $datasetDirectory): array
    {
        return $this->loadAndValidateManifest($datasetDirectory);
    }

    /**
     * @return array<string, mixed>
     */
    public function manifestForVersion(string $rootDirectory, string $datasetVersion): array
    {
        $manifest = $this->manifest($this->datasetDirectory($rootDirectory, $datasetVersion));

        if (($manifest['dataset_version'] ?? null) !== $datasetVersion) {
            throw new RuntimeException(
                "Email dataset manifest identity mismatch: requested {$datasetVersion}, "
                .'declared '.(string) ($manifest['dataset_version'] ?? 'missing').'.',
            );
        }

        return $manifest;
    }

    public function datasetDirectory(string $rootDirectory, string $datasetVersion): string
    {
        if (! preg_match('/^[a-z0-9-]+$/', $datasetVersion)) {
            throw new InvalidArgumentException("Unsafe email dataset version: {$datasetVersion}");
        }

        return rtrim($rootDirectory, DIRECTORY_SEPARATOR).'/'.$datasetVersion;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function records(string $datasetDirectory): Generator
    {
        yield from $this->stream($datasetDirectory, null);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function recordsForMailbox(string $datasetDirectory, string $mailboxKey): Generator
    {
        if (! preg_match('/^[a-z0-9-]+$/', $mailboxKey)) {
            throw new InvalidArgumentException("Unsafe mailbox key: {$mailboxKey}");
        }

        yield from $this->stream($datasetDirectory, $mailboxKey);
    }

    /**
     * Looks up one fixture without loading or scanning the email corpus.
     *
     * @return array<string, mixed>|null
     */
    public function fixtureMetadata(string $datasetDirectory, string $fixtureId): ?array
    {
        return $this->lookupFixtureMetadata($datasetDirectory, $fixtureId);
    }

    /**
     * Version-bound lookup used by the asynchronous IMAP ingestion bridge.
     *
     * @return array<string, mixed>|null
     */
    public function fixtureMetadataForVersion(
        string $rootDirectory,
        string $datasetVersion,
        string $fixtureId,
    ): ?array {
        return $this->lookupFixtureMetadata(
            $this->datasetDirectory($rootDirectory, $datasetVersion),
            $fixtureId,
            $datasetVersion,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupFixtureMetadata(
        string $datasetDirectory,
        string $fixtureId,
        ?string $expectedDatasetVersion = null,
    ): ?array
    {
        $prefix = FixtureMetadataIndex::prefix($fixtureId);
        $base = $this->realDatasetDirectory($datasetDirectory);
        $manifest = $this->readManifest($base);
        if ($expectedDatasetVersion !== null
            && ($manifest['dataset_version'] ?? null) !== $expectedDatasetVersion) {
            throw new RuntimeException(
                "Email dataset manifest identity mismatch: requested {$expectedDatasetVersion}, "
                .'declared '.(string) ($manifest['dataset_version'] ?? 'missing').'.',
            );
        }
        $index = $this->fixtureIndexDefinition($manifest);
        $matchingShard = null;

        foreach ($index['shards'] as $shard) {
            if (($shard['prefix'] ?? null) === $prefix) {
                $matchingShard = $shard;
                break;
            }
        }

        if ($matchingShard === null) {
            return null;
        }

        $path = $this->resolveShardPath($base, (string) $matchingShard['path']);
        $actualChecksum = hash_file('sha256', $path);
        if ($actualChecksum === false
            || ! hash_equals((string) $matchingShard['sha256'], $actualChecksum)) {
            throw new RuntimeException(
                "Checksum mismatch for fixture metadata index shard {$matchingShard['path']}.",
            );
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open fixture metadata index shard {$path}.");
        }

        $lineNumber = 0;
        $records = 0;
        $match = null;
        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                if (trim($line) === '') {
                    continue;
                }

                try {
                    $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException(
                        "Invalid fixture metadata JSONL at {$matchingShard['path']}:{$lineNumber}: "
                        .$exception->getMessage(),
                        0,
                        $exception,
                    );
                }
                if (! is_array($entry)) {
                    throw new RuntimeException(
                        "Fixture metadata entry {$matchingShard['path']}:{$lineNumber} is not an object.",
                    );
                }

                try {
                    FixtureMetadataIndex::validateEntry($entry, $prefix);
                } catch (InvalidArgumentException $exception) {
                    throw new RuntimeException(
                        "Invalid fixture metadata entry {$matchingShard['path']}:{$lineNumber}: "
                        .$exception->getMessage(),
                        0,
                        $exception,
                    );
                }

                $records++;
                if ($entry['fixture_id'] === $fixtureId) {
                    if ($match !== null) {
                        throw new RuntimeException(
                            "Fixture metadata index contains duplicate fixture_id {$fixtureId}.",
                        );
                    }
                    $match = $entry;
                }
            }

            if (! feof($handle)) {
                throw new RuntimeException(
                    "Read failed before EOF for fixture metadata index shard {$matchingShard['path']}.",
                );
            }
        } finally {
            if (! fclose($handle)) {
                throw new RuntimeException("Unable to close fixture metadata index shard {$matchingShard['path']}.");
            }
        }

        if ($records !== (int) $matchingShard['records']) {
            throw new RuntimeException(
                "Fixture metadata index shard {$matchingShard['path']} contains {$records} records; "
                ."manifest declares {$matchingShard['records']}.",
            );
        }

        return $match;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function stream(string $datasetDirectory, ?string $mailboxKey): Generator
    {
        $manifest = $this->loadAndValidateManifest($datasetDirectory);
        $base = $this->realDatasetDirectory($datasetDirectory);
        $matched = false;

        foreach ($manifest['shards'] as $shard) {
            if ($mailboxKey !== null && ($shard['mailbox'] ?? null) !== $mailboxKey) {
                continue;
            }
            $matched = true;
            $path = $this->resolveShardPath($base, (string) $shard['path']);
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException("Unable to open email dataset shard {$path}.");
            }

            $lineNumber = 0;
            $records = 0;
            try {
                while (($line = fgets($handle)) !== false) {
                    $lineNumber++;
                    if (trim($line) === '') {
                        continue;
                    }

                    try {
                        $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException $exception) {
                        throw new RuntimeException(
                            "Invalid JSONL at {$shard['path']}:{$lineNumber}: {$exception->getMessage()}",
                            0,
                            $exception,
                        );
                    }
                    if (! is_array($record)) {
                        throw new RuntimeException("Email dataset record {$shard['path']}:{$lineNumber} is not an object.");
                    }

                    $this->recordValidator->validate($record);
                    if (($record['dataset_version'] ?? null) !== $manifest['dataset_version']) {
                        throw new RuntimeException(
                            "Email dataset record {$shard['path']}:{$lineNumber} belongs to a different dataset version.",
                        );
                    }
                    if (($record['company_key'] ?? null) !== ($shard['company'] ?? null)
                        || ($record['mailbox_key'] ?? null) !== ($shard['mailbox'] ?? null)
                        || substr((string) ($record['sent_at'] ?? ''), 0, 7) !== ($shard['month'] ?? null)) {
                        throw new RuntimeException(
                            "Email dataset record {$shard['path']}:{$lineNumber} does not match shard metadata."
                        );
                    }

                    $records++;
                    yield $record;
                }

                if (! feof($handle)) {
                    throw new RuntimeException("Read failed before EOF for email dataset shard {$shard['path']}.");
                }
            } finally {
                if (! fclose($handle)) {
                    throw new RuntimeException("Unable to close email dataset shard {$shard['path']}.");
                }
            }

            if ($records !== (int) $shard['records']) {
                throw new RuntimeException(
                    "Shard {$shard['path']} contains {$records} records; manifest declares {$shard['records']}."
                );
            }
        }

        if ($mailboxKey !== null && ! $matched) {
            throw new InvalidArgumentException("Mailbox {$mailboxKey} is absent from the email dataset.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadAndValidateManifest(string $datasetDirectory): array
    {
        $base = $this->realDatasetDirectory($datasetDirectory);
        $manifest = $this->readManifest($base);
        $aggregateParts = [];
        $recordTotal = 0;
        $previousPath = null;
        foreach ($manifest['shards'] as $shard) {
            if ($previousPath !== null && strcmp($previousPath, $shard['path']) >= 0) {
                throw new RuntimeException('Email dataset manifest shard paths must be unique and lexically sorted.');
            }
            $previousPath = $shard['path'];

            $shardPath = $this->resolveShardPath($base, $shard['path']);
            $actual = hash_file('sha256', $shardPath);
            if ($actual === false || ! hash_equals($shard['sha256'], $actual)) {
                throw new RuntimeException("Checksum mismatch for email dataset shard {$shard['path']}.");
            }

            $aggregateParts[] = $shard['path'].' '.$actual;
            $recordTotal += (int) $shard['records'];
        }

        $aggregate = hash('sha256', implode("\n", $aggregateParts)."\n");
        if (! is_string($manifest['aggregate_checksum'] ?? null)
            || ! hash_equals($manifest['aggregate_checksum'], $aggregate)) {
            throw new RuntimeException('Email dataset aggregate checksum mismatch.');
        }

        if ($recordTotal !== (int) ($manifest['total_records'] ?? -1)
            || count($manifest['shards']) !== (int) ($manifest['total_shards'] ?? -1)) {
            throw new RuntimeException('Email dataset manifest totals do not match its shard declarations.');
        }

        $this->validateCompleteFixtureIndex($base, $manifest);

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $base): array
    {
        $path = $base.'/manifest.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read email dataset manifest {$path}.");
        }

        try {
            $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid email dataset manifest {$path}: {$exception->getMessage()}", 0, $exception);
        }

        $datasetVersion = is_array($manifest)
            ? ($manifest['dataset_version'] ?? null)
            : null;
        $generatorRevision = is_array($manifest)
            ? ($manifest['generator_revision'] ?? null)
            : null;
        if (! is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== '2.0'
            || ! is_string($datasetVersion)
            || ! preg_match('/^[a-z0-9-]+$/', $datasetVersion)
            || ! is_string($generatorRevision)
            || ! DatasetVersion::supportsGeneratorRevision(
                $generatorRevision,
                $this->currentGeneratorRevision,
            )
            || ! DatasetVersion::hasGeneratorRevisionPrefix(
                $datasetVersion,
                $generatorRevision,
            )
            || ! is_string($manifest['snapshot_fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $manifest['snapshot_fingerprint']) !== 1
            || ! str_contains(
                $datasetVersion,
                '-snap'.substr($manifest['snapshot_fingerprint'], 0, 16),
            )
            || ! is_array($manifest['shards'] ?? null)
            || ! array_is_list($manifest['shards'])) {
            throw new RuntimeException("Email dataset manifest {$path} has an invalid contract.");
        }

        foreach ($manifest['shards'] as $shard) {
            if (! is_array($shard)
                || ! isset($shard['path'], $shard['sha256'], $shard['records'], $shard['company'], $shard['mailbox'], $shard['month'])
                || ! is_string($shard['path'])
                || ! is_string($shard['sha256'])
                || ! preg_match('/^[a-f0-9]{64}$/', $shard['sha256'])
                || ! is_int($shard['records'])
                || $shard['records'] < 0) {
                throw new RuntimeException("Email dataset manifest {$path} contains an invalid shard.");
            }
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function fixtureIndexDefinition(array $manifest): array
    {
        $index = $manifest['indexes']['fixtures'] ?? null;
        if (! is_array($index)
            || ($index['algorithm'] ?? null) !== 'fixture-id-prefix'
            || ($index['prefix_length'] ?? null) !== FixtureMetadataIndex::PREFIX_LENGTH
            || ! is_int($index['total_records'] ?? null)
            || ! is_int($index['total_shards'] ?? null)
            || ! is_string($index['aggregate_checksum'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $index['aggregate_checksum']) !== 1
            || ! is_array($index['shards'] ?? null)
            || ! array_is_list($index['shards'])) {
            throw new RuntimeException('Email dataset manifest does not declare a valid fixture metadata index.');
        }

        $previousPath = null;
        $prefixes = [];
        foreach ($index['shards'] as $shard) {
            if (! is_array($shard)
                || ! is_string($shard['path'] ?? null)
                || ! is_string($shard['prefix'] ?? null)
                || preg_match('/^[a-f0-9]{2}$/', $shard['prefix']) !== 1
                || $shard['path'] !== "indexes/fixtures/{$shard['prefix']}.jsonl"
                || ! is_int($shard['records'] ?? null)
                || $shard['records'] < 1
                || ! is_string($shard['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $shard['sha256']) !== 1) {
                throw new RuntimeException('Email dataset manifest contains an invalid fixture metadata index shard.');
            }
            if ($previousPath !== null && strcmp($previousPath, $shard['path']) >= 0) {
                throw new RuntimeException('Fixture metadata index shard paths must be unique and lexically sorted.');
            }
            if (isset($prefixes[$shard['prefix']])) {
                throw new RuntimeException('Fixture metadata index prefixes must be unique.');
            }
            $previousPath = $shard['path'];
            $prefixes[$shard['prefix']] = true;
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function validateCompleteFixtureIndex(string $base, array $manifest): void
    {
        $index = $this->fixtureIndexDefinition($manifest);
        $aggregateParts = [];
        $records = 0;

        foreach ($index['shards'] as $shard) {
            $path = $this->resolveShardPath($base, $shard['path']);
            $actual = hash_file('sha256', $path);
            if ($actual === false || ! hash_equals($shard['sha256'], $actual)) {
                throw new RuntimeException(
                    "Checksum mismatch for fixture metadata index shard {$shard['path']}.",
                );
            }
            $aggregateParts[] = $shard['path'].' '.$actual;
            $records += $shard['records'];
        }

        $aggregate = hash('sha256', implode("\n", $aggregateParts)."\n");
        if (! hash_equals($index['aggregate_checksum'], $aggregate)) {
            throw new RuntimeException('Fixture metadata index aggregate checksum mismatch.');
        }
        if ($records !== $index['total_records']
            || count($index['shards']) !== $index['total_shards']
            || $records !== (int) ($manifest['total_records'] ?? -1)) {
            throw new RuntimeException('Fixture metadata index totals do not match the dataset manifest.');
        }
    }

    private function realDatasetDirectory(string $datasetDirectory): string
    {
        $real = realpath($datasetDirectory);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("Email dataset directory does not exist: {$datasetDirectory}");
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function resolveShardPath(string $base, string $relativePath): string
    {
        if ($relativePath === ''
            || str_starts_with($relativePath, '/')
            || in_array('..', explode('/', str_replace('\\', '/', $relativePath)), true)) {
            throw new RuntimeException("Unsafe email dataset shard path: {$relativePath}");
        }

        $real = realpath($base.'/'.$relativePath);
        if ($real === false || ! is_file($real) || ! str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Email dataset shard escapes or is missing: {$relativePath}");
        }

        return $real;
    }
}
