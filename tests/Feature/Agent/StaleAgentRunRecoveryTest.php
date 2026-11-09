<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\StaleAgentRunRecovery;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaleAgentRunRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_requires_exact_tenant_and_inactivity_and_never_restarts_a_run(): void
    {
        $run = $this->makeRun();
        $run->forceFill(['updated_at' => now()->subDays(2)])->save();
        $service = app(StaleAgentRunRecovery::class);
        $this->assertSame('skipped', $service->recover('foreign', $run->id, now()->subHour(), true));
        $this->assertSame('candidate', $service->recover($run->tenant_id, $run->id, now()->subHour(), false));
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSame('cancelled', $service->recover($run->tenant_id, $run->id, now()->subHour(), true));
        $this->assertSame('skipped', $service->recover($run->tenant_id, $run->id, now()->subHour(), true));
        $this->assertSame('run.cancelled', $run->events()->latest('sequence')->value('type'));
    }

    public function test_recent_events_protect_an_old_run_from_reconciliation(): void
    {
        $run = $this->makeRun();
        $run->forceFill(['updated_at' => now()->subDays(2)])->save();
        $run->events()->create(['sequence' => 1, 'type' => 'tool.progress', 'phase' => 'tool', 'locale' => 'en', 'payload_json' => []]);
        $this->assertSame('recent_activity', app(StaleAgentRunRecovery::class)->recover($run->tenant_id, $run->id, now()->subHour(), true));
        $this->assertSame('queued', $run->fresh()->status);
    }

    private function makeRun(): AgentRun
    {
        $user = User::create(['name' => 'Run owner', 'email' => Str::uuid().'@example.test', 'password' => 'password']);
        return AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'autry', 'channel' => 'chat',
            'actor_type' => 'user', 'actor_id' => (string) $user->id, 'user_id' => $user->id, 'locale' => 'en', 'timezone' => 'UTC',
            'status' => 'queued', 'input_json' => []]);
    }
}
