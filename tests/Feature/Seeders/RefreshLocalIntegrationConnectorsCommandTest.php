<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Console\Commands\RefreshLocalIntegrationConnectorsCommand;
use App\Services\Dev\LocalIntegrationFixtureEnvironment;
use App\Services\Dev\LocalIntegrationFixtureLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Tests\TestCase;

final class RefreshLocalIntegrationConnectorsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
            ->registerCommand($this->app->make(RefreshLocalIntegrationConnectorsCommand::class));
    }

    protected function tearDown(): void
    {
        foreach (['APP_ENV', LocalIntegrationFixtureEnvironment::FLAG] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        parent::tearDown();
    }

    public function test_it_rejects_a_missing_tenant_before_restarting_or_touching_connectors(): void
    {
        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';
        putenv(LocalIntegrationFixtureEnvironment::FLAG.'=true');
        $_ENV[LocalIntegrationFixtureEnvironment::FLAG] = $_SERVER[LocalIntegrationFixtureEnvironment::FLAG] = 'true';
        $lifecycle = Mockery::mock(LocalIntegrationFixtureLifecycle::class);
        $lifecycle->shouldNotReceive('restart');
        $this->app->instance(LocalIntegrationFixtureLifecycle::class, $lifecycle);

        $this->artisan('dev:refresh-local-integration-connectors', ['--tenant' => 'rotta-logistics'])
            ->assertExitCode(1);
        $this->assertSame(0, ApiConnector::withoutGlobalScopes()->count());
        $this->assertSame(0, McpConnection::withoutGlobalScopes()->count());
    }

    public function test_it_rejects_tenants_outside_the_fixed_allowlist(): void
    {
        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';
        putenv(LocalIntegrationFixtureEnvironment::FLAG.'=true');
        $_ENV[LocalIntegrationFixtureEnvironment::FLAG] = $_SERVER[LocalIntegrationFixtureEnvironment::FLAG] = 'true';
        $lifecycle = Mockery::mock(LocalIntegrationFixtureLifecycle::class);
        $lifecycle->shouldNotReceive('restart');
        $this->app->instance(LocalIntegrationFixtureLifecycle::class, $lifecycle);

        $this->artisan('dev:refresh-local-integration-connectors', ['--tenant' => 'another-tenant'])
            ->assertExitCode(1);
    }
}
