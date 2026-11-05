<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Developer;

use App\Http\Controllers\Api\Developer\LocalIntegrationFixturesController;
use App\Services\Dev\LocalIntegrationFixtureLifecycle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class LocalIntegrationFixturesControllerTest extends TestCase
{
    /** @var array<string, array{process:string|false,env:mixed,server:mixed}> */
    private array $previousEnvironment = [];

    protected function defineRoutes($router): void
    {
        $router->post('/local-integrations/{action}', [LocalIntegrationFixturesController::class, 'control']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'local');
        $this->setEnvironment('APP_ENV', 'local');
        $this->setEnvironment('LOCAL_INTEGRATIONS_API_BASE_URL', 'http://127.0.0.1:14310');
        $this->setEnvironment('LOCAL_INTEGRATIONS_MCP_BASE_URL', 'http://127.0.0.1:14311/mcp');
        config(['app.debug' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $key => $values) {
            $values['process'] === false ? putenv($key) : putenv($key.'='.$values['process']);
            if ($values['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $values['env'];
            }
            if ($values['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $values['server'];
            }
        }
        $this->app->instance('env', 'testing');
        parent::tearDown();
    }

    public function test_launcher_failure_returns_its_cause_as_json_without_debug_mode(): void
    {
        $lifecycle = Mockery::mock(LocalIntegrationFixtureLifecycle::class);
        $lifecycle->shouldReceive('start')->once()->andThrow(new RuntimeException('La porta del mock API è già occupata.'));
        $this->app->instance(LocalIntegrationFixtureLifecycle::class, $lifecycle);

        $this->postJson('/local-integrations/start')
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'La porta del mock API è già occupata.',
                'code' => 'local_integration_control_failed',
            ]);
    }

    public function test_stop_returns_current_status_and_the_configured_display_addresses(): void
    {
        $lifecycle = Mockery::mock(LocalIntegrationFixtureLifecycle::class);
        $lifecycle->shouldReceive('stop')->once();
        $this->app->instance(LocalIntegrationFixtureLifecycle::class, $lifecycle);
        Http::fake(fn () => throw new ConnectionException('Stopped'));

        $this->postJson('/local-integrations/stop')
            ->assertOk()
            ->assertJsonPath('message', 'Server mock API e MCP arrestati.')
            ->assertJsonPath('status.api.state', 'stopped')
            ->assertJsonPath('status.mcp.state', 'stopped')
            ->assertJsonPath('status.api.address', '127.0.0.1:14310')
            ->assertJsonPath('status.mcp.address', '127.0.0.1:14311');
    }

    public function test_non_local_environment_cannot_control_any_process(): void
    {
        $this->app->instance('env', 'production');
        $lifecycle = Mockery::mock(LocalIntegrationFixtureLifecycle::class);
        $lifecycle->shouldNotReceive('start');
        $this->app->instance(LocalIntegrationFixtureLifecycle::class, $lifecycle);

        $this->postJson('/local-integrations/start')->assertNotFound();
    }

    public function test_unknown_action_remains_a_not_found_response(): void
    {
        $lifecycle = Mockery::mock(LocalIntegrationFixtureLifecycle::class);
        $lifecycle->shouldNotReceive('start', 'stop', 'restart');
        $this->app->instance(LocalIntegrationFixtureLifecycle::class, $lifecycle);

        $this->postJson('/local-integrations/unknown')->assertNotFound();
    }

    private function setEnvironment(string $key, string $value): void
    {
        $this->previousEnvironment[$key] ??= [
            'process' => getenv($key),
            'env' => $_ENV[$key] ?? null,
            'server' => $_SERVER[$key] ?? null,
        ];
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}
