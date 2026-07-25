<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo\EmailDataset;

use App\Services\Demo\EmailDataset\CatalogLoader;
use App\Services\Demo\EmailDataset\DatasetGenerationRequest;
use App\Services\Demo\EmailDataset\DatasetPublisher;
use App\Services\Demo\EmailDataset\EmailDatasetGenerator;
use App\Services\Demo\EmailDataset\EmailDatasetQualityValidator;
use App\Services\Demo\EmailDataset\EmailDatasetReader;
use App\Services\Demo\EmailDataset\EmailRecordValidator;
use App\Services\Demo\EmailDataset\ExactAllocator;
use App\Services\Demo\EmailDataset\GoldCategoryClassifier;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use Tests\TestCase;

final class EmailDatasetQualityValidatorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        $files = new Filesystem;
        foreach ($this->temporaryDirectories as $directory) {
            if (is_dir($directory)) {
                $this->assertTrue($files->deleteDirectory($directory));
            }
        }
        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    public function test_disk_backed_indexes_are_removed_after_a_successful_scan(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'demo',
            seed: 311,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: ['prometeo-antincendio-1'],
        ));
        $indexDirectory = $this->temporaryDirectory(create: true);

        $this->validator($indexDirectory)->validate($result->directory);

        $this->assertSame([], $this->directoryEntries($indexDirectory));
    }

    public function test_duplicate_generated_content_is_rejected_by_the_disk_index(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'demo',
            seed: 912,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: ['rotta-logistics-1'],
        ));
        $indexDirectory = $this->temporaryDirectory(create: true);
        $firstGenerated = null;

        $this->mutateRecords(
            $result->directory,
            static function (array $record) use (&$firstGenerated): array {
                if (($record['message_type'] ?? null) === 'gold') {
                    return $record;
                }

                if ($firstGenerated === null) {
                    $firstGenerated = [
                        'subject' => (string) $record['subject'],
                        'body_text' => (string) $record['body_text'],
                    ];

                    return $record;
                }

                if ($firstGenerated !== false) {
                    $record['subject'] = $firstGenerated['subject'];
                    $record['body_text'] = $firstGenerated['body_text'];
                    $firstGenerated = false;
                }

                return $record;
            },
        );
        $this->assertFalse($firstGenerated, 'The generated test corpus must contain at least two generated records.');

        try {
            $this->validator($indexDirectory)->validate($result->directory);
            $this->fail('Duplicate generated content was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Duplicate generated subject+body pair',
                $exception->getMessage(),
            );
        }

        $this->assertSame([], $this->directoryEntries($indexDirectory));
    }

    public function test_foreign_canary_phrase_is_rejected_even_without_a_declared_canary_id(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'gold',
            seed: 144,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: [
                'passolibero-calzature-1',
                'rotta-logistics-1',
            ],
        ));
        $indexDirectory = $this->temporaryDirectory(create: true);
        $catalog = (new CatalogLoader)->loadCompany('v1', 'passolibero-calzature');
        $foreignCanary = (array) $catalog['canaries'][0];
        $foreignPhrase = (string) $foreignCanary['phrase'];
        $mutated = false;

        $this->mutateRecords(
            $result->directory,
            static function (array $record) use ($foreignPhrase, &$mutated): array {
                if (! $mutated && ($record['company_key'] ?? null) === 'rotta-logistics') {
                    $record['body_text'] .= "\n\n".$foreignPhrase;
                    $mutated = true;
                }

                return $record;
            },
        );
        $this->assertTrue($mutated, 'The generated test corpus must contain a Rotta Logistics record.');

        try {
            $this->validator($indexDirectory)->validate($result->directory);
            $this->fail('A foreign canary phrase was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Cross-company canary phrase '.(string) $foreignCanary['canary_id'],
                $exception->getMessage(),
            );
        }

        $this->assertSame([], $this->directoryEntries($indexDirectory));
    }

    public function test_thread_chain_validation_is_preserved_by_the_disk_index(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'demo',
            seed: 721,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: ['prometeo-antincendio-2'],
        ));
        $indexDirectory = $this->temporaryDirectory(create: true);
        $messagesByThread = [];
        $mutated = false;

        $this->mutateRecords(
            $result->directory,
            static function (array $record) use (&$messagesByThread, &$mutated): array {
                if (($record['message_type'] ?? null) !== 'thread') {
                    return $record;
                }

                $threadId = (string) $record['thread_id'];
                $previous = $messagesByThread[$threadId] ?? [];
                if (! $mutated && count($previous) >= 2) {
                    $record['in_reply_to'] = $previous[0];
                    $mutated = true;
                }
                $previous[] = (string) $record['message_id'];
                $messagesByThread[$threadId] = $previous;

                return $record;
            },
        );
        $this->assertTrue($mutated, 'The generated test corpus must contain a thread with at least three messages.');

        try {
            $this->validator($indexDirectory)->validate($result->directory);
            $this->fail('A non-contiguous reply chain was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'orphan or non-contiguous reply',
                $exception->getMessage(),
            );
        }

        $this->assertSame([], $this->directoryEntries($indexDirectory));
    }

    private function generator(): EmailDatasetGenerator
    {
        return new EmailDatasetGenerator(
            new CatalogLoader,
            new ExactAllocator,
            new GoldCategoryClassifier,
            new EmailRecordValidator,
            new DatasetPublisher,
            $this->validator(),
        );
    }

    private function validator(?string $temporaryDirectory = null): EmailDatasetQualityValidator
    {
        return new EmailDatasetQualityValidator(
            new EmailDatasetReader(new EmailRecordValidator),
            temporaryDirectory: $temporaryDirectory,
        );
    }

    private function temporaryDirectory(bool $create = false): string
    {
        $directory = sys_get_temp_dir().'/askmydocs-email-quality-test-'.bin2hex(random_bytes(8));
        $this->temporaryDirectories[] = $directory;

        if ($create && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create test directory {$directory}.");
        }

        return $directory;
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     *
     * @throws JsonException
     */
    private function mutateRecords(string $datasetDirectory, callable $mutator): void
    {
        $manifestPath = $datasetDirectory.'/manifest.json';
        $manifestContents = file_get_contents($manifestPath);
        $this->assertNotFalse($manifestContents);
        $manifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        foreach ($manifest['shards'] as $shardIndex => $shard) {
            $path = $datasetDirectory.'/'.(string) $shard['path'];
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            $this->assertNotFalse($lines);
            $rewritten = [];

            foreach ($lines as $line) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $this->assertIsArray($record);
                $rewritten[] = json_encode($mutator($record), JSON_THROW_ON_ERROR);
            }

            $written = file_put_contents($path, implode("\n", $rewritten)."\n");
            $this->assertNotFalse($written);
            $checksum = hash_file('sha256', $path);
            $this->assertNotFalse($checksum);
            $manifest['shards'][$shardIndex]['sha256'] = $checksum;
        }

        $aggregateParts = [];
        foreach ($manifest['shards'] as $shard) {
            $aggregateParts[] = $shard['path'].' '.$shard['sha256'];
        }
        $manifest['aggregate_checksum'] = hash('sha256', implode("\n", $aggregateParts)."\n");
        $encodedManifest = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
        $this->assertNotFalse(file_put_contents($manifestPath, $encodedManifest));
    }

    /**
     * @return list<string>
     */
    private function directoryEntries(string $directory): array
    {
        $entries = scandir($directory);
        $this->assertNotFalse($entries);

        return array_values(array_diff($entries, ['.', '..']));
    }
}
