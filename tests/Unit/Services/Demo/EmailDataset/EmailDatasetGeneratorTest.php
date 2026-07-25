<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo\EmailDataset;

use App\Services\Demo\EmailDataset\CatalogLoader;
use App\Services\Demo\EmailDataset\DatasetGenerationRequest;
use App\Services\Demo\EmailDataset\DatasetProfile;
use App\Services\Demo\EmailDataset\DatasetPublisher;
use App\Services\Demo\EmailDataset\DatasetVersion;
use App\Services\Demo\EmailDataset\EmailDatasetGenerator;
use App\Services\Demo\EmailDataset\EmailDatasetQualityValidator;
use App\Services\Demo\EmailDataset\EmailDatasetReader;
use App\Services\Demo\EmailDataset\EmailRecordValidator;
use App\Services\Demo\EmailDataset\ExactAllocator;
use App\Services\Demo\EmailDataset\FixtureMetadataIndex;
use App\Services\Demo\EmailDataset\GoldCategoryClassifier;
use Closure;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class EmailDatasetGeneratorTest extends TestCase
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

    public function test_profiles_expose_the_approved_exact_volumes(): void
    {
        $catalogs = new CatalogLoader;
        $index = $catalogs->loadIndex('v1');
        $goldTotal = 0;
        $mailboxCount = 0;

        foreach ($index['companies'] as $companyKey) {
            $company = $catalogs->loadCompany('v1', $companyKey);
            foreach (array_keys($company['mailboxes']) as $mailboxKey) {
                $goldTotal += count($catalogs->loadGold($mailboxKey));
                $mailboxCount++;
                $this->assertSame(1000, array_sum($company['mailboxes'][$mailboxKey]['category_matrix_large']));
            }
        }

        $this->assertSame(6, $mailboxCount);
        $this->assertSame(751, $goldTotal);
        $this->assertSame(3000, $catalogs->loadProfile('demo')->mailboxTarget * $mailboxCount);
        $this->assertSame(6000, $catalogs->loadProfile('large')->mailboxTarget * $mailboxCount);
        $this->assertSame(30000, $catalogs->loadProfile('stress')->mailboxTarget * $mailboxCount);
    }

    /**
     * @param  array<string, int>  $recordsByCompany
     * @param  array<string, int>  $recordsByMailbox
     */
    #[DataProvider('nonLargeProfileVolumes')]
    public function test_non_large_profiles_publish_their_exact_approved_volumes(
        string $profile,
        int $total,
        array $recordsByCompany,
        array $recordsByMailbox,
    ): void {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: $profile,
            seed: 20260723,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
        ));
        $manifest = (new EmailDatasetReader(new EmailRecordValidator))
            ->manifest($result->directory);

        $this->assertSame($total, $manifest['total_records']);
        $this->assertSame($recordsByCompany, $manifest['statistics']['records_by_company']);
        $this->assertSame($recordsByMailbox, $manifest['statistics']['records_by_mailbox']);
        $this->assertSame($total, $manifest['indexes']['fixtures']['total_records']);
    }

    /**
     * @return array<string, array{
     *     string,
     *     int,
     *     array<string, int>,
     *     array<string, int>
     * }>
     */
    public static function nonLargeProfileVolumes(): array
    {
        return [
            'gold' => [
                'gold',
                751,
                [
                    'passolibero-calzature' => 242,
                    'prometeo-antincendio' => 247,
                    'rotta-logistics' => 262,
                ],
                [
                    'passolibero-calzature-1' => 122,
                    'passolibero-calzature-2' => 120,
                    'prometeo-antincendio-1' => 128,
                    'prometeo-antincendio-2' => 119,
                    'rotta-logistics-1' => 136,
                    'rotta-logistics-2' => 126,
                ],
            ],
            'demo' => [
                'demo',
                3000,
                [
                    'passolibero-calzature' => 1000,
                    'prometeo-antincendio' => 1000,
                    'rotta-logistics' => 1000,
                ],
                [
                    'passolibero-calzature-1' => 500,
                    'passolibero-calzature-2' => 500,
                    'prometeo-antincendio-1' => 500,
                    'prometeo-antincendio-2' => 500,
                    'rotta-logistics-1' => 500,
                    'rotta-logistics-2' => 500,
                ],
            ],
            'stress' => [
                'stress',
                30000,
                [
                    'passolibero-calzature' => 10000,
                    'prometeo-antincendio' => 10000,
                    'rotta-logistics' => 10000,
                ],
                [
                    'passolibero-calzature-1' => 5000,
                    'passolibero-calzature-2' => 5000,
                    'prometeo-antincendio-1' => 5000,
                    'prometeo-antincendio-2' => 5000,
                    'rotta-logistics-1' => 5000,
                    'rotta-logistics-2' => 5000,
                ],
            ],
        ];
    }

    public function test_same_inputs_produce_byte_identical_dataset_trees(): void
    {
        $firstRoot = $this->temporaryDirectory();
        $secondRoot = $this->temporaryDirectory();
        $generator = $this->generator();
        $request = fn (string $root): DatasetGenerationRequest => new DatasetGenerationRequest(
            profile: 'demo',
            seed: 42017,
            catalogVersion: 'v1',
            outputDirectory: $root,
            mailboxes: ['rotta-logistics-1'],
        );

        $first = $generator->generate($request($firstRoot));
        $second = $generator->generate($request($secondRoot));

        $this->assertSame($first->datasetVersion, $second->datasetVersion);
        $this->assertSame($first->aggregateChecksum, $second->aggregateChecksum);
        $this->assertSame($this->hashTree($first->directory), $this->hashTree($second->directory));
    }

    public function test_generation_discards_the_artifact_when_input_snapshot_changes(): void
    {
        $outputDirectory = $this->temporaryDirectory();
        $catalogs = new CatalogLoader;
        $fingerprintCalls = 0;
        $resolver = static function (
            string $profile,
            string $catalogVersion,
            array $companies,
            array $mailboxes,
        ) use ($catalogs, &$fingerprintCalls): string {
            $fingerprintCalls++;
            $fingerprint = $catalogs->snapshotFingerprint(
                $profile,
                $catalogVersion,
                $companies,
                $mailboxes,
            );

            return $fingerprintCalls === 1
                ? $fingerprint
                : hash('sha256', $fingerprint."\0controlled-mutation");
        };

        try {
            $this->generator($catalogs, $resolver)->generate(new DatasetGenerationRequest(
                profile: 'gold',
                seed: 42018,
                catalogVersion: 'v1',
                outputDirectory: $outputDirectory,
                mailboxes: ['rotta-logistics-1'],
            ));
            $this->fail('A dataset generated across two different input snapshots was published.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'inputs changed during generation',
                $exception->getMessage(),
            );
        }

        $this->assertSame(2, $fingerprintCalls);
        $entries = scandir($outputDirectory);
        $this->assertIsArray($entries);
        $this->assertSame([], array_values(array_diff($entries, ['.', '..'])));
    }

    public function test_generation_reloads_inputs_captured_after_the_initial_fingerprint(): void
    {
        $catalogRoot = $this->temporaryDirectory();
        $files = new Filesystem;
        $this->assertTrue($files->copyDirectory(
            dirname(__DIR__, 5).'/database/seeders/email-dataset',
            $catalogRoot,
        ));
        $catalogs = new CatalogLoader($catalogRoot);
        $scenarioPath = $catalogRoot
            .'/catalogs/v1/companies/rotta-logistics/scenarios.json';
        $mutationMarker = 'SNAPSHOT-B-CONTENT';
        $fingerprintCalls = 0;
        $resolver = function (
            string $profile,
            string $catalogVersion,
            array $companies,
            array $mailboxes,
        ) use (
            $catalogs,
            $scenarioPath,
            $mutationMarker,
            &$fingerprintCalls,
        ): string {
            $fingerprintCalls++;
            if ($fingerprintCalls === 1) {
                $contents = file_get_contents($scenarioPath);
                $this->assertNotFalse($contents);
                $scenarios = json_decode(
                    $contents,
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                $this->assertIsArray($scenarios);
                foreach (
                    $scenarios['scenarios']['spedizioni-tracking']['body_templates']
                    as &$bodyTemplate
                ) {
                    $bodyTemplate = $mutationMarker."\n\n".$bodyTemplate;
                }
                unset($bodyTemplate);
                $encoded = json_encode(
                    $scenarios,
                    JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR,
                )."\n";
                $this->assertNotFalse(file_put_contents($scenarioPath, $encoded));
            }

            return $catalogs->snapshotFingerprint(
                $profile,
                $catalogVersion,
                $companies,
                $mailboxes,
            );
        };

        $result = $this->generator($catalogs, $resolver)->generate(
            new DatasetGenerationRequest(
                profile: 'demo',
                seed: 42021,
                catalogVersion: 'v1',
                outputDirectory: $this->temporaryDirectory(),
                mailboxes: ['rotta-logistics-1'],
            ),
        );

        $foundReloadedContent = false;
        $reader = new EmailDatasetReader(new EmailRecordValidator);
        foreach ($reader->records($result->directory) as $record) {
            if (str_contains((string) $record['body_text'], $mutationMarker)) {
                $foundReloadedContent = true;
                break;
            }
        }

        $this->assertSame(2, $fingerprintCalls);
        $this->assertTrue(
            $foundReloadedContent,
            'The artifact used values loaded before the committed snapshot fingerprint.',
        );
    }

    public function test_reader_keeps_g1_readable_after_a_simulated_current_revision_bump(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'gold',
            seed: 42019,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: ['rotta-logistics-1'],
        ));

        $reader = new EmailDatasetReader(
            new EmailRecordValidator,
            currentGeneratorRevision: 'g2',
        );
        $manifest = $reader->manifest($result->directory);

        $this->assertSame('g1', $manifest['generator_revision']);
        $this->assertTrue(DatasetVersion::supportsGeneratorRevision('g1', 'g2'));
    }

    public function test_reader_rejects_an_unknown_generator_revision(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'gold',
            seed: 42020,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: ['rotta-logistics-1'],
        ));
        $manifest = $this->readRawManifest($result->directory);
        $manifest['generator_revision'] = 'g999';
        $manifest['dataset_version'] = preg_replace(
            '/^case-study-email-v2-g1-/',
            'case-study-email-v2-g999-',
            (string) $manifest['dataset_version'],
        );
        $this->assertIsString($manifest['dataset_version']);
        $this->writeRawManifest($result->directory, $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid contract');

        (new EmailDatasetReader(new EmailRecordValidator))->manifest($result->directory);
    }

    public function test_dataset_version_is_centralized_and_subset_identity_is_deterministic(): void
    {
        $catalogs = new CatalogLoader;
        $companies = $catalogs->loadIndex('v1')['companies'];
        $mailboxes = [];
        foreach ($companies as $companyKey) {
            array_push($mailboxes, ...array_keys($catalogs->loadCompany('v1', $companyKey)['mailboxes']));
        }
        sort($mailboxes, SORT_STRING);
        $snapshot = $catalogs->snapshotFingerprint('large', 'v1', $companies, $mailboxes);

        $this->assertSame(
            'case-study-email-v2-g1-large-s20260723-catalogv1-snap'.substr($snapshot, 0, 16),
            DatasetVersion::standard('large'),
        );
        $this->assertSame(
            'case-study-email-v2-g1-demo-s42-catalogv1-snap'
                .str_repeat('a', 16).'-subset-b88469c917',
            DatasetVersion::make(
                profile: 'demo',
                seed: 42,
                catalogVersion: 'v1',
                companies: ['rotta-logistics'],
                mailboxes: ['rotta-logistics-1'],
                subset: true,
                snapshotFingerprint: str_repeat('a', 64),
            ),
        );

        $this->expectException(InvalidArgumentException::class);
        DatasetVersion::standard('unknown-profile');
    }

    public function test_profile_rejects_a_calendar_date_that_php_would_normalize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('YYYY-MM-DD');

        DatasetProfile::fromArray([
            'key' => 'invalid-calendar',
            'mailbox_target' => 10,
            'include_gold' => true,
            'thread_ratio' => 0.5,
            'timeline_start' => '2026-02-30',
            'timeline_end' => '2026-03-01',
        ]);
    }

    public function test_large_profile_has_exact_company_mailbox_and_thread_counts(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'large',
            seed: 20260723,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
        ));
        $reader = new EmailDatasetReader(new EmailRecordValidator);
        $manifest = $reader->manifest($result->directory);

        $this->assertSame(6000, $manifest['total_records']);
        $this->assertSame([
            'passolibero-calzature' => 2000,
            'prometeo-antincendio' => 2000,
            'rotta-logistics' => 2000,
        ], $manifest['statistics']['records_by_company']);
        $this->assertSame([
            'passolibero-calzature-1' => 1000,
            'passolibero-calzature-2' => 1000,
            'prometeo-antincendio-1' => 1000,
            'prometeo-antincendio-2' => 1000,
            'rotta-logistics-1' => 1000,
            'rotta-logistics-2' => 1000,
        ], $manifest['statistics']['records_by_mailbox']);
        $this->assertSame(3900, $manifest['statistics']['thread_messages']);
        $this->assertSame(0.65, $manifest['statistics']['thread_ratio']);
        $this->assertSame([
            'commerciale-sla' => 30,
            'correzioni-rumore' => 10,
            'customer-care' => 100,
            'dogana-adr' => 150,
            'fatture' => 20,
            'formazione' => 10,
            'hub-magazzino' => 200,
            'orbita-incidenti' => 60,
            'spedizioni-tracking' => 420,
        ], $manifest['statistics']['records_by_mailbox_category']['rotta-logistics-1']);
        $this->assertSame([
            'passolibero-calzature-1' => 650,
            'passolibero-calzature-2' => 650,
            'prometeo-antincendio-1' => 650,
            'prometeo-antincendio-2' => 650,
            'rotta-logistics-1' => 650,
            'rotta-logistics-2' => 650,
        ], $manifest['statistics']['thread_messages_by_mailbox']);
    }

    public function test_reader_streams_one_mailbox_and_message_ids_encode_dataset_identity(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'demo',
            seed: 91,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: ['prometeo-antincendio-2'],
        ));
        $reader = new EmailDatasetReader(new EmailRecordValidator);
        $count = 0;
        $threadCount = 0;

        foreach ($reader->recordsForMailbox($result->directory, 'prometeo-antincendio-2') as $record) {
            $count++;
            $threadCount += $record['message_type'] === 'thread' ? 1 : 0;
            $this->assertSame(
                "<{$result->datasetVersion}.{$record['fixture_id']}@fixtures.askmydocs.invalid>",
                $record['message_id'],
            );
            $this->assertSame([], $record['attachments']);
        }

        $this->assertSame(500, $count);
        $this->assertSame(325, $threadCount);
    }

    public function test_fixture_metadata_index_is_sharded_checksummed_and_lookupable(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'gold',
            seed: 92,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: ['prometeo-antincendio-2'],
        ));
        $reader = new EmailDatasetReader(new EmailRecordValidator);
        $manifest = $reader->manifest($result->directory);
        $record = null;
        foreach ($reader->recordsForMailbox($result->directory, 'prometeo-antincendio-2') as $candidate) {
            $record = $candidate;
            break;
        }
        $this->assertIsArray($record);

        $index = $manifest['indexes']['fixtures'];
        $this->assertSame('fixture-id-prefix', $index['algorithm']);
        $this->assertSame(2, $index['prefix_length']);
        $this->assertSame($manifest['total_records'], $index['total_records']);
        $this->assertSame(count($index['shards']), $index['total_shards']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $index['aggregate_checksum']);

        $metadata = $reader->fixtureMetadata($result->directory, $record['fixture_id']);
        $this->assertSame([
            'fixture_id' => $record['fixture_id'],
            'company_key' => $record['company_key'],
            'mailbox_key' => $record['mailbox_key'],
            'scenario_type' => $record['scenario_type'],
            'topic' => $record['topic'],
            'message_type' => $record['message_type'],
            'thread_id' => $record['thread_id'],
            'fact_ids' => $record['fact_ids'],
            'canonical_sources' => $record['canonical_sources'],
            'truth_state' => $record['truth_state'],
            'canary_ids' => $record['canary_ids'],
            'content_sha256' => FixtureMetadataIndex::contentChecksum(
                (string) $record['subject'],
                (string) $record['body_text'],
            ),
        ], $metadata);

        $prefix = substr($record['fixture_id'], 0, 2);
        $matching = array_values(array_filter(
            $index['shards'],
            static fn (array $shard): bool => $shard['prefix'] === $prefix,
        ));
        $this->assertCount(1, $matching);
        $this->assertSame("indexes/fixtures/{$prefix}.jsonl", $matching[0]['path']);
    }

    public function test_fixture_metadata_lookup_scans_only_its_prefix_and_checks_that_shard(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'gold',
            seed: 93,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: ['rotta-logistics-1'],
        ));
        $manifest = $this->readRawManifest($result->directory);
        $indexShards = $manifest['indexes']['fixtures']['shards'];
        $this->assertGreaterThan(1, count($indexShards));

        $selected = $indexShards[0];
        $unrelated = $indexShards[count($indexShards) - 1];
        $this->assertNotSame($selected['prefix'], $unrelated['prefix']);
        $selectedLine = file($result->directory.'/'.$selected['path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($selectedLine);
        $this->assertNotEmpty($selectedLine);
        $selectedEntry = json_decode($selectedLine[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($selectedEntry);

        $this->assertNotFalse(file_put_contents(
            $result->directory.'/'.$unrelated['path'],
            "{corrupt-unrelated-index}\n",
            FILE_APPEND,
        ));

        $reader = new EmailDatasetReader(new EmailRecordValidator);
        $this->assertSame(
            $selectedEntry,
            $reader->fixtureMetadata($result->directory, $selectedEntry['fixture_id']),
        );

        $this->assertNotFalse(file_put_contents(
            $result->directory.'/'.$selected['path'],
            "{corrupt-selected-index}\n",
            FILE_APPEND,
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum mismatch for fixture metadata index shard');
        $reader->fixtureMetadata($result->directory, $selectedEntry['fixture_id']);
    }

    public function test_fixture_metadata_lookup_rejects_an_entry_stored_under_a_foreign_prefix(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'gold',
            seed: 94,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: ['passolibero-calzature-1'],
        ));
        $manifest = $this->readRawManifest($result->directory);
        $selected = $manifest['indexes']['fixtures']['shards'][0];
        $indexPath = $result->directory.'/'.$selected['path'];
        $lines = file($indexPath);
        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines);
        $entry = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($entry);
        $fixtureId = $entry['fixture_id'];
        $foreignPrefix = $selected['prefix'] === 'ff' ? '00' : 'ff';
        $entry['fixture_id'] = $foreignPrefix.substr($fixtureId, 2);
        $lines[0] = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
        $this->assertNotFalse(file_put_contents($indexPath, implode('', $lines)));

        foreach ($manifest['indexes']['fixtures']['shards'] as $index => $shard) {
            if ($shard['path'] === $selected['path']) {
                $checksum = hash_file('sha256', $indexPath);
                $this->assertNotFalse($checksum);
                $manifest['indexes']['fixtures']['shards'][$index]['sha256'] = $checksum;
                break;
            }
        }
        $this->writeRawManifest($result->directory, $manifest);

        $reader = new EmailDatasetReader(new EmailRecordValidator);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stored in the wrong prefix shard');
        $reader->fixtureMetadata($result->directory, $fixtureId);
    }

    public function test_reader_fails_before_streaming_when_a_shard_checksum_is_corrupt(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'gold',
            seed: 8,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: ['passolibero-calzature-1'],
        ));
        $reader = new EmailDatasetReader(new EmailRecordValidator);
        $manifest = $reader->manifest($result->directory);
        $shardPath = $result->directory.'/'.$manifest['shards'][0]['path'];
        $this->assertNotFalse(file_put_contents($shardPath, "\n", FILE_APPEND));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum mismatch');
        iterator_to_array($reader->recordsForMailbox($result->directory, 'passolibero-calzature-1'));
    }

    public function test_manifest_for_version_rejects_a_directory_with_a_different_declared_identity(): void
    {
        $root = $this->temporaryDirectory();
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'gold',
            seed: 24,
            catalogVersion: 'v1',
            outputDirectory: $root,
            mailboxes: ['rotta-logistics-1'],
        ));
        $requestedVersion = 'case-study-email-v2-forged';
        $requestedDirectory = $root.'/'.$requestedVersion;
        $files = new Filesystem;
        $this->assertTrue($files->moveDirectory($result->directory, $requestedDirectory));

        $reader = new EmailDatasetReader(new EmailRecordValidator);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest identity mismatch');
        $reader->manifestForVersion($root, $requestedVersion);
    }

    public function test_schema_v2_manifest_without_fixture_index_is_rejected(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'gold',
            seed: 26,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: ['rotta-logistics-2'],
        ));
        $manifest = $this->readRawManifest($result->directory);
        unset($manifest['indexes']);
        $this->writeRawManifest($result->directory, $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixture metadata index');

        (new EmailDatasetReader(new EmailRecordValidator))->manifest($result->directory);
    }

    public function test_reader_rejects_a_record_whose_dataset_identity_differs_from_manifest(): void
    {
        $result = $this->generator()->generate(new DatasetGenerationRequest(
            profile: 'gold',
            seed: 25,
            catalogVersion: 'v1',
            outputDirectory: $this->temporaryDirectory(),
            mailboxes: ['rotta-logistics-1'],
        ));
        $this->rewriteFirstRecordWithForeignDatasetIdentity($result->directory);

        $reader = new EmailDatasetReader(new EmailRecordValidator);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belongs to a different dataset version');
        iterator_to_array($reader->records($result->directory));
    }

    private function generator(
        ?CatalogLoader $catalogs = null,
        ?Closure $snapshotFingerprintResolver = null,
    ): EmailDatasetGenerator
    {
        $catalogs ??= new CatalogLoader;

        return new EmailDatasetGenerator(
            $catalogs,
            new ExactAllocator,
            new GoldCategoryClassifier,
            new EmailRecordValidator,
            new DatasetPublisher,
            new EmailDatasetQualityValidator(
                new EmailDatasetReader(new EmailRecordValidator),
                $catalogs,
            ),
            $snapshotFingerprintResolver,
        );
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/askmydocs-email-dataset-test-'.bin2hex(random_bytes(8));
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    /**
     * @return array<string, string>
     */
    private function hashTree(string $directory): array
    {
        $hashes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $hash = hash_file('sha256', $file->getPathname());
            $this->assertNotFalse($hash);
            $hashes[$relative] = $hash;
        }
        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    /**
     * @return array<string, mixed>
     */
    private function readRawManifest(string $directory): array
    {
        $contents = file_get_contents($directory.'/manifest.json');
        $this->assertNotFalse($contents);
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeRawManifest(string $directory, array $manifest): void
    {
        $contents = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
        $this->assertNotFalse(file_put_contents($directory.'/manifest.json', $contents));
    }

    /**
     * Re-signs the edited shard and aggregate so this test reaches the
     * record-vs-manifest identity guard instead of stopping at checksum validation.
     *
     * @throws JsonException
     */
    private function rewriteFirstRecordWithForeignDatasetIdentity(string $directory): void
    {
        $manifestPath = $directory.'/manifest.json';
        $manifestContents = file_get_contents($manifestPath);
        $this->assertNotFalse($manifestContents);
        $manifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);
        $this->assertNotEmpty($manifest['shards']);

        $firstShardPath = $directory.'/'.$manifest['shards'][0]['path'];
        $lines = file($firstShardPath);
        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines);
        $record = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($record);

        $foreignVersion = 'case-study-email-v2-foreign';
        $record['dataset_version'] = $foreignVersion;
        $record['message_id'] = '<'.$foreignVersion.'.'.$record['fixture_id']
            .'@fixtures.askmydocs.invalid>';
        $lines[0] = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
        $this->assertNotFalse(file_put_contents($firstShardPath, implode('', $lines)));

        $aggregateParts = [];
        foreach ($manifest['shards'] as $index => $shard) {
            $checksum = hash_file('sha256', $directory.'/'.$shard['path']);
            $this->assertNotFalse($checksum);
            $manifest['shards'][$index]['sha256'] = $checksum;
            $aggregateParts[] = $shard['path'].' '.$checksum;
        }
        $manifest['aggregate_checksum'] = hash('sha256', implode("\n", $aggregateParts)."\n");
        $encodedManifest = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
        $this->assertNotFalse(file_put_contents($manifestPath, $encodedManifest));
    }
}
