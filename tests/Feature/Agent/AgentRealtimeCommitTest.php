<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentEventPublisher;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AgentRealtimeCommitTest extends TestCase
{

    public function test_notification_runs_after_the_real_commit_and_an_outage_cannot_lose_the_event(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'public-key']);
        $user = User::create(['name' => 'Owner', 'email' => 'commit@example.test', 'password' => 'password']);
        $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'autry', 'channel' => 'chat',
            'actor_type' => 'user', 'actor_id' => (string) $user->id, 'user_id' => $user->id, 'locale' => 'it',
            'timezone' => 'UTC', 'status' => 'running', 'input_json' => ['question' => 'private question']]);
        $driver = \Mockery::mock(Broadcaster::class);
        Broadcast::shouldReceive('connection')->once()->with('reverb')->andReturn($driver);
        $driver->shouldReceive('broadcast')->once()->withArgs(function ($channels, $event, $payload) use ($run, $user): bool {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(1, $run->events()->count());
            $this->assertSame(['private-agent.activity.autry.'.$user->id], $channels);
            $this->assertSame('agent.run.updated', $event);
            $this->assertSame(['run_id' => $run->run_id, 'sequence' => 1, 'conversation_id' => null], $payload);

            return true;
        })->andThrow(new \RuntimeException('Reverb unavailable'));

        DB::transaction(function () use ($run): void {
            app(AgentEventPublisher::class)->publish($run, 'run.started');
            $this->assertSame(1, DB::transactionLevel());
        });
        $this->assertSame(1, $run->fresh()->last_sequence);
        $this->assertSame('run.started', $run->events()->sole()->type);
    }
}
