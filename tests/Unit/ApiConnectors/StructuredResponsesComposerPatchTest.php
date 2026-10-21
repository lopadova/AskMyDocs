<?php

declare(strict_types=1);

namespace Tests\Unit\ApiConnectors;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class StructuredResponsesComposerPatchTest extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        $this->packagePath = sys_get_temp_dir().'/api-structured-patch-'.bin2hex(random_bytes(8));
        mkdir($this->packagePath.'/src/Services', 0755, true);
        file_put_contents($this->packagePath.'/src/Services/ApiToolExecutor.php', <<<'PHP'
            $decoded = json_decode($response->body(), true);
            $isJson = json_last_error() === JSON_ERROR_NONE && is_array($decoded);
PHP);
        file_put_contents($this->packagePath.'/src/Services/ApiRouteTester.php', <<<'PHP'
        return $this->execute($plan, $material, $timeoutMs);
    private function execute(RequestPlan $plan, AuthMaterial $material, int $timeoutMs): TestResult
            $decoded = json_decode($raw, true);
            $isJson = json_last_error() === JSON_ERROR_NONE && is_array($decoded);
PHP);
    }

    protected function tearDown(): void
    {
        foreach (['ApiToolExecutor', 'ApiRouteTester'] as $name) unlink($this->packagePath.'/src/Services/'.$name.'.php');
        rmdir($this->packagePath.'/src/Services');rmdir($this->packagePath.'/src');rmdir($this->packagePath);
    }

    public function test_patch_is_idempotent_and_registered_for_install_and_update(): void
    {
        $this->process()->mustRun();
        $first = file_get_contents($this->packagePath.'/src/Services/ApiRouteTester.php');
        $this->assertStringContainsString('StructuredApiResponseDecoder::class', $first);
        $this->assertStringContainsString('$timeoutMs, $route->output_transform', $first);
        $this->process()->mustRun();
        $this->assertSame($first, file_get_contents($this->packagePath.'/src/Services/ApiRouteTester.php'));
        $manifest = json_decode(file_get_contents(dirname(__DIR__,3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['post-install-cmd', 'post-update-cmd'] as $event) $this->assertContains('@php scripts/composer/patch-api-connector-structured-responses.php', $manifest['scripts'][$event]);
    }

    public function test_drift_prevents_partial_writes(): void
    {
        $before = file_get_contents($this->packagePath.'/src/Services/ApiToolExecutor.php');
        file_put_contents($this->packagePath.'/src/Services/ApiRouteTester.php', 'upstream changed');
        $process = $this->process();$process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame($before, file_get_contents($this->packagePath.'/src/Services/ApiToolExecutor.php'));
    }

    private function process(): Process
    {
        return new Process([PHP_BINARY, dirname(__DIR__,3).'/scripts/composer/patch-api-connector-structured-responses.php', $this->packagePath]);
    }
}
