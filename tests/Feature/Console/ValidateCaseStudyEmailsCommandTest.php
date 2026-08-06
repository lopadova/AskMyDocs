<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Demo\EmailDataset\DatasetPublisher;
use Tests\TestCase;

final class ValidateCaseStudyEmailsCommandTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDirectory = sys_get_temp_dir().'/askmydocs-email-validation-'
            .getmypid().'-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDirectory)) {
            foreach (glob($this->outputDirectory.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
                app(DatasetPublisher::class)->discard($directory);
            }

            if (! rmdir($this->outputDirectory)) {
                $this->fail("Unable to remove test dataset root {$this->outputDirectory}.");
            }
        }

        parent::tearDown();
    }

    public function test_validates_a_published_dataset_without_regenerating_it(): void
    {
        $version = $this->generateSubset();

        $this->artisan('demo:validate-case-study-emails', [
            '--dataset-version' => $version,
            '--dataset-root' => $this->outputDirectory,
        ])
            ->expectsOutputToContain("Dataset valido: {$version}")
            ->assertExitCode(0);
    }

    public function test_fails_loudly_when_a_published_shard_is_modified(): void
    {
        $version = $this->generateSubset();
        $shards = glob($this->outputDirectory.'/'.$version.'/*/*/*.jsonl') ?: [];
        $this->assertNotEmpty($shards);

        $written = file_put_contents($shards[0], "{}\n", FILE_APPEND);
        $this->assertNotFalse($written);

        $this->artisan('demo:validate-case-study-emails', [
            '--dataset-version' => $version,
            '--dataset-root' => $this->outputDirectory,
        ])
            ->expectsOutputToContain('Checksum mismatch')
            ->assertExitCode(1);
    }

    private function generateSubset(): string
    {
        $this->artisan('demo:generate-case-study-emails', [
            '--profile' => 'gold',
            '--company' => ['rotta-logistics'],
            '--mailbox' => ['rotta-logistics-1'],
            '--output' => $this->outputDirectory,
        ])->assertExitCode(0);

        $directories = glob($this->outputDirectory.'/*', GLOB_ONLYDIR) ?: [];
        $this->assertCount(1, $directories);

        return basename($directories[0]);
    }
}
