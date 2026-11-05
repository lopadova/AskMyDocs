<?php

declare(strict_types=1);

namespace Tests\Unit\ApiConnectors;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SharedHeadersComposerPatchTest extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        $this->packagePath = sys_get_temp_dir().'/api-headers-patch-'.bin2hex(random_bytes(8));
        mkdir($this->packagePath.'/src/Support', 0755, true);
        file_put_contents($this->packagePath.'/src/Support/RequestPlanner.php', <<<'PHP'
        $headers = [];
        ParamLocation::Header => $headers[$name] = (string) $coerced,
PHP);
        file_put_contents($this->packagePath.'/src/Support/HttpDispatcher.php', '        $headers = array_merge($plan->headers, $material->headers);');
    }

    protected function tearDown(): void
    {
        foreach (['RequestPlanner', 'HttpDispatcher'] as $name) {
            unlink($this->packagePath.'/src/Support/'.$name.'.php');
        }
        rmdir($this->packagePath.'/src/Support');
        rmdir($this->packagePath.'/src');
        rmdir($this->packagePath);
    }

    public function test_installs_and_updates_register_the_shared_header_patch(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach (['post-install-cmd', 'post-update-cmd'] as $event) {
            $this->assertContains(
                '@php scripts/composer/patch-api-connector-headers.php',
                $manifest['scripts'][$event] ?? [],
                $event.' must apply the API connector shared-header patch.',
            );
        }
    }

    public function test_composer_install_and_update_can_apply_the_patch_repeatedly(): void
    {
        $this->runPatch()->mustRun();
        $planner = file_get_contents($this->packagePath.'/src/Support/RequestPlanner.php');
        $dispatcher = file_get_contents($this->packagePath.'/src/Support/HttpDispatcher.php');
        $this->assertStringContainsString('$route->connector?->sharedHeaders()', $planner);
        $this->assertStringContainsString('$headers[strtolower($name)]', $planner);
        $this->assertStringContainsString('array_change_key_case($material->headers, CASE_LOWER)', $dispatcher);
        $this->runPatch()->mustRun();
        $this->assertSame($planner, file_get_contents($this->packagePath.'/src/Support/RequestPlanner.php'));
        $this->assertSame($dispatcher, file_get_contents($this->packagePath.'/src/Support/HttpDispatcher.php'));
    }

    public function test_dependency_drift_fails_without_partially_patching_the_package(): void
    {
        $planner = file_get_contents($this->packagePath.'/src/Support/RequestPlanner.php');
        file_put_contents($this->packagePath.'/src/Support/HttpDispatcher.php', 'changed upstream');
        $process = $this->runPatch();
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('review the shared-header patch', $process->getErrorOutput());
        $this->assertSame($planner, file_get_contents($this->packagePath.'/src/Support/RequestPlanner.php'));
    }

    private function runPatch(): Process
    {
        return new Process([PHP_BINARY, dirname(__DIR__, 3).'/scripts/composer/patch-api-connector-headers.php', $this->packagePath]);
    }
}
