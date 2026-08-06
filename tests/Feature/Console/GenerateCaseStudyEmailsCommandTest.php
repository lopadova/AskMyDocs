<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class GenerateCaseStudyEmailsCommandTest extends TestCase
{
    private ?string $outputDirectory = null;

    protected function tearDown(): void
    {
        if ($this->outputDirectory !== null && is_dir($this->outputDirectory)) {
            $this->assertTrue((new Filesystem)->deleteDirectory($this->outputDirectory));
        }

        parent::tearDown();
    }

    public function test_command_generates_a_filtered_gold_dataset_without_network_access(): void
    {
        $this->outputDirectory = sys_get_temp_dir().'/askmydocs-email-command-test-'.bin2hex(random_bytes(8));

        $this->artisan('demo:generate-case-study-emails', [
            '--profile' => 'gold',
            '--seed' => '17',
            '--mailbox' => ['rotta-logistics-2'],
            '--output' => $this->outputDirectory,
            '--stats' => true,
        ])
            ->expectsOutputToContain('Dataset generato')
            ->assertExitCode(0);

        $manifests = glob($this->outputDirectory.'/*/manifest.json');
        $this->assertIsArray($manifests);
        $this->assertCount(1, $manifests);
        $manifest = json_decode((string) file_get_contents($manifests[0]), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(126, $manifest['total_records']);
        $this->assertSame(['rotta-logistics-2' => 126], $manifest['statistics']['records_by_mailbox']);
    }

    public function test_command_rejects_non_numeric_seed_without_publishing(): void
    {
        $this->outputDirectory = sys_get_temp_dir().'/askmydocs-email-command-invalid-'.bin2hex(random_bytes(8));

        $this->artisan('demo:generate-case-study-emails', [
            '--seed' => 'not-a-number',
            '--output' => $this->outputDirectory,
        ])->assertExitCode(2);

        $this->assertDirectoryDoesNotExist($this->outputDirectory);
    }
}
