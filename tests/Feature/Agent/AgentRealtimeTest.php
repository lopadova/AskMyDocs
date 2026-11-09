<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentEventLog;
use App\Agent\AgentEventPublisher;
use App\Agent\AgentRealtime;
use App\Http\Controllers\Api\AgentRealtimeController;
use App\Models\AgentRun;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AgentRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        Route::middleware(['api', 'auth', 'tenant.authorize'])->group(function (): void {
            Route::get('/test-transport', [AgentRealtimeController::class, 'config']);
            Route::post('/test-transport/auth', [AgentRealtimeController::class, 'auth']);
        });
    }

    public function test_private_channel_authorization_never_signs_a_different_user_or_tenant(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'public-key',
            'broadcasting.connections.reverb.secret' => 'server-secret', 'broadcasting.connections.reverb.app_id' => 'test']);
        $user = User::create(['name' => 'Owner', 'email' => 'realtime@example.test', 'password' => 'password']);
        (new \App\Providers\AgentRealtimeServiceProvider($this->app))->boot();
        $this->actingAs($user);
        $tenant = app(TenantContext::class)->current();
        $this->getJson('/test-transport')->assertOk()->assertJsonPath('channel', AgentRealtime::channel($tenant, $user->id))
            ->assertDontSee('server-secret');
        $this->postJson('/test-transport/auth', ['socket_id' => '1.2', 'channel_name' => 'private-'.AgentRealtime::channel($tenant, $user->id + 1)])->assertForbidden();
        $this->postJson('/test-transport/auth', ['socket_id' => '1.2', 'channel_name' => 'private-'.AgentRealtime::channel('foreign', $user->id)])->assertForbidden();
        $this->postJson('/test-transport/auth', ['socket_id' => '1.2', 'channel_name' => 'private-'.AgentRealtime::channel($tenant, $user->id)])->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_rollback_does_not_broadcast_uncommitted_events(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'public-key']);
        $run = $this->makeRun();
        $driver = \Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
        Broadcast::shouldReceive('connection')->with('reverb')->andReturn($driver);
        $driver->shouldReceive('broadcast')->never();
        DB::beginTransaction();
        app(AgentEventPublisher::class)->publish($run, 'run.started');
        DB::rollBack();
        $this->assertSame(0, $run->events()->count());
    }

    public function test_replay_is_bounded_and_a_crashed_terminal_publisher_closes_the_client(): void
    {
        $run = $this->makeRun();
        for ($i = 1; $i <= 102; $i++) {
            $run->events()->create(['sequence' => $i, 'type' => 'tool.progress', 'phase' => 'tool', 'locale' => 'en', 'payload_json' => []]);
        }
        $run->forceFill(['last_sequence' => 102, 'status' => 'failed'])->save();
        $first = app(AgentEventLog::class)->read($run, 0);
        $this->assertCount(100, $first['events']);
        $this->assertTrue($first['has_more']);
        $second = app(AgentEventLog::class)->read($run, 100);
        $this->assertCount(3, $second['events']);
        $this->assertFalse($second['has_more']);
        $this->assertSame('run.failed', $second['events'][2]['type']);
    }

    private function makeRun(): AgentRun
    {
        $user = User::create(['name' => 'Run owner', 'email' => Str::uuid().'@example.test', 'password' => 'password']);
        return AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'autry', 'channel' => 'chat',
            'actor_type' => 'user', 'actor_id' => (string) $user->id, 'user_id' => $user->id, 'locale' => 'en', 'timezone' => 'UTC',
            'status' => 'queued', 'input_json' => []]);
    }
}
