<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentEventPublisher;
use App\Agent\AgentExecutionContextFactory;
use App\Agent\AgentRunDispatcher;
use App\Contracts\AgentRunHandler;
use App\Http\Controllers\Api\AgentRunEventController;
use App\Jobs\ExecuteAgentRunJob;
use App\Models\AgentRun;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AgentRunTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        Route::middleware('api')->get('/test-agent-runs/{run}/events', AgentRunEventController::class);
    }

    public function test_dispatcher_persists_the_context_and_queues_the_worker(): void
    {
        Queue::fake();
        $user = $this->user('dispatch@example.com');
        $context = app(AgentExecutionContextFactory::class)->forUser($user, 'orders');

        $run = app(AgentRunDispatcher::class)->dispatch(
            $context,
            ['question' => 'Dammi gli ordini'],
            ['user_id' => $user->id],
        );

        $this->assertSame('it-IT', $run->locale);
        $this->assertSame(AgentRun::STATUS_QUEUED, $run->status);
        $this->assertSame('Dammi gli ordini', $run->input_json['question']);
        Queue::assertPushed(ExecuteAgentRunJob::class, fn ($job): bool => $job->agentRunId === $run->id
            && $job->tenantId === $run->tenant_id);
    }

    public function test_worker_scopes_the_run_and_restores_long_lived_process_context(): void
    {
        $user = $this->user('worker-context@example.com');
        $run = $this->completedRun($user);
        $tenants = app(TenantContext::class);
        $packageTenants = app(PackageTenantContext::class);
        $tenants->reset();
        $packageTenants->reset();
        $resetTenant = $tenants->current();
        $resetPackageTenant = $packageTenants->current();
        App::setLocale('en');
        date_default_timezone_set('UTC');

        $handler = \Mockery::mock(AgentRunHandler::class);
        $handler->shouldReceive('handle')
            ->once()
            ->withArgs(function (AgentRun $handled) use ($run, $tenants, $packageTenants): bool {
                $this->assertSame($run->id, $handled->id);
                $this->assertSame('test-tenant', $tenants->current());
                $this->assertSame('test-tenant', $packageTenants->current());
                $this->assertSame('it', App::currentLocale());
                $this->assertSame('Europe/Rome', date_default_timezone_get());

                return true;
            });

        (new ExecuteAgentRunJob($run->id, 'test-tenant'))->handle($handler, $tenants, $packageTenants);

        $this->assertSame($resetTenant, $tenants->current());
        $this->assertSame($resetPackageTenant, $packageTenants->current());
        $this->assertSame('en', App::currentLocale());
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_worker_cannot_load_a_run_through_another_tenant(): void
    {
        $user = $this->user('worker-idor@example.com');
        $run = $this->completedRun($user);
        $handler = \Mockery::mock(AgentRunHandler::class);
        $handler->shouldNotReceive('handle');

        $this->expectException(ModelNotFoundException::class);

        (new ExecuteAgentRunJob($run->id, 'other-tenant'))
            ->handle(
                $handler,
                app(TenantContext::class),
                app(PackageTenantContext::class),
            );
    }

    public function test_sse_replays_only_events_after_the_cursor_and_is_no_store(): void
    {
        config()->set('agent.events.stream_seconds', 0);
        $user = $this->user('stream@example.com');
        $run = $this->completedRun($user);
        $publisher = app(AgentEventPublisher::class);
        $publisher->publish($run, 'run.started', 'run.started');
        $publisher->publish($run, 'run.completed', 'run.completed', canCancel: false);

        $response = $this->actingAs($user)->get('/test-agent-runs/'.$run->run_id.'/events?after=1', [
            'Accept' => 'text/event-stream',
        ]);

        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $content = $response->streamedContent();
        $this->assertStringContainsString("id: 2\nevent: run.completed", $content);
        $this->assertStringNotContainsString("id: 1\n", $content);
        $this->assertStringContainsString('"locale":"it-IT"', $content);
    }

    public function test_another_user_receives_the_same_not_found_boundary(): void
    {
        $owner = $this->user('owner-run@example.com');
        $intruder = $this->user('intruder-run@example.com');
        $run = $this->completedRun($owner);

        $this->actingAs($intruder)
            ->get('/test-agent-runs/'.$run->run_id.'/events')
            ->assertNotFound();
    }

    private function completedRun(User $user): AgentRun
    {
        return AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'test-tenant',
            'project_key' => 'orders',
            'user_id' => $user->id,
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_COMPLETED,
        ]);
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => 'Agent user',
            'email' => $email,
            'password' => Hash::make('secret-pass-123'),
            'locale' => 'it-IT',
        ]);
    }
}
