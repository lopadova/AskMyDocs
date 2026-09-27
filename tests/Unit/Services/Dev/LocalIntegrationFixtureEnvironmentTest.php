<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dev;

use App\Services\Dev\LocalIntegrationFixtureEnvironment;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Tests\TestCase;

final class LocalIntegrationFixtureEnvironmentTest extends TestCase
{
    /** @var list<string> */
    private array $touchedEnvironment = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->touchedEnvironment as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_enable_replaces_only_the_local_fixture_flag(): void
    {
        $this->setEnvironment('APP_ENV', 'local');
        $this->setEnvironment(LocalIntegrationFixtureEnvironment::FLAG, 'false');
        $path = tempnam(sys_get_temp_dir(), 'askmydocs-local-fixture-');
        $this->assertIsString($path);
        $this->temporaryFiles[] = $path;
        file_put_contents($path, "APP_NAME=AskMyDocs\n".LocalIntegrationFixtureEnvironment::FLAG."=false\n");

        app(LocalIntegrationFixtureEnvironment::class)->enable($path);

        $this->assertSame(
            "APP_NAME=AskMyDocs\n".LocalIntegrationFixtureEnvironment::FLAG."=true\n",
            file_get_contents($path),
        );
        $this->assertTrue(LocalIntegrationFixtureEnvironment::enabled());
    }

    public function test_enable_refuses_non_local_environment_before_touching_the_file(): void
    {
        $this->setEnvironment('APP_ENV', 'production');
        $path = tempnam(sys_get_temp_dir(), 'askmydocs-local-fixture-');
        $this->assertIsString($path);
        $this->temporaryFiles[] = $path;
        file_put_contents($path, 'UNCHANGED=true'.PHP_EOL);

        try {
            app(LocalIntegrationFixtureEnvironment::class)->enable($path);
            $this->fail('Expected the local environment guard to reject the write.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('APP_ENV=local', $exception->getMessage());
        }

        $this->assertSame('UNCHANGED=true'.PHP_EOL, file_get_contents($path));
    }

    private function setEnvironment(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        $this->touchedEnvironment[] = $key;
    }
}
