<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentRunControl;
use App\Http\Controllers\Api\AgentRunControlController;
use App\Jobs\ExecuteAgentRunJob;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AgentRunControlTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        Route::middleware('api')->post('/test-agent-runs/{run}/cancel', [AgentRunControlController::class, 'cancel']);
        Route::middleware('api')->post('/test-agent-runs/{run}/continue', [AgentRunControlController::class, 'resume']);
    }

    public function test_owner_can_cancel_an_active_run_and_receives_a_localized_event(): void
    {
        $user = $this->user('cancel@example.com');
        $run = $this->makeRun($user, AgentRun::STATUS_RUNNING);

        $this->actingAs($user)
            ->postJson('/test-agent-runs/'.$run->run_id.'/cancel')
            ->assertOk()
            ->assertJsonPath('status', AgentRun::STATUS_CANCELLED)
            ->assertJsonPath('locale', 'it-IT');

        $this->assertNotNull($run->fresh()->cancelled_at);
        $this->assertSame('La ricerca è stata annullata.', $run->events()->sole()->message);
    }

    public function test_terminal_run_cannot_be_cancelled_again(): void
    {
        $user = $this->user('terminal@example.com');
        $run = $this->makeRun($user, AgentRun::STATUS_COMPLETED);

        $this->actingAs($user)
            ->postJson('/test-agent-runs/'.$run->run_id.'/cancel')
            ->assertConflict()
            ->assertJsonPath('error', 'run_not_cancellable');
    }

    public function test_confirmed_bounded_extension_requeues_the_same_run(): void
    {
        Queue::fake();
        $user = $this->user('continue@example.com');
        $run = $this->makeRun($user, AgentRun::STATUS_RUNNING);
        app(AgentRunControl::class)->awaitConfirmation($run, ['physical' => 50]);

        $this->actingAs($user)
            ->postJson('/test-agent-runs/'.$run->run_id.'/continue', [
                'logical_extension' => 5,
                'physical_extension' => 50,
            ])
            ->assertAccepted()
            ->assertJsonPath('status', AgentRun::STATUS_QUEUED)
            ->assertJsonPath('budget.confirmed_logical_extension', 5)
            ->assertJsonPath('budget.confirmed_physical_extension', 50);

        Queue::assertPushed(ExecuteAgentRunJob::class, fn ($job): bool => $job->agentRunId === $run->id
            && $job->tenantId === $run->tenant_id);
        $this->assertSame('budget.extended', $run->events()->latest('sequence')->firstOrFail()->type);
    }

    public function test_extension_is_rejected_outside_the_bounded_policy(): void
    {
        $user = $this->user('bounds@example.com');
        $run = $this->makeRun($user, AgentRun::STATUS_AWAITING_CONFIRMATION);

        $this->actingAs($user)
            ->postJson('/test-agent-runs/'.$run->run_id.'/continue', [
                'physical_extension' => 101,
            ])
            ->assertConflict()
            ->assertJsonPath('error', 'extension_out_of_bounds');
    }

    public function test_mcp_interaction_is_persisted_as_a_first_class_pause(): void
    {
        $user = $this->user('mcp-pause@example.com');
        $run = $this->makeRun($user, AgentRun::STATUS_RUNNING);

        app(AgentRunControl::class)->awaitMcpInteraction($run, 'mcp_input_required', [
            'pending_interaction_id' => 'interaction-123',
            'prompt' => 'Choose a warehouse',
        ]);

        $fresh = $run->fresh();
        $this->assertSame(AgentRun::STATUS_AWAITING_MCP_INPUT, $fresh->status);
        $this->assertSame('interaction-123', data_get($fresh->result_json, 'pending_mcp_interaction.pending_interaction_id'));
        $event = $run->events()->sole();
        $this->assertSame('run.mcp_interaction_required', $event->type);
        $this->assertSame('mcp_input_required', data_get($event->payload_json, 'data.reason'));
    }

    public function test_run_access_hides_another_users_run(): void
    {
        $owner = $this->user('control-owner@example.com');
        $intruder = $this->user('control-intruder@example.com');
        $run = $this->makeRun($owner, AgentRun::STATUS_RUNNING);

        $this->actingAs($intruder)
            ->postJson('/test-agent-runs/'.$run->run_id.'/cancel')
            ->assertNotFound();
    }

    private function makeRun(User $user, string $status): AgentRun
    {
        return AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'test-tenant',
            'user_id' => $user->id,
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => $status,
            'budget_json' => [],
        ]);
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => 'Control user',
            'email' => $email,
            'password' => Hash::make('secret-pass-123'),
            'locale' => 'it-IT',
        ]);
    }
}
