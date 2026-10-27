<?php

declare(strict_types=1);

namespace Tests\Unit\ApiConnectors;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class IdentityFieldsComposerPatchTest extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        $this->packagePath = sys_get_temp_dir().'/api-identity-patch-'.bin2hex(random_bytes(8));
        $fixtures = [
            'Models/ApiRoute.php' => <<<'PHP'
        'items_path',
        'items_path' => 'string',
PHP,
            'Http/Resources/ApiRouteResource.php' => <<<'PHP'
            'items_path' => $this->items_path,
PHP,
            'Support/RouteConfig.php' => <<<'PHP'
                'items_path' => $route->items_path,
            'items_path' => $response['items_path'] ?? null,
PHP,
            'Support/RouteConfigSchema.php' => <<<'PHP'
                        'items_path' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'items_path' => self::str($response['items_path'] ?? null),
PHP,
            'Http/Requests/Concerns/ValidatesRouteConfig.php' => <<<'PHP'
            'config.response.items_path' => ['nullable', 'string', 'max:255'],
PHP,
            'Http/Requests/StoreRouteRequest.php' => <<<'PHP'
            'items_path' => ['nullable', 'string', 'max:255'],
PHP,
            'Http/Requests/UpdateRouteRequest.php' => <<<'PHP'
            'items_path' => ['sometimes', 'nullable', 'string', 'max:255'],
PHP,
            'Services/ConnectorAdminService.php' => <<<'PHP'
        $this->applyEndpointType($route, $data);
        $route->items_path = is_string($flat['items_path'] ?? null) ? $flat['items_path'] : null;
        $base['response'] = $response;
                'items_path' => $suggestion['items_path'] ?? null,
PHP,
        ];
        foreach ($fixtures as $relative => $source) {
            $path = $this->packagePath.'/src/'.$relative;
            (new Filesystem)->ensureDirectoryExists(dirname($path));
            file_put_contents($path, $source);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->packagePath);
    }

    public function test_composer_applies_the_contract_patch_idempotently_on_install_and_update(): void
    {
        $this->process()->mustRun();
        $files = (new Filesystem)->allFiles($this->packagePath);
        $contents = [];
        foreach ($files as $file) {
            $contents[$file->getPathname()] = $file->getContents();
            $this->assertStringContainsString('identity_fields', $file->getContents());
        }
        $this->process()->mustRun();
        foreach ($contents as $path => $source) {
            $this->assertSame($source, file_get_contents($path));
        }
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['post-install-cmd', 'post-update-cmd'] as $event) {
            $this->assertContains('@php scripts/composer/patch-api-connector-identity-fields.php', $manifest['scripts'][$event]);
        }
    }

    public function test_package_drift_fails_before_any_partial_writes(): void
    {
        $path = $this->packagePath.'/src/Models/ApiRoute.php';
        $before = file_get_contents($path);
        file_put_contents($this->packagePath.'/src/Services/ConnectorAdminService.php', 'changed upstream');
        $process = $this->process();
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('review the identity-fields patch', $process->getErrorOutput());
        $this->assertSame($before, file_get_contents($path));
    }

    private function process(): Process
    {
        return new Process([PHP_BINARY, dirname(__DIR__, 3).'/scripts/composer/patch-api-connector-identity-fields.php', $this->packagePath]);
    }
}
